/* ==========================================================================
   پنل آپلود فایل — منطق سمت مرورگر
   آپلود تکه‌تکه (chunked) با پشتیبانی از توقف/ادامه، نمایش سرعت و لینک‌سازی
   ========================================================================== */
(function () {
    'use strict';

    var CFG = window.UPLOAD_PANEL || {};
    var URLS = CFG.urls || {};
    var LIMITS = CFG.limits || {};
    var MAX_FILE = LIMITS.maxFileSize || 2 * 1024 * 1024 * 1024;
    var CHUNK_MIN = 256 * 1024;
    var MAX_PARALLEL = 2;
    var KEY_STORE = 'up:keys';
    var PENDING_STORE = 'up:pending';

    document.documentElement.classList.add('has-js');

    /* ------------------------------------------------------------------
     | ابزارها
     * ----------------------------------------------------------------*/
    var FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    function faNum(v) {
        return String(v).replace(/\d/g, function (d) { return FA_DIGITS[+d]; });
    }
    function humanSize(bytes) {
        bytes = Number(bytes) || 0;
        var units = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت', 'ترابایت'];
        var i = 0;
        while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
        var val = i === 0 ? Math.round(bytes).toString()
            : (bytes < 10 ? bytes.toFixed(2) : bytes.toFixed(1));
        return faNum(val) + ' ' + units[i];
    }
    function humanTime(sec) {
        if (!isFinite(sec) || sec < 0) return '—';
        sec = Math.round(sec);
        if (sec < 60) return faNum(sec) + ' ثانیه';
        var m = Math.floor(sec / 60), s = sec % 60;
        if (m < 60) return faNum(m) + ' دقیقه' + (s ? ' و ' + faNum(s) + ' ثانیه' : '');
        var h = Math.floor(m / 60); m = m % 60;
        return faNum(h) + ' ساعت' + (m ? ' و ' + faNum(m) + ' دقیقه' : '');
    }
    function clampChunk(size) {
        var max = LIMITS.chunkSize || 8 * 1024 * 1024;
        return Math.max(CHUNK_MIN, Math.min(Number(size) || max, max));
    }
    function el(tag, cls, html) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (html !== undefined) e.innerHTML = html;
        return e;
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    /**
     * خواندن امن JSON از پاسخ سرور.
     * بعضی هاست‌ها قبل از JSON یک اخطار PHP چاپ می‌کنند (مثلا وقتی حجم درخواست از
     * post_max_size بیشتر است)؛ در آن حالت هم باید بتوانیم پیام اصلی را بخوانیم.
     */
    function parseJSON(text) {
        if (typeof text !== 'string' || text === '') return null;
        try { return JSON.parse(text); } catch (e) { /* شاید متن اضافی قبلش باشد */ }
        var s = text.indexOf('{'), t = text.lastIndexOf('}');
        if (s > -1 && t > s) {
            try { return JSON.parse(text.slice(s, t + 1)); } catch (e2) { /* بی‌فایده */ }
        }
        return null;
    }
    function store(key, val) {        try {
            if (val === undefined) {
                var raw = localStorage.getItem(key);
                return raw ? JSON.parse(raw) : {};
            }
            localStorage.setItem(key, JSON.stringify(val));
        } catch (e) { /* حالت خصوصی مرورگر */ }
        return {};
    }
    function kindOf(mime, ext) {
        mime = (mime || '').toLowerCase(); ext = (ext || '').toLowerCase();
        if (mime.indexOf('image/') === 0) return 'image';
        if (mime.indexOf('video/') === 0) return 'video';
        if (mime.indexOf('audio/') === 0) return 'audio';
        if (mime === 'application/pdf') return 'pdf';
        if (['zip', 'rar', '7z', 'tar', 'gz'].indexOf(ext) > -1) return 'archive';
        if (['doc', 'docx', 'txt', 'rtf', 'odt'].indexOf(ext) > -1) return 'doc';
        if (['xls', 'xlsx', 'csv', 'ods'].indexOf(ext) > -1) return 'sheet';
        if (['apk', 'exe', 'msi', 'dmg', 'iso'].indexOf(ext) > -1) return 'app';
        if (['js', 'css', 'html', 'json', 'php', 'py', 'ts'].indexOf(ext) > -1) return 'code';
        return 'file';
    }
    function fingerprintOf(file) {
        return [file.name, file.size, file.lastModified || 0, file.type || ''].join('|');
    }

    /* ------------------------------------------------------------------
     | توست
     * ----------------------------------------------------------------*/
    var toastBox = document.getElementById('toasts');
    function toast(message, type, ms) {
        type = type || 'info';
        ms = ms || 3600;
        var icons = {
            ok: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.6"><path d="M5 13l4.5 4.5L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            err: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.6"><path d="M12 7v7m0 3.2v.3" stroke-linecap="round"/><circle cx="12" cy="12" r="9"/></svg>',
            warn: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 9v4m0 3.3v.2M10.3 3.9L2.6 17.4A2 2 0 004.3 20.4h15.4a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            info: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 11v5m0-8.4v.2" stroke-linecap="round"/><circle cx="12" cy="12" r="9"/></svg>'
        };
        var t = el('div', 'toast ' + type,
            '<span class="t-icon">' + (icons[type] || icons.info) + '</span><span>' + esc(message) + '</span>');
        t.style.setProperty('--dur', (ms / 1000) + 's');
        toastBox.appendChild(t);
        setTimeout(function () {
            t.classList.add('out');
            setTimeout(function () { t.remove(); }, 380);
        }, ms);
    }

    /* ------------------------------------------------------------------
     | کانفتی جشن
     * ----------------------------------------------------------------*/
    function confetti(count) {
        var box = document.getElementById('confetti');
        var colors = ['#6c5ce7', '#00d2ff', '#ff5e9c', '#22c55e', '#f59e0b', '#f472b6'];
        var n = count || 26;
        for (var i = 0; i < n; i++) {
            var p = document.createElement('i');
            var startX = (Math.random() * 100).toFixed(2);
            p.style.left = startX + 'vw';
            p.style.top = '-20px';
            p.style.background = colors[i % colors.length];
            p.style.setProperty('--dx', ((Math.random() - 0.5) * 260).toFixed(0) + 'px');
            p.style.setProperty('--dy', (window.innerHeight * (0.6 + Math.random() * 0.5)).toFixed(0) + 'px');
            p.style.setProperty('--rot', (Math.random() * 900 - 450).toFixed(0) + 'deg');
            p.style.setProperty('--t', (1.4 + Math.random() * 1.1).toFixed(2) + 's');
            p.style.animationDelay = (Math.random() * 0.25).toFixed(2) + 's';
            box.appendChild(p);
            (function (node) { setTimeout(function () { node.remove(); }, 3200); })(p);
        }
    }

    /* ------------------------------------------------------------------
     | درخواست‌های شبکه
     * ----------------------------------------------------------------*/
    function postJSON(url, data) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data || {}),
            credentials: 'same-origin'
        }).then(function (r) {
            return r.text().then(function (text) {
                var j = parseJSON(text);
                if (!j) {
                    var bad = new Error('پاسخ نامعتبر از سرور (' + r.status + ')');
                    bad.status = r.status;
                    throw bad;
                }
                if (!r.ok || j.ok === false) {
                    var err = new Error(j.error || ('خطای سرور ' + r.status));
                    err.status = r.status;
                    err.payload = j;
                    throw err;
                }
                return j;
            });
        });
    }

    /** ارسال یک تکه با XHR تا پیشرفت واقعی آپلود را ببینیم */
    function sendChunk(uploadId, index, blob, onProgress, registerXhr) {
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            registerXhr(xhr);
            var url = URLS.chunk + '?u=' + encodeURIComponent(uploadId) + '&i=' + index;
            xhr.open('POST', url, true);
            xhr.setRequestHeader('Content-Type', 'application/octet-stream');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.upload.onprogress = function (e) {
                if (e.lengthComputable && onProgress) onProgress(e.loaded);
            };
            xhr.onload = function () {
                var j = parseJSON(xhr.responseText);
                if (xhr.status >= 200 && xhr.status < 300 && j && j.ok) {
                    return resolve(j);
                }
                var msg = (j && j.error) || ('خطای ' + xhr.status);
                var err = new Error(msg);
                err.status = xhr.status;
                err.payload = j || {};
                reject(err);
            };
            xhr.onerror = function () { reject(new Error('خطای شبکه در ارسال تکه')); };
            xhr.onabort = function () {
                var e = new Error('aborted'); e.aborted = true; reject(e);
            };
            xhr.ontimeout = function () { reject(new Error('مهلت ارسال تکه تمام شد')); };
            xhr.send(blob);
        });
    }

    /* ------------------------------------------------------------------
     | آیتم آپلود
     * ----------------------------------------------------------------*/
    var listBox = document.getElementById('uploadList');
    var items = [];
    var pumpScheduled = false;

    function UploadItem(file) {
        this.file = file;
        this.name = file.name;
        this.size = file.size;
        this.type = file.type || '';
        this.ext = (file.name.split('.').pop() || '').toLowerCase();
        if (this.ext.length > 8) this.ext = '';
        this.fingerprint = fingerprintOf(file);
        this.chunkSize = clampChunk(LIMITS.chunkSize || 8 * 1024 * 1024);
        this.chunks = 0;
        this.nextChunk = 0;
        this.sent = 0;
        this.uploadId = null;
        this.status = 'queued';         // queued|uploading|paused|done|error|canceled
        this.message = '';
        this.result = null;
        this.xhr = null;
        this.retries = 0;
        this.samples = [];
        this.resumedFrom = 0;
        this.el = null;
        this.build();
    }

    UploadItem.prototype.build = function () {
        var kind = kindOf(this.type, this.ext);
        var node = el('div', 'upload-item');
        node.style.setProperty('--kind', 'var(--k-' + kind + ')');
        node.innerHTML =
            '<span class="thumb-mini"></span>' +
            '<span class="u-icon">' + esc(this.ext || 'file') + '</span>' +
            '<div class="u-main">' +
            '  <div class="u-top">' +
            '    <span class="u-name" title="' + esc(this.name) + '">' + esc(this.name) + '</span>' +
            '    <span class="u-size">' + humanSize(this.size) + '</span>' +
            '    <span class="u-status">در نوبت</span>' +
            '  </div>' +
            '  <div class="progress"><span class="bar"></span></div>' +
            '</div>' +
            '<div class="u-actions">' +
            '  <button class="act mini pause" type="button" title="توقف">' +
            '    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 5v14M15 5v14" stroke-linecap="round"/></svg>' +
            '  </button>' +
            '  <button class="act mini cancel" type="button" title="لغو">' +
            '    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 6l12 12M18 6L6 18" stroke-linecap="round"/></svg>' +
            '  </button>' +
            '</div>';
        this.el = node;
        this.refs = {
            status: node.querySelector('.u-status'),
            progress: node.querySelector('.progress'),
            bar: node.querySelector('.bar'),
            pause: node.querySelector('.pause'),
            cancel: node.querySelector('.cancel'),
            actions: node.querySelector('.u-actions')
        };
        var self = this;
        this.refs.pause.addEventListener('click', function () {
            if (self.status === 'uploading' || self.status === 'queued') self.pause();
            else if (self.status === 'paused') self.resume();
        });
        this.refs.cancel.addEventListener('click', function () { self.cancel(); });
        listBox.appendChild(node);
    };

    UploadItem.prototype.setStatus = function (text, cls) {
        this.refs.status.className = 'u-status' + (cls ? ' ' + cls : '');
        this.refs.status.innerHTML = text;
    };

    UploadItem.prototype.setProgress = function (pct) {
        pct = Math.max(0, Math.min(100, pct));
        this.pct = pct;
        this.refs.bar.style.width = pct.toFixed(2) + '%';
    };

    UploadItem.prototype.speed = function () {
        var now = Date.now();
        this.samples.push([now, this.sent]);
        while (this.samples.length && now - this.samples[0][0] > 4000) this.samples.shift();
        if (this.samples.length < 2) return 0;
        var first = this.samples[0];
        var dt = (now - first[0]) / 1000;
        var db = this.sent - first[1];
        if (dt <= 0.2 || db <= 0) return 0;
        return db / dt;
    };

    UploadItem.prototype.renderProgress = function () {
        var total = this.size || 1;
        this.setProgress(this.sent / total * 100);
        if (this.status === 'uploading') {
            var sp = this.speed();
            var eta = sp > 0 ? (total - this.sent) / sp : Infinity;
            var html = '<span class="spin"></span>' +
                faNum(Math.floor(this.sent / total * 100)) + '٪' +
                (sp > 0 ? ' · ' + humanSize(sp) + '/ث' : '') +
                (isFinite(eta) && eta > 1 ? ' · ' + humanTime(eta) : '');
            this.setStatus(html);
        }
    };

    UploadItem.prototype.pause = function () {
        if (this.status === 'done' || this.status === 'canceled') return;
        this.status = 'paused';
        if (this.xhr) { try { this.xhr.abort(); } catch (e) { } }
        this.refs.progress.classList.remove('active');
        this.refs.progress.classList.add('paused');
        this.setStatus('متوقف شد · ' + faNum(Math.floor(this.sent / (this.size || 1) * 100)) + '٪', '');
        this.refs.pause.title = 'ادامه';
        this.refs.pause.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M7 4l12 8-12 8z" stroke-linejoin="round"/></svg>';
        updateBatchBar();
    };

    UploadItem.prototype.resume = function () {
        if (this.status !== 'paused' && this.status !== 'error') return;
        this.status = 'queued';
        this.refs.progress.classList.remove('paused');
        this.refs.pause.title = 'توقف';
        this.refs.pause.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 5v14M15 5v14" stroke-linecap="round"/></svg>';
        this.setStatus('در نوبت');
        schedulePump();
    };

    UploadItem.prototype.cancel = function () {
        var self = this;
        this.status = 'canceled';
        if (this.xhr) { try { this.xhr.abort(); } catch (e) { } }
        this.refs.progress.classList.remove('active');
        this.refs.progress.classList.add('failed');
        this.setStatus('لغو شد');
        this.refs.actions.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
        if (this.uploadId) {
            postJSON(URLS.abort, { upload_id: this.uploadId }).catch(function () { });
            dropPending(this.fingerprint);
        }
        setTimeout(function () { self.remove(); }, 2200);
        updateBatchBar();
    };

    UploadItem.prototype.remove = function () {
        if (this.el) {
            this.el.style.transition = 'opacity .35s, transform .35s';
            this.el.style.opacity = '0';
            this.el.style.transform = 'translateY(-8px)';
            setTimeout((function (node) { return function () { node.remove(); }; })(this.el), 360);
        }
        items = items.filter(function (i) { return i !== this; }, this);
    };

    UploadItem.prototype.fail = function (message) {
        this.status = 'error';
        this.message = message;
        this.refs.progress.classList.remove('active');
        this.refs.progress.classList.add('failed');
        this.setStatus(esc(message), 'err');
        this.el.classList.add('error', 'shake');
        this.refs.pause.title = 'تلاش دوباره';
        this.refs.pause.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 12a8 8 0 1 1 2.6 5.9M4 19v-5h5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        updateBatchBar();
    };

    UploadItem.prototype.complete = function (file) {
        this.status = 'done';
        this.result = file;
        this.setProgress(100);
        this.refs.progress.classList.remove('active');
        this.refs.progress.classList.add('done');
        this.setStatus('<span class="check"><svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="3.2"><path d="M5 13l4.5 4.5L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg></span> انجام شد', 'ok');
        this.el.classList.add('success', 'done-anim');
        this.refs.pause.disabled = true;
        this.refs.pause.style.display = 'none';
        this.refs.cancel.style.display = 'none';

        var box = el('div', 'link-box',
            '<input type="text" readonly value="' + esc(file.url) + '" dir="ltr">' +
            '<button class="btn btn-mini primary copy" type="button">کپی لینک</button>' +
            '<a class="link-open" href="' + esc(file.url) + '" target="_blank" rel="noopener">بازکردن</a>' +
            '<a class="link-open" href="' + esc(file.page_url || file.url) + '">صفحه‌ی فایل</a>');
        box.querySelector('.copy').addEventListener('click', function () {
            copyText(file.url);
        });
        box.querySelector('input').addEventListener('focus', function () { this.select(); });
        this.el.appendChild(box);

        if (file.id && file.delete_key) {
            var keys = store(KEY_STORE);
            keys[file.id] = file.delete_key;
            store(KEY_STORE, keys);
        }
        dropPending(this.fingerprint);
        updateBatchBar();
    };

    /* ------------------------------------------------------------------
     | چسباندن تکه‌ها و مدیریت نشست
     * ----------------------------------------------------------------*/
    function initSession(item) {
        // اندازه‌ی تکه در اثر انگشت می‌آید تا تغییر اندازه، نشست تازه بسازد
        var fp = item.fingerprint + '|c' + item.chunkSize;
        return postJSON(URLS.init, {
            name: item.name,
            size: item.size,
            mime: item.type,
            fingerprint: fp,
            chunk_size: item.chunkSize
        }).then(function (res) {
            item.uploadId = res.upload_id;
            item.chunkSize = res.chunk_size || item.chunkSize;
            item.chunks = res.chunks;
            item.resumedFrom = res.resume_from || 0;
            if (item.resumedFrom > 0 && item.sent === 0) {
                item.sent = Math.min(item.size, item.resumedFrom * item.chunkSize);
                item.nextChunk = item.resumedFrom;
                item.setProgress(item.sent / (item.size || 1) * 100);
                toast('ادامه‌ی آپلود «' + item.name + '» از محل قطع', 'info', 3200);
            }
            item.nextChunk = item.resumedFrom;
            addPending(item.fingerprint, { name: item.name, size: item.size, uploadId: item.uploadId, ts: Date.now() });
            return res;
        });
    }

    function finishUpload(item) {
        item.refs.progress.classList.add('active');
        item.setStatus('<span class="spin"></span> در حال ساخت لینک…');
        return postJSON(URLS.finish, { upload_id: item.uploadId }).then(function (res) {
            item.complete(res.file);
            toast('«' + item.name + '» آپلود شد و لینکش آماده است', 'ok');
            confetti(22);
            pushRecent(res.file);
            refreshRecent();
            return res;
        });
    }

    function sleep(ms) {
        return new Promise(function (r) { setTimeout(r, ms); });
    }

    /** نصف‌کردن اندازه‌ی تکه و آماده‌سازی برای شروع دوباره */
    function shrinkChunk(item, message) {
        if (item.uploadId) {
            postJSON(URLS.abort, { upload_id: item.uploadId }).catch(function () { });
        }
        item.chunkSize = Math.max(CHUNK_MIN, Math.floor(item.chunkSize / 2));
        item.uploadId = null;
        item.nextChunk = 0;
        item.sent = 0;
        item.setProgress(0);
        item.refs.progress.classList.add('active');
        item.setStatus('<span class="spin"></span> ' + message);
        updateBatchBar();
    }

    /** حلقه‌ی اصلی آپلود یک فایل */
    function runItem(item) {
        if (item.running || item.status === 'paused' || item.status === 'canceled') return Promise.resolve();
        item.running = true;
        item.status = 'uploading';
        item.refs.progress.classList.add('active');
        item.retries = 0;

        function step() {
            if (item.status === 'paused' || item.status === 'canceled') {
                item.running = false;
                return Promise.resolve();
            }
            if (item.sent >= item.size && item.size > 0) {
                return finishUpload(item).then(function () {
                    item.running = false;
                }).catch(function (e) {
                    // اگر بعضی تکه‌ها نرسیده بودند، همان‌ها را دوباره بفرست
                    var missing = e && e.payload && e.payload.missing;
                    if (Array.isArray(missing) && missing.length) {
                        var first = Math.min.apply(null, missing);
                        item.nextChunk = first;
                        item.sent = Math.min(item.size, first * item.chunkSize);
                        item.setProgress(item.sent / (item.size || 1) * 100);
                        item.refs.progress.classList.add('active');
                        item.setStatus('<span class="spin"></span> ارسال تکه‌های باقی‌مانده…');
                        return step();
                    }
                    item.running = false;
                    item.fail(e.message || 'نهایی‌سازی ناموفق بود');
                });
            }
            var start = item.nextChunk * item.chunkSize;
            var end = Math.min(item.size, start + item.chunkSize);
            var blob = item.file.slice(start, end);
            item.renderProgress();
            return sendChunk(item.uploadId, item.nextChunk, blob, function (loaded) {
                var absolute = start + loaded;
                if (absolute > item.sent) item.sent = absolute;
                item.renderProgress();
                updateBatchBar();
            }, function (xhr) { item.xhr = xhr; }).then(function () {
                item.nextChunk += 1;
                item.sent = Math.min(item.size, item.nextChunk * item.chunkSize);
                item.retries = 0;
                item.renderProgress();
                updateBatchBar();
                return step();
            }).catch(function (err) {
                if (err.aborted || item.status === 'paused' || item.status === 'canceled') {
                    item.running = false;
                    return Promise.resolve();
                }
                // نشست منقضی شده → از سر شروع کن
                if (err.status === 410 || (err.payload && err.payload.restart)) {
                    item.uploadId = null;
                    item.nextChunk = 0;
                    item.sent = 0;
                    item.setProgress(0);
                    return initSession(item).then(step);
                }
                // تکه برای تنظیمات سرور بزرگ است → کوچک‌ترش کن
                if (err.status === 413 || (err.payload && err.payload.shrink)
                    || (err.status === 400 && item.chunkSize > CHUNK_MIN)) {
                    shrinkChunk(item, 'اندازه‌ی تکه برای سرور بزرگ بود؛ تکه‌های کوچک‌تر…');
                    return initSession(item).then(step);
                }
                item.retries += 1;
                // بعد از چند شکست پشت‌سرهم، اندازه‌ی تکه را نصف کن (پروکسی‌های سخت‌گیر)
                if (item.retries >= 4 && item.chunkSize > CHUNK_MIN) {
                    shrinkChunk(item, 'تلاش دوباره با تکه‌های کوچک‌تر…');
                    item.retries = 0;
                    return sleep(1200).then(step);
                }
                if (item.retries > 6 || !navigator.onLine) {
                    if (!navigator.onLine) {
                        item.status = 'paused';
                        item.running = false;
                        item.setStatus('اتصال قطع است · در انتظار شبکه', 'err');
                        return Promise.resolve();
                    }
                    item.running = false;
                    updateBatchBar();
                    return item.fail(err.message || 'آپلود ناموفق بود');
                }
                var wait = Math.min(12000, 700 * Math.pow(1.7, item.retries)) + Math.random() * 400;
                item.setStatus('<span class="spin"></span> تلاش دوباره در ' + faNum(Math.round(wait / 1000)) + ' ثانیه…');
                return sleep(wait).then(step);
            });
        }

        return Promise.resolve()
            .then(function () { return item.uploadId ? null : initSession(item); })
            .then(step)
            .catch(function (e) {
                item.running = false;
                item.fail(e.message || 'خطای نامشخص');
            });
    }

    /** زمان‌بندی صف آپلود */
    function schedulePump() {
        if (pumpScheduled) return;
        pumpScheduled = true;
        setTimeout(function () {
            pumpScheduled = false;
            pump();
        }, 30);
    }
    function pump() {
        var active = items.filter(function (i) { return i.running; }).length;
        var queued = items.filter(function (i) { return i.status === 'queued'; });
        for (var i = 0; i < queued.length && active < MAX_PARALLEL; i++) {
            (function (item) {
                active++;
                runItem(item).then(function () { updateBatchBar(); if (hasWork()) schedulePump(); });
            })(queued[i]);
        }
        updateBatchBar();
    }
    function hasWork() {
        return items.some(function (i) { return i.status === 'queued' || i.status === 'uploading'; });
    }

    /* ------------------------------------------------------------------
     | افزودن فایل‌ها
     * ----------------------------------------------------------------*/
    function addFiles(fileList) {
        var files = Array.prototype.slice.call(fileList || []);
        if (!files.length) return;
        var maxBatch = LIMITS.maxBatch || 50;
        if (files.length > maxBatch) {
            toast('حداکثر ' + faNum(maxBatch) + ' فایل در هر بار؛ بقیه حذف شدند.', 'warn');
            files = files.slice(0, maxBatch);
        }
        var added = 0, rejected = 0;
        files.forEach(function (file) {
            if (file.size > MAX_FILE) {
                toast('«' + file.name + '» بزرگ‌تر از ' + humanSize(MAX_FILE) + ' است', 'err', 5000);
                rejected++;
                return;
            }
            if (file.size === 0) {
                toast('«' + file.name + '» خالی است', 'warn');
                rejected++;
                return;
            }
            items.push(new UploadItem(file));
            added++;
        });
        if (added) {
            document.getElementById('batchBar').hidden = false;
            schedulePump();
            updateBatchBar();
        } else if (!rejected) {
            toast('فایلی انتخاب نشد', 'warn');
        }
    }

    /* ------------------------------------------------------------------
     | نوار وضعیت گروهی
     * ----------------------------------------------------------------*/
    var bar = {
        box: document.getElementById('batchBar'),
        label: document.getElementById('batchLabel'),
        percent: document.getElementById('batchPercent'),
        speed: document.getElementById('batchSpeed'),
        eta: document.getElementById('batchEta'),
        fill: document.getElementById('batchFill'),
        progress: null
    };
    if (bar.fill) bar.progress = bar.fill.parentElement;

    function updateBatchBar() {
        if (!bar.box) return;
        if (!items.length) { bar.box.hidden = true; return; }
        bar.box.hidden = false;

        var total = 0, sent = 0, active = 0, done = 0, failed = 0, speed = 0;
        items.forEach(function (i) {
            total += i.size;
            sent += Math.min(i.sent, i.size);
            if (i.status === 'done') done++;
            if (i.status === 'error') failed++;
            if (i.status === 'uploading') { active++; speed += i.speed(); }
        });
        var pct = total ? sent / total * 100 : 0;
        bar.fill.style.width = pct.toFixed(2) + '%';
        bar.percent.textContent = faNum(Math.floor(pct)) + '٪';
        bar.speed.textContent = speed > 0 ? humanSize(speed) + '/ث' : '—';
        bar.eta.textContent = (speed > 0 && sent < total) ? 'باقی‌مانده ' + humanTime((total - sent) / speed) : (pct >= 100 ? 'تمام شد' : '—');

        if (hasWork()) {
            bar.label.textContent = 'در حال آپلود ' + faNum(active) + ' فایل از ' + faNum(items.length);
            bar.progress.className = 'progress slim active';
        } else {
            bar.label.textContent = failed
                ? faNum(done) + ' موفق · ' + faNum(failed) + ' ناموفق'
                : 'همه‌ی ' + faNum(items.length) + ' فایل انجام شد';
            bar.progress.className = 'progress slim' + (failed ? ' failed' : ' done');
        }

        if (pct >= 100 && !hasWork() && !bar.celebrated && done) {
            bar.celebrated = true;
            confetti(46);
            toast('همه‌ی فایل‌ها آپلود شدند 🎉', 'ok', 4200);
        }
        if (hasWork()) bar.celebrated = false;
    }

    /* ------------------------------------------------------------------
     | کپی لینک
     * ----------------------------------------------------------------*/
    function copyText(text) {
        function fallback() {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.top = '-1000px';
            document.body.appendChild(ta);
            ta.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { }
            ta.remove();
            return ok;
        }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () {
                toast('لینک کپی شد', 'ok', 2200);
            }).catch(function () {
                fallback() ? toast('لینک کپی شد', 'ok', 2200) : toast('کپی نشد؛ دستی انتخاب کن', 'warn');
            });
        } else {
            fallback() ? toast('لینک کپی شد', 'ok', 2200) : toast('کپی نشد؛ دستی انتخاب کن', 'warn');
        }
    }
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-copy]') : null;
        if (btn) {
            e.preventDefault();
            copyText(btn.getAttribute('data-copy'));
        }
    });

    /* ------------------------------------------------------------------
     | آپلودهای نیمه‌کاره (برای ادامه پس از بستن صفحه)
     * ----------------------------------------------------------------*/
    function addPending(fp, info) {
        var all = store(PENDING_STORE);
        all[fp] = info;
        // حذف قدیمی‌ها
        var now = Date.now();
        Object.keys(all).forEach(function (k) {
            if (now - (all[k].ts || 0) > 24 * 3600 * 1000) delete all[k];
        });
        store(PENDING_STORE, all);
    }
    function dropPending(fp) {
        var all = store(PENDING_STORE);
        if (all[fp]) { delete all[fp]; store(PENDING_STORE, all); }
    }
    function pendingCount() {
        var all = store(PENDING_STORE);
        var now = Date.now();
        return Object.keys(all).filter(function (k) { return now - (all[k].ts || 0) < 24 * 3600 * 1000; }).length;
    }

    /* ------------------------------------------------------------------
     | فایل‌های اخیر
     * ----------------------------------------------------------------*/
    var recentGrid = document.getElementById('recentGrid');
    var MAX_RECENT = 60;

    function keys() { return store(KEY_STORE); }
    function hasKey(id) { return !!keys()[id]; }

    function fileCardHTML(f) {
        var kind = kindOf(f.mime, f.extension);
        var inline = f.inline;
        var thumbInner = (kind === 'image' && inline)
            ? '<img src="' + esc(f.url) + (f.url.indexOf('?') > -1 ? '&' : '?') + 'view=1" alt="" loading="lazy">'
            : '<span class="kind-icon">' +
            '<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M15 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V7z"/><path d="M15 3v4h4"/></svg>' +
            '</span>';
        return '<article class="file-card" data-id="' + esc(f.id) + '" style="--kind:var(--k-' + kind + ')">' +
            '<a class="thumb" href="' + esc(f.page_url || f.url) + '">' + thumbInner +
            '<span class="ext-badge">' + esc((f.extension || 'file').toUpperCase()) + '</span></a>' +
            '<div class="file-card-body">' +
            '<a class="file-name" href="' + esc(f.page_url || f.url) + '" title="' + esc(f.name) + '">' + esc(f.name) + '</a>' +
            '<div class="file-meta"><span>' + humanSize(f.size) + '</span><span class="dot"></span>' +
            '<span>' + faNum(f.downloads || 0) + ' دانلود</span></div>' +
            '</div>' +
            '<div class="file-card-actions">' +
            '<button class="act copy" type="button" data-copy="' + esc(f.url) + '" title="کپی لینک">' +
            '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="11" height="11" rx="2.5"/><path d="M15 5.5A2.5 2.5 0 0012.5 3H6a2 2 0 00-2 2v8a2 2 0 002 2h1"/></svg></button>' +
            '<a class="act" href="' + esc(f.url) + '" title="دانلود">' +
            '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 4v11m0 0l-4-4m4 4l4-4M5 20h14" stroke-linecap="round" stroke-linejoin="round"/></svg></a>' +
            (hasKey(f.id) && CFG.allowDelete !== false
                ? '<button class="act del" type="button" data-del="' + esc(f.id) + '" title="حذف">' +
                '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 7h14M9 7V5h6v2m-8 0l1 12h8l1-12" stroke-linecap="round" stroke-linejoin="round"/></svg></button>'
                : '') +
            '</div></article>';
    }

    function pushRecent(f) {
        if (!recentGrid) return;
        var emptyNode = recentGrid.querySelector('.empty');
        if (emptyNode) emptyNode.remove();
        var wrap = el('div');
        wrap.innerHTML = fileCardHTML(f);
        var card = wrap.firstChild;
        card.classList.add('bounce-in');
        recentGrid.insertBefore(card, recentGrid.firstChild);
        while (recentGrid.querySelectorAll('.file-card').length > MAX_RECENT) {
            var last = recentGrid.querySelectorAll('.file-card');
            last[last.length - 1].remove();
        }
    }

    function refreshRecent() {
        if (!recentGrid || !URLS.list) return;
        fetch(URLS.list + '?limit=' + (CFG.recentLimit || 12), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) return;
                recentGrid.innerHTML = '';
                if (!j.files.length) {
                    recentGrid.appendChild(el('div', 'empty',
                        '<svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 7a2 2 0 012-2h3.5l2 2H18a2 2 0 012 2v8a2 2 0 01-2 2H6a2 2 0 01-2-2z" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
                        '<span>هنوز فایلی آپلود نشده. اولین فایل رو بفرست!</span>'));
                    return;
                }
                j.files.forEach(function (f, idx) {
                    var wrap = el('div');
                    wrap.innerHTML = fileCardHTML(f);
                    var card = wrap.firstChild;
                    card.style.animationDelay = (idx * 0.04).toFixed(2) + 's';
                    recentGrid.appendChild(card);
                });
            })
            .catch(function () { });
    }

    /* حذف فایل */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-del]') : null;
        if (!btn) return;
        e.preventDefault();
        var id = btn.getAttribute('data-del');
        var key = keys()[id];
        if (!key) {
            toast('برای حذف، باید کلید حذف را داشته باشی (فقط فایل‌های خودت).', 'warn');
            return;
        }
        if (!window.confirm('این فایل حذف شود؟')) return;
        postJSON(URLS.delete, { id: id, key: key }).then(function () {
            var card = btn.closest('.file-card');
            if (card) {
                card.style.transition = 'opacity .3s, transform .3s';
                card.style.opacity = '0';
                card.style.transform = 'scale(.94)';
                setTimeout(function () {
                    card.remove();
                    if (!recentGrid.querySelector('.file-card')) refreshRecent();
                }, 320);
            }
            var k = keys(); delete k[id]; store(KEY_STORE, k);
            toast('فایل حذف شد', 'ok');
        }).catch(function (err) {
            toast(err.message || 'حذف ناموفق بود', 'err');
        });
    });

    /* ------------------------------------------------------------------
     | رویدادهای رابط کاربری
     * ----------------------------------------------------------------*/
    var dropzone = document.getElementById('dropzone');
    var fileInput = document.getElementById('fileInput');
    var folderInput = document.getElementById('folderInput');

    document.getElementById('pickFiles').addEventListener('click', function (e) {
        e.stopPropagation();
        fileInput.click();
    });
    document.getElementById('pickFolder').addEventListener('click', function (e) {
        e.stopPropagation();
        folderInput.click();
    });
    dropzone.addEventListener('click', function () { fileInput.click(); });
    dropzone.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
    });
    fileInput.addEventListener('change', function () { addFiles(fileInput.files); fileInput.value = ''; });
    folderInput.addEventListener('change', function () { addFiles(folderInput.files); folderInput.value = ''; });

    var dragDepth = 0;
    function isFileDrag(e) {
        var dt = e.dataTransfer;
        return dt && Array.prototype.indexOf.call(dt.types || [], 'Files') > -1;
    }
    ['dragenter', 'dragover'].forEach(function (ev) {
        window.addEventListener(ev, function (e) {
            if (!isFileDrag(e)) return;
            e.preventDefault();
            if (ev === 'dragenter') dragDepth++;
            dropzone.classList.add('dragging');
        });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        window.addEventListener(ev, function (e) {
            if (!isFileDrag(e)) return;
            if (ev === 'dragleave') { dragDepth = Math.max(0, dragDepth - 1); if (dragDepth > 0) return; }
            e.preventDefault();
            dragDepth = 0;
            dropzone.classList.remove('dragging');
            if (ev === 'drop' && e.dataTransfer && e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
        });
    });

    // چسباندن فایل با Ctrl+V
    document.addEventListener('paste', function (e) {
        if (!e.clipboardData || !e.clipboardData.files || !e.clipboardData.files.length) return;
        var files = Array.prototype.slice.call(e.clipboardData.files).map(function (f, i) {
            if (f.name) return f;
            var ext = (f.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
            return new File([f], 'paste-' + Date.now() + '-' + i + '.' + ext, { type: f.type });
        });
        toast('فایل از کلیپ‌بورد اضافه شد', 'info');
        addFiles(files);
    });

    document.getElementById('pauseAll').addEventListener('click', function () {
        items.filter(function (i) { return i.status === 'uploading' || i.status === 'queued'; })
            .forEach(function (i) { i.pause(); });
        toast('آپلودها متوقف شد', 'warn');
    });
    document.getElementById('resumeAll').addEventListener('click', function () {
        items.filter(function (i) { return i.status === 'paused' || i.status === 'error'; })
            .forEach(function (i) { i.resume(); });
        schedulePump();
    });
    document.getElementById('cancelAll').addEventListener('click', function () {
        if (!items.length) return;
        if (!window.confirm('همه‌ی آپلودها لغو شوند؟')) return;
        items.slice().forEach(function (i) { i.cancel(); });
        toast('همه لغو شد', 'warn');
    });
    document.getElementById('clearDone').addEventListener('click', function () {
        items.slice().forEach(function (i) { if (i.status === 'done') i.remove(); });
        setTimeout(updateBatchBar, 400);
    });
    var refreshBtn = document.getElementById('refreshRecent');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            refreshBtn.disabled = true;
            refreshRecent();
            setTimeout(function () { refreshBtn.disabled = false; toast('لیست به‌روز شد', 'info', 1800); }, 500);
        });
    }

    /* تم روشن/تاریک */
    var themeBtn = document.getElementById('themeToggle');
    var savedTheme = null;
    try { savedTheme = localStorage.getItem('up:theme'); } catch (e) { }
    if (savedTheme) document.documentElement.setAttribute('data-theme', savedTheme);
    themeBtn.addEventListener('click', function () {
        var next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', next);
        try { localStorage.setItem('up:theme', next); } catch (e) { }
        toast(next === 'light' ? 'تم روشن شد' : 'تم تاریک شد', 'info', 1600);
    });

    /* اتصال شبکه */
    window.addEventListener('offline', function () {
        items.filter(function (i) { return i.status === 'uploading'; }).forEach(function (i) { i.pause(); });
        toast('اینترنت قطع شد؛ بعد از وصل شدن «ادامه‌ی همه» را بزن', 'err', 5200);
    });
    window.addEventListener('online', function () {
        var paused = items.filter(function (i) { return i.status === 'paused' && i.uploadId; });
        if (paused.length) {
            toast('اینترنت برگشت؛ ادامه می‌دهیم…', 'ok');
            paused.forEach(function (i) { i.resume(); });
        }
    });

    /* محافظت از بستن صفحه هنگام آپلود */
    window.addEventListener('beforeunload', function (e) {
        if (hasWork()) {
            e.preventDefault();
            e.returnValue = 'آپلودها هنوز تمام نشده‌اند. مطمئنی می‌خواهی صفحه را ببندی؟';
            return e.returnValue;
        }
    });

    /* ------------------------------------------------------------------
     | راه‌اندازی
     * ----------------------------------------------------------------*/
    (function boot() {
        // دکمه‌ی حذف فقط برای فایل‌هایی که کلید حذفشان را داریم نمایش داده می‌شود
        var myKeys = keys();
        document.querySelectorAll('[data-del]').forEach(function (btn) {
            if (myKeys[btn.getAttribute('data-del')] && CFG.allowDelete !== false) {
                btn.style.display = '';
            } else {
                btn.remove();
            }
        });

        var flash = CFG.flash || {};
        if (flash.message) {
            toast(flash.message, flash.type === 'error' ? 'err' : (flash.type === 'ok' ? 'ok' : 'info'), 6000);
        }
        var pending = pendingCount();
        if (pending > 0) {
            toast('آپلود نیمه‌کاره داری؛ همان فایل را دوباره انتخاب کن تا از محل قطع ادامه دهد.', 'warn', 7000);
        }
        updateBatchBar();
    })();
})();
