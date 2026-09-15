<?php
/**
 * file.php — صفحه‌ی اختصاصی هر فایل (پیش‌نمایش + لینک‌ها)
 * ?id=xxxxx  یا  /f/xxxxx با router.php
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$id = preg_replace('/[^a-z0-9]/i', '', (string) ($_GET['id'] ?? ''));
$f  = $id !== '' ? find_file($id) : null;

if (!$f) {
    http_response_code(404);
    ?>
    <!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>فایل پیدا نشد</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(asset('assets/style.css'), ENT_QUOTES) ?>"></head>
    <body class="app">
    <div class="bg" aria-hidden="true"><span class="blob blob-1"></span><span class="blob blob-2"></span><span class="grid"></span></div>
    <main class="wrap" style="min-height:70vh;justify-content:center;align-items:center">
        <section class="card" style="text-align:center;max-width:520px">
            <h1 style="font-size:64px;font-weight:900;margin:0;background:linear-gradient(135deg,var(--accent),var(--accent2,var(--accent-2)));-webkit-background-clip:text;background-clip:text;color:transparent">۴۰۴</h1>
            <p style="color:var(--text-dim)">این فایل پیدا نشد یا حذف شده است.</p>
            <p style="margin-top:16px"><a class="btn btn-primary" href="<?= htmlspecialchars(base_url(), ENT_QUOTES) ?>/">بازگشت به پنل آپلود</a></p>
        </section>
    </main></body></html>
    <?php
    exit;
}

$url      = file_url($f);
$mime     = (string) ($f['mime'] ?? 'application/octet-stream');
$kind     = 'file';
if (str_starts_with($mime, 'image/')) $kind = 'image';
elseif (str_starts_with($mime, 'video/')) $kind = 'video';
elseif (str_starts_with($mime, 'audio/')) $kind = 'audio';
elseif ($mime === 'application/pdf') $kind = 'pdf';
$inline   = is_inline_mime($mime);
$viewUrl  = $url . (str_contains($url, '?') ? '&' : '?') . 'view=1';
$dlUrl    = $url . (str_contains($url, '?') ? '&' : '?') . 'dl=1';
?>
<!doctype html>
<html lang="fa" dir="rtl" data-theme="<?= htmlspecialchars((string) cfg('default_theme', 'dark'), ENT_QUOTES) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars((string) $f['name'], ENT_QUOTES) ?> — <?= htmlspecialchars((string) cfg('site_title'), ENT_QUOTES) ?></title>
<meta property="og:title" content="<?= htmlspecialchars((string) $f['name'], ENT_QUOTES) ?>">
<meta property="og:description" content="<?= htmlspecialchars(human_size((int) $f['size']), ENT_QUOTES) ?> · دانلود از پنل آپلود">
<?php if ($kind === 'image'): ?>
<meta property="og:image" content="<?= htmlspecialchars($viewUrl, ENT_QUOTES) ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= htmlspecialchars(asset('assets/style.css'), ENT_QUOTES) ?>">
</head>
<body class="app">

<div class="bg" aria-hidden="true">
    <span class="blob blob-1"></span><span class="blob blob-2"></span><span class="blob blob-3"></span>
    <span class="grid"></span><span class="noise"></span>
</div>

<header class="topbar">
    <a class="brand" href="<?= htmlspecialchars(base_url(), ENT_QUOTES) ?>/">
        <span class="logo" aria-hidden="true">
            <svg viewBox="0 0 32 32" width="24" height="24"><path d="M16 24V9m0 0l-6 6m6-6l6 6" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </span>
        <span class="brand-text">
            <strong><?= htmlspecialchars((string) cfg('site_title'), ENT_QUOTES) ?></strong>
            <small>صفحه‌ی فایل</small>
        </span>
    </a>
    <div class="topbar-actions">
        <a class="pill" href="<?= htmlspecialchars(base_url(), ENT_QUOTES) ?>/">پنل آپلود</a>
        <button class="icon-btn" id="themeToggle" type="button" title="تغییر تم">
            <svg class="i-moon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <svg class="i-sun" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4.2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9L17 7M7 17l-2.1 2.1" stroke-linecap="round"/></svg>
        </button>
    </div>
</header>

<main class="wrap" style="max-width:820px">
    <section class="card bounce-in">
        <div class="card-head">
            <h2>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V7z"/><path d="M15 3v4h4"/></svg>
                <span style="word-break:break-all"><?= htmlspecialchars((string) $f['name'], ENT_QUOTES) ?></span>
            </h2>
        </div>

        <?php if ($kind === 'image'): ?>
            <div class="preview-wrap" style="border-radius:var(--radius-sm);overflow:hidden;border:1px solid var(--border);background:rgba(0,0,0,.2)">
                <img src="<?= htmlspecialchars($viewUrl, ENT_QUOTES) ?>" alt="<?= htmlspecialchars((string) $f['name'], ENT_QUOTES) ?>"
                     style="display:block;width:100%;max-height:60vh;object-fit:contain">
            </div>
        <?php elseif ($kind === 'video'): ?>
            <video controls preload="metadata" playsinline
                   style="width:100%;max-height:60vh;border-radius:var(--radius-sm);border:1px solid var(--border);background:#000"
                   src="<?= htmlspecialchars($url, ENT_QUOTES) ?>"></video>
        <?php elseif ($kind === 'audio'): ?>
            <audio controls preload="metadata" style="width:100%" src="<?= htmlspecialchars($url, ENT_QUOTES) ?>"></audio>
        <?php elseif ($kind === 'pdf'): ?>
            <p style="color:var(--text-dim);font-size:13.5px">این فایل PDF است؛ می‌توانی در تب جدید ببینی یا دانلودش کنی.</p>
        <?php endif; ?>

        <div class="file-meta" style="margin:16px 0 18px;font-size:13px;flex-wrap:wrap;gap:10px;display:flex;align-items:center">
            <span class="badge-soft"><?= fa_num(human_size((int) $f['size'])) ?></span>
            <span class="badge-soft"><?= htmlspecialchars(strtoupper((string) ($f['ext'] ?? 'FILE')) ?: 'FILE', ENT_QUOTES) ?></span>
            <span class="badge-soft"><?= fa_num((int) ($f['downloads'] ?? 0)) ?> دانلود</span>
            <span class="badge-soft"><?= fa_num(date('Y/m/d H:i', (int) $f['uploaded'])) ?></span>
        </div>

        <div class="link-box" style="grid-column:auto;margin-bottom:14px">
            <input type="text" readonly value="<?= htmlspecialchars($url, ENT_QUOTES) ?>" dir="ltr" id="directLink">
            <button class="btn btn-mini" id="copyLink" type="button">کپی لینک</button>
        </div>

        <div class="dz-buttons" style="justify-content:flex-start">
            <a class="btn btn-primary" href="<?= htmlspecialchars($dlUrl, ENT_QUOTES) ?>">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 4v11m0 0l-4-4m4 4l4-4M5 20h14" stroke-linecap="round" stroke-linejoin="round"/></svg>
                دانلود فایل
            </a>
            <?php if ($inline && $kind !== 'image'): ?>
                <a class="btn btn-ghost" href="<?= htmlspecialchars($viewUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener">نمایش در مرورگر</a>
            <?php endif; ?>
            <button class="btn btn-ghost" id="copyPage" type="button">کپی لینک صفحه</button>
            <button class="btn btn-ghost" id="deleteFile" type="button" style="color:var(--err);display:none">حذف فایل</button>
        </div>
    </section>

    <footer class="footer">
        <span><?= htmlspecialchars((string) cfg('footer_text'), ENT_QUOTES) ?></span>
    </footer>
</main>

<div class="toasts" id="toasts" aria-live="polite"></div>

<script>
window.FILE_DATA = {
    id: <?= json_encode($f['id'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    url: <?= json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    pageUrl: <?= json_encode(file_page_url($f), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
};
window.UPLOAD_PANEL = {
    urls: { delete: <?= json_encode(base_url() . '/api/delete.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> },
    flash: {}
};
</script>
<script>
(function () {
    var toastBox = document.getElementById('toasts');
    function toast(msg, type) {
        var t = document.createElement('div');
        t.className = 'toast ' + (type || 'info');
        var icon = document.createElement('span');
        icon.className = 't-icon';
        icon.textContent = '✓';
        var text = document.createElement('span');
        text.textContent = msg == null ? '' : String(msg);   // متن همیشه به‌صورت متن، نه HTML
        t.appendChild(icon);
        t.appendChild(text);
        toastBox.appendChild(t);
        setTimeout(function () { t.classList.add('out'); setTimeout(function () { t.remove(); }, 380); }, 2800);
    }
    var theme = null;
    try { theme = localStorage.getItem('up:theme'); } catch (e) { }
    if (theme) document.documentElement.setAttribute('data-theme', theme);
    document.getElementById('themeToggle').addEventListener('click', function () {
        var next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', next);
        try { localStorage.setItem('up:theme', next); } catch (e) { }
    });
    function copy(text) {
        var ok = false;
        var ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.top = '-1000px';
        document.body.appendChild(ta); ta.select();
        try { ok = document.execCommand('copy'); } catch (e) { }
        ta.remove();
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () { toast('لینک کپی شد', 'ok'); });
            return;
        }
        toast(ok ? 'لینک کپی شد' : 'کپی نشد، دستی انتخاب کن', ok ? 'ok' : 'warn');
    }
    document.getElementById('copyLink').addEventListener('click', function () { copy(FILE_DATA.url); });
    document.getElementById('copyPage').addEventListener('click', function () { copy(FILE_DATA.pageUrl); });

    var delBtn = document.getElementById('deleteFile');
    var key = null;
    try { key = (JSON.parse(localStorage.getItem('up:keys') || '{}'))[FILE_DATA.id]; } catch (e) { }
    if (key) {
        delBtn.style.display = '';
        delBtn.addEventListener('click', function () {
            if (!confirm('این فایل برای همیشه حذف شود؟')) return;
            fetch(window.UPLOAD_PANEL.urls.delete, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: FILE_DATA.id, key: key })
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (j.ok) {
                    toast('فایل حذف شد', 'ok');
                    setTimeout(function () { location.href = <?= json_encode(base_url() . '/', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; }, 900);
                } else {
                    toast(j.error || 'حذف ناموفق بود', 'err');
                }
            }).catch(function () { toast('حذف ناموفق بود', 'err'); });
        });
    }
})();
</script>
</body>
</html>
