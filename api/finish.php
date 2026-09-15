<?php
/**
 * api/finish.php — چسباندن تکه‌ها و ساخت فایل نهایی + لینک
 * POST JSON: { upload_id }
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_fail('فقط درخواست POST مجاز است.', 405);
}

$uploadId = preg_replace('/[^a-z0-9]/i', '', (string) param('upload_id', param('u', '')));
if ($uploadId === '') {
    json_fail('شناسه‌ی آپلود نامعتبر است.');
}

$meta = session_read($uploadId);
if (!$meta) {
    json_fail('نشست آپلود پیدا نشد یا منقضی شده است.', 410, ['restart' => true]);
}

$dir = session_dir($uploadId);

// قفل: جلوگیری از اجرای هم‌زمان دو درخواست finish برای یک نشست
$lockFp = @fopen($dir . '/.finish.lock', 'c+');
if (!$lockFp || !@flock($lockFp, LOCK_EX | LOCK_NB)) {
    json_fail('عملیات نهایی‌سازی همین حالا در حال اجراست...', 409);
}

$chunks = (int) $meta['chunks'];
$size   = (int) $meta['size'];
$name   = clean_filename((string) $meta['name']);

// بررسی کامل بودن تکه‌ها
$missing = [];
$totalOnDisk = 0;
for ($i = 0; $i < $chunks; $i++) {
    $p = $dir . '/' . $i . '.part';
    if (!is_file($p)) {
        $missing[] = $i;
        continue;
    }
    $totalOnDisk += (int) filesize($p);
}
if ($missing) {
    @flock($lockFp, LOCK_UN);
    fclose($lockFp);
    json_fail('بعضی از تکه‌ها نرسیده‌اند. دوباره تلاش کنید.', 409, ['missing' => array_slice($missing, 0, 20)]);
}
if ($totalOnDisk !== $size) {
    @flock($lockFp, LOCK_UN);
    fclose($lockFp);
    json_fail('حجم کل تکه‌ها با حجم فایل اصلی هم‌خوانی ندارد.', 400);
}
if (!has_free_space($size)) {
    @flock($lockFp, LOCK_UN);
    fclose($lockFp);
    json_fail('فضای کافی برای نهایی‌سازی فایل روی سرور نیست.', 507);
}

$fileId   = random_id(8);
$storeExt = safe_extension($name);
$stored   = $fileId . '.' . $storeExt;
$finalPath = storage_path('files', $stored);
$tmpFinal  = storage_path('files', $stored . '.building');

$out = @fopen($tmpFinal, 'wb');
if (!$out) {
    @flock($lockFp, LOCK_UN);
    fclose($lockFp);
    json_fail('ساخت فایل نهایی ناموفق بود (دسترسی نوشتن).', 500);
}

$ok = true;
for ($i = 0; $i < $chunks; $i++) {
    $p = $dir . '/' . $i . '.part';
    $in = @fopen($p, 'rb');
    if (!$in) {
        $ok = false;
        break;
    }
    if (stream_copy_to_stream($in, $out) === false) {
        fclose($in);
        $ok = false;
        break;
    }
    fclose($in);
}
fflush($out);
fclose($out);

if (!$ok || (int) @filesize($tmpFinal) !== $size) {
    @unlink($tmpFinal);
    @flock($lockFp, LOCK_UN);
    fclose($lockFp);
    json_fail('چسباندن تکه‌ها ناموفق بود. لطفاً دوباره تلاش کنید.', 500);
}

if (!@rename($tmpFinal, $finalPath)) {
    @unlink($tmpFinal);
    @flock($lockFp, LOCK_UN);
    fclose($lockFp);
    json_fail('انتقال فایل نهایی ناموفق بود.', 500);
}
@chmod($finalPath, 0644);

// نوع فایل: ترکیبی از تشخیص محتوا و پسوند
$mime = detect_mime($finalPath, $name, (string) ($meta['mime'] ?? ''));

// چک‌سام فایل‌های کوچک (برای جلوگیری از آپلود تکراری)
$hashMax = (int) cfg('hash_max_bytes', 0);
$md5 = null;
if ($hashMax > 0 && $size <= $hashMax) {
    $md5 = @md5_file($finalPath) ?: null;
}

$record = register_file([
    'id'            => $fileId,
    'name'          => $name,
    'stored'        => $stored,
    'size'          => $size,
    'mime'          => $mime,
    'ext'           => file_ext($name),
    'uploaded'      => time(),
    'ip_hash'       => substr(hash('sha256', client_ip()), 0, 12),
    'downloads'     => 0,
    'md5'           => $md5,
    'delete_key'    => random_id(20),
    'chunked'       => $chunks > 1,
]);

// پاکسازی نشست
@unlink($dir . '/.finish.lock');
@flock($lockFp, LOCK_UN);
fclose($lockFp);
remove_dir($dir);

app_log('upload ' . $fileId . ' ' . $name . ' ' . human_size($size) . ' ' . $mime);

json_out([
    'ok'    => true,
    'file'  => [
        'id'          => $record['id'],
        'name'        => $record['name'],
        'size'        => $record['size'],
        'size_text'   => human_size($record['size']),
        'mime'        => $record['mime'],
        'uploaded'    => $record['uploaded'],
        'downloads'   => 0,
        'url'         => file_url($record),
        'page_url'    => file_page_url($record),
        'delete_key'  => (bool) cfg('allow_delete', true) ? $record['delete_key'] : null,
        'inline'      => is_inline_mime((string) $record['mime']),
        'extension'   => $record['ext'],
        'md5'         => $record['md5'],
    ],
]);
