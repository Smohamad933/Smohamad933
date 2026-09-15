<?php
/**
 * download.php — ارسال فایل برای دانلود/نمایش
 * ?id=xxxxx&dl=1 (اجبار دانلود)  |  ?view=1 (نمایش داخل مرورگر برای انواع مجاز)
 * از Range پشتیبانی می‌کند (دانلود/ادامه‌ی ویدیو)
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$id = preg_replace('/[^a-z0-9]/i', '', (string) ($_GET['id'] ?? ''));
if ($id === '') {
    http_response_code(400);
    exit('شناسه نامعتبر است.');
}

$f = find_file($id);
if (!$f) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>یافت نشد</title>'
        . '<body style="font-family:Tahoma;background:#0f1220;color:#e8e8f0;display:flex;align-items:center;'
        . 'justify-content:center;height:100vh;margin:0"><div style="text-align:center">'
        . '<h1 style="font-size:64px;margin:0">۴۰۴</h1><p>این فایل پیدا نشد یا حذف شده است.</p>'
        . '<a href="' . htmlspecialchars(base_url(), ENT_QUOTES) . '/" style="color:#8b7cff">بازگشت به پنل</a>'
        . '</div></body>';
    exit;
}

$path = stored_file_path($f);
if (!is_file($path)) {
    http_response_code(410);
    exit('فایل روی سرور موجود نیست.');
}

$size = (int) filesize($path);
$mime = (string) ($f['mime'] ?? 'application/octet-stream');
$name = (string) ($f['name'] ?? 'file');

$forceDownload = isset($_GET['dl']) && $_GET['dl'] !== '0';
$forceView     = isset($_GET['view']) && $_GET['view'] !== '0';
$inline = $forceView ? is_inline_mime($mime) : (is_inline_mime($mime) && !$forceDownload);

// شمارش دانلود (فقط دانلود واقعی: نه درخواست Range و نه پیش‌نمایش درون‌صفحه)
$isRange = isset($_SERVER['HTTP_RANGE']);
if (!$isRange && !$forceView) {
    db_mutate(function (array &$db) use ($id) {
        if (isset($db['files'][$id])) {
            $db['files'][$id]['downloads'] = (int) ($db['files'][$id]['downloads'] ?? 0) + 1;
        }
        return true;
    });
}

// هدرهای امنیتی و کش
header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src none; sandbox');
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: "' . md5($id . $size . ($f['uploaded'] ?? 0)) . '"');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) ($f['uploaded'] ?? time())) . ' GMT');

// Content-Disposition با پشتیبانی از نام فارسی
$asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $name) ?: 'file';
$asciiFallback = str_replace(['"', '\\'], '_', $asciiFallback);
header(
    'Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="' . $asciiFallback . '"'
    . "; filename*=UTF-8''" . rawurlencode($name)
);

// درخواست‌های شرطی (کش سمت مرورگر)
$etag = '"' . md5($id . $size . ($f['uploaded'] ?? 0)) . '"';
$notModified = ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag;
if (!$notModified && isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
    $since = @strtotime((string) $_SERVER['HTTP_IF_MODIFIED_SINCE']);
    $mtime = (int) ($f['uploaded'] ?? 0);
    if ($since !== false && $mtime > 0 && $mtime <= $since) {
        $notModified = true;
    }
}
if ($notModified) {
    // پاسخ ۳۰۴ نه بدنه دارد و نه Content-Length
    http_response_code(304);
    exit;
}

// مدیریت Range
// نکته: Content-Length فقط جایی ست می‌شود که واقعاً بدنه داریم (۲۰۰ یا ۲۰۶)
// تا پاسخ‌های ۳۰۴/۴۱۶ استاندارد بمانند.
$start = 0;
$end   = $size - 1;
$partial = false;
if ($isRange && preg_match('/bytes=(\d*)-(\d*)/i', (string) $_SERVER['HTTP_RANGE'], $m)) {
    $from = $m[1] === '' ? null : (int) $m[1];
    $to   = $m[2] === '' ? null : (int) $m[2];
    if ($from === null && $to !== null) {           // آخرین N بایت
        $start = max(0, $size - $to);
        $end   = $size - 1;
    } else {
        $start = max(0, (int) $from);
        $end   = $to === null ? $size - 1 : min($to, $size - 1);
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        header('Content-Length: 0');
        exit;
    }
    $partial = true;
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
header('Content-Length: ' . ($partial ? ($end - $start + 1) : $size));

// ارسال خروجی به‌صورت تکه‌ای (حافظه‌ی کم)
while (ob_get_level() > 0) {
    @ob_end_clean();
}
set_time_limit(0);
@ignore_user_abort(false);

$fp = @fopen($path, 'rb');
if (!$fp) {
    http_response_code(500);
    exit('خطا در خواندن فایل.');
}
if ($start > 0) {
    fseek($fp, $start);
}
$remaining = $end - $start + 1;
$buffer = 512 * 1024;
while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
    $read = (int) min($buffer, $remaining);
    $data = fread($fp, $read);
    if ($data === false || $data === '') {
        break;
    }
    echo $data;
    $remaining -= strlen($data);
    @flush();
}
fclose($fp);
exit;
