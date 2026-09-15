<?php
/**
 * index.php — پنل آپلود فایل
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$limits  = server_limits();
$recent  = cfg('show_recent', true) ? recent_files((int) cfg('recent_limit', 12)) : [];
$used    = total_storage_used();
$maxSize = (int) $limits['max_file_size'];

$accent  = (string) cfg('accent', '#6c5ce7');
$accent2 = (string) cfg('accent2', '#00d2ff');
$accent3 = (string) cfg('accent3', '#ff5e9c');

// پیام فلاش (وقتی کاربر بدون جاوااسکریپت آپلود می‌کند به اینجا ریدایرکت می‌شود)
// نکته‌ی امنیتی: این مقدار از آدرس می‌آید، پس کوتاه و پاک‌سازی می‌شود.
$flashMsg = isset($_GET['msg']) ? (string) $_GET['msg'] : '';
$flashMsg = mb_substr(str_replace(["\r", "\n", "\t"], ' ', $flashMsg), 0, 240, 'UTF-8');
$flashType = isset($_GET['up']) ? strtolower((string) $_GET['up']) : 'info';
if (!in_array($flashType, ['info', 'ok', 'error', 'warn'], true)) {
    $flashType = 'info';
}

/** آیکون بر اساس نوع فایل */
function file_kind(string $mime, string $ext): string
{
    $m = strtolower($mime);
    if (str_starts_with($m, 'image/')) return 'image';
    if (str_starts_with($m, 'video/')) return 'video';
    if (str_starts_with($m, 'audio/')) return 'audio';
    if ($m === 'application/pdf') return 'pdf';
    if (in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz'], true)) return 'archive';
    if (in_array($ext, ['doc', 'docx', 'txt', 'rtf', 'odt'], true)) return 'doc';
    if (in_array($ext, ['xls', 'xlsx', 'csv', 'ods'], true)) return 'sheet';
    if (in_array($ext, ['apk', 'exe', 'msi', 'dmg', 'iso'], true)) return 'app';
    if (in_array($ext, ['js', 'css', 'html', 'json', 'php', 'py', 'ts'], true)) return 'code';
    return 'file';
}
?>
<!doctype html>
<html lang="fa" dir="rtl" data-theme="<?= htmlspecialchars((string) cfg('default_theme', 'dark'), ENT_QUOTES) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark light">
<title><?= htmlspecialchars((string) cfg('site_title'), ENT_QUOTES) ?></title>
<meta name="description" content="آپلود فایل تا ۲ گیگابایت و دریافت لینک مستقیم">
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $accent . '"/><stop offset="1" stop-color="' . $accent2 . '"/></linearGradient></defs><rect width="32" height="32" rx="9" fill="url(#g)"/><path d="M16 23V11m0 0l-5 5m5-5l5 5" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>') ?>">
<style>
    :root{
        --accent:<?= htmlspecialchars($accent, ENT_QUOTES) ?>;
        --accent-2:<?= htmlspecialchars($accent2, ENT_QUOTES) ?>;
        --accent-3:<?= htmlspecialchars($accent3, ENT_QUOTES) ?>;
    }
</style>
<link rel="stylesheet" href="<?= htmlspecialchars(asset('assets/style.css'), ENT_QUOTES) ?>">
</head>
<body class="app">

<!-- لایه‌های پس‌زمینه‌ی متحرک -->
<div class="bg" aria-hidden="true">
    <span class="blob blob-1"></span>
    <span class="blob blob-2"></span>
    <span class="blob blob-3"></span>
    <span class="grid"></span>
    <span class="noise"></span>
</div>

<header class="topbar">
    <div class="brand">
        <span class="logo" aria-hidden="true">
            <svg viewBox="0 0 32 32" width="26" height="26">
                <path d="M16 24V9m0 0l-6 6m6-6l6 6" fill="none" stroke="currentColor" stroke-width="2.8"
                      stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </span>
        <span class="brand-text">
            <strong><?= htmlspecialchars((string) cfg('site_title'), ENT_QUOTES) ?></strong>
            <small><?= htmlspecialchars((string) cfg('site_subtitle'), ENT_QUOTES) ?></small>
        </span>
    </div>
    <div class="topbar-actions">
        <span class="pill" title="حداکثر حجم هر فایل">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12m0 0l-4-4m4 4l4-4M4 19h16" stroke-linecap="round" stroke-linejoin="round"/></svg>
            تا <?= fa_num(human_size($maxSize)) ?>
        </span>
        <span class="pill" title="فضای مصرفی">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/></svg>
            <?= fa_num(human_size($used)) ?>
        </span>
        <button class="icon-btn" id="themeToggle" type="button" title="تغییر تم" aria-label="تغییر تم">
            <svg class="i-moon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <svg class="i-sun" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4.2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9L17 7M7 17l-2.1 2.1" stroke-linecap="round"/></svg>
        </button>
    </div>
</header>

<main class="wrap">

    <!-- کارت آپلود -->
    <section class="card upload-card">
        <div class="dropzone" id="dropzone" role="button" tabindex="0"
             aria-label="انتخاب یا رها کردن فایل برای آپلود">
            <svg class="dz-border" aria-hidden="true">
                <rect x="1.5" y="1.5" width="calc(100% - 3px)" height="calc(100% - 3px)" rx="22" ry="22"/>
            </svg>

            <div class="dz-inner">
                <div class="dz-icon" aria-hidden="true">
                    <span class="ring"></span>
                    <span class="ring ring-2"></span>
                    <span class="cloud">
                        <svg viewBox="0 0 64 64" width="70" height="70" fill="none" stroke="currentColor" stroke-width="3.2"
                             stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 46h24a10 10 0 001-19.9A14 14 0 0020.6 25 10.5 10.5 0 0020 46z"/>
                            <path class="arrow" d="M32 44V26m0 0l-6 6m6-6l6 6"/>
                        </svg>
                    </span>
                </div>

                <h1 class="dz-title">فایل رو بکش و بنداز اینجا</h1>
                <p class="dz-sub">
                    یا <b>کلیک کن</b> تا از دستگاه انتخاب کنی —
                    حداکثر <b><?= fa_num(human_size($maxSize)) ?></b> برای هر فایل،
                    چند فایل با هم هم می‌شود.
                </p>

                <div class="dz-buttons">
                    <button class="btn btn-primary" id="pickFiles" type="button">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 16V4m0 0L7 9m5-5l5 5M4 20h16" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        انتخاب فایل
                    </button>
                    <button class="btn btn-ghost" id="pickFolder" type="button">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        انتخاب پوشه
                    </button>
                </div>

                <p class="dz-hint">
                    آپلود روی سرور ادامه‌پذیر است؛ اگر اینترنت قطع شد، همان فایل را دوباره انتخاب کن تا از همان‌جا ادامه دهد.
                </p>
            </div>

            <input type="file" id="fileInput" multiple hidden>
            <input type="file" id="folderInput" webkitdirectory directory multiple hidden>
            <form id="nojsForm" class="nojs-form" action="<?= htmlspecialchars(base_url(), ENT_QUOTES) ?>/api/upload-simple.php" method="post" enctype="multipart/form-data">
                <input type="file" name="file[]" multiple id="nojsInput">
                <button type="submit" class="btn btn-ghost">آپلود بدون جاوااسکریپت</button>
            </form>
        </div>

        <!-- نوار وضعیت گروهی -->
        <div class="batch-bar" id="batchBar" hidden>
            <div class="batch-info">
                <span class="batch-label" id="batchLabel">در حال آپلود…</span>
                <span class="batch-stats">
                    <span id="batchPercent">۰٪</span>
                    <span class="dot"></span>
                    <span id="batchSpeed">۰</span>
                    <span class="dot"></span>
                    <span id="batchEta">—</span>
                </span>
            </div>
            <div class="progress slim"><span class="bar" id="batchFill" style="width:0%"></span></div>
            <div class="batch-actions">
                <button class="btn btn-mini" id="pauseAll" type="button">توقف همه</button>
                <button class="btn btn-mini" id="resumeAll" type="button">ادامه‌ی همه</button>
                <button class="btn btn-mini danger" id="cancelAll" type="button">لغو همه</button>
                <button class="btn btn-mini ghost" id="clearDone" type="button">پاک کردن لیست</button>
            </div>
        </div>

        <!-- لیست آپلودها -->
        <div class="upload-list" id="uploadList" aria-live="polite"></div>
    </section>

    <?php if (cfg('show_recent', true)): ?>
    <section class="card recent-card">
        <div class="card-head">
            <h2>
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2" stroke-linecap="round"/></svg>
                فایل‌های اخیر
            </h2>
            <button class="btn btn-mini ghost" id="refreshRecent" type="button">به‌روزرسانی</button>
        </div>
        <div class="recent-grid" id="recentGrid">
            <?php if (!$recent): ?>
                <div class="empty" id="recentEmpty">
                    <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 7a2 2 0 012-2h3.5l2 2H18a2 2 0 012 2v8a2 2 0 01-2 2H6a2 2 0 01-2-2z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span>هنوز فایلی آپلود نشده. اولین فایل رو بفرست!</span>
                </div>
            <?php endif; ?>
            <?php foreach ($recent as $f):
                $kind = file_kind((string) ($f['mime'] ?? ''), (string) ($f['ext'] ?? ''));
                $inline = is_inline_mime((string) ($f['mime'] ?? ''));
            ?>
            <article class="file-card" data-id="<?= htmlspecialchars((string) $f['id'], ENT_QUOTES) ?>">
                <a class="thumb" href="<?= htmlspecialchars(file_page_url($f), ENT_QUOTES) ?>" style="--kind:var(--k-<?= $kind ?>)">
                    <?php if ($kind === 'image'): ?>
                        <img src="<?= htmlspecialchars(file_url($f) . (str_contains(file_url($f), '?') ? '&' : '?') . 'view=1', ENT_QUOTES) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <span class="kind-icon">
                            <?php if ($kind === 'video'): ?>
                                <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="6" width="13" height="12" rx="2.5"/><path d="M16 11l5-3v8l-5-3z"/></svg>
                            <?php elseif ($kind === 'audio'): ?>
                                <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 18V6l10-2v12" stroke-linecap="round"/><circle cx="6.5" cy="18" r="2.6"/><circle cx="16.5" cy="16" r="2.6"/></svg>
                            <?php elseif ($kind === 'pdf'): ?>
                                <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8z"/><path d="M14 3v5h5"/></svg>
                            <?php elseif ($kind === 'archive'): ?>
                                <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 8a2 2 0 012-2h12a2 2 0 012 2v9a2 2 0 01-2 2H6a2 2 0 01-2-2z"/><path d="M12 6v6m0 0l-2-2m2 2l2-2" stroke-linecap="round"/></svg>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M15 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V7z"/><path d="M15 3v4h4"/></svg>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                    <span class="ext-badge"><?= htmlspecialchars(mb_strtoupper((string) ($f['ext'] ?? '')) ?: 'FILE', ENT_QUOTES) ?></span>
                </a>
                <div class="file-card-body">
                    <a class="file-name" href="<?= htmlspecialchars(file_page_url($f), ENT_QUOTES) ?>" title="<?= htmlspecialchars((string) $f['name'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $f['name'], ENT_QUOTES) ?></a>
                    <div class="file-meta">
                        <span><?= fa_num(human_size((int) $f['size'])) ?></span>
                        <span class="dot"></span>
                        <span><?= fa_num((int) ($f['downloads'] ?? 0)) ?> دانلود</span>
                    </div>
                </div>
                <div class="file-card-actions">
                    <button class="act copy" type="button" data-copy="<?= htmlspecialchars(file_url($f), ENT_QUOTES) ?>" title="کپی لینک">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="11" height="11" rx="2.5"/><path d="M15 5.5A2.5 2.5 0 0012.5 3H6a2 2 0 00-2 2v8a2 2 0 002 2h1"/></svg>
                    </button>
                    <a class="act" href="<?= htmlspecialchars(file_url($f), ENT_QUOTES) ?>" title="دانلود">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 4v11m0 0l-4-4m4 4l4-4M5 20h14" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                    <button class="act del" type="button" style="display:none" data-del="<?= htmlspecialchars((string) $f['id'], ENT_QUOTES) ?>" title="حذف">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 7h14M9 7V5h6v2m-8 0l1 12h8l1-12" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <footer class="footer">
        <span><?= htmlspecialchars((string) cfg('footer_text'), ENT_QUOTES) ?></span>
        <span class="dot"></span>
        <span>PHP <?= fa_num(PHP_VERSION) ?> · <?= fa_num(human_size($maxSize)) ?> برای هر فایل</span>
    </footer>
</main>

<div class="toasts" id="toasts" aria-live="polite"></div>
<div class="confetti" id="confetti" aria-hidden="true"></div>

<script>
window.UPLOAD_PANEL = <?= json_encode([
    'baseUrl'    => base_url(),
    'urls'       => [
        'init'    => base_url() . '/api/init.php',
        'chunk'   => base_url() . '/api/chunk.php',
        'finish'  => base_url() . '/api/finish.php',
        'abort'   => base_url() . '/api/abort.php',
        'list'    => base_url() . '/api/list.php',
        'delete'  => base_url() . '/api/delete.php',
    ],
    'limits'     => [
        'maxFileSize' => $maxSize,
        'chunkSize'   => (int) $limits['max_chunk'],
        'maxBatch'    => (int) cfg('max_files_per_batch', 50),
    ],
    'recentLimit' => (int) cfg('recent_limit', 12),
    'allowDelete' => (bool) cfg('allow_delete', true),
    'flash'       => ['type' => $flashType, 'message' => $flashMsg],
    // JSON_HEX_* : جلوگیری از بستن تگ <script> با مقدارهای ورودی (ضد XSS)
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= htmlspecialchars(asset('assets/app.js'), ENT_QUOTES) ?>" defer></script>
</body>
</html>
