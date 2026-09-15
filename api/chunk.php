<?php
/**
 * api/chunk.php — دریافت یک تکه از فایل
 * POST خام (application/octet-stream) با پارامترهای u = شناسه‌ی آپلود و i = شماره‌ی تکه
 * بدنه‌ی درخواست مستقیم روی دیسک نوشته می‌شود (بدون مصرف حافظه)
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_fail('فقط درخواست POST مجاز است.', 405);
}

$uploadId = preg_replace('/[^a-z0-9]/i', '', (string) param('u', ''));
$index    = (int) param('i', -1);

if ($uploadId === '' || strlen($uploadId) < 6) {
    json_fail('شناسه‌ی آپلود نامعتبر است.');
}
if ($index < 0) {
    json_fail('شماره‌ی تکه نامعتبر است.');
}

$meta = session_read($uploadId);
if (!$meta) {
    json_fail('نشست آپلود پیدا نشد یا منقضی شده است.', 410, ['restart' => true]);
}
if ($index >= (int) $meta['chunks']) {
    json_fail('شماره‌ی تکه خارج از محدوده است.');
}

// اگر بدنه‌ی درخواست از post_max_size بزرگ‌تر باشد، PHP آن را نصفه تحویل می‌دهد؛
// پس بهتر است همان اول ۴۱۳ برگردانیم تا مرورگر خودش تکه را کوچک‌تر کند.
$postMax = (int) server_limits()['post_max_size'];
$declaredTotal = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($postMax > 0 && $declaredTotal > $postMax) {
    json_fail('حجم تکه از سقف مجاز سرور بیشتر است؛ با تکه‌های کوچک‌تر تلاش می‌کنیم.', 413, ['shrink' => true]);
}

$dir = session_dir($uploadId);
if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    json_fail('پوشه‌ی موقت قابل نوشتن نیست.', 500);
}

$chunkSize = (int) $meta['chunk_size'];
$fileSize  = (int) $meta['size'];
$expected  = ($index === (int) $meta['chunks'] - 1)
    ? ($fileSize - $chunkSize * ((int) $meta['chunks'] - 1))
    : $chunkSize;

$tmpPath = $dir . '/' . $index . '.part';
$in = @fopen('php://input', 'rb');
if (!$in) {
    json_fail('خواندن بدنه‌ی درخواست ناموفق بود.', 500);
}
$out = @fopen($tmpPath, 'wb');
if (!$out) {
    fclose($in);
    json_fail('نوشتن روی دیسک ناموفق بود. دسترسی پوشه‌ی storage را بررسی کنید.', 500);
}

$written = stream_copy_to_stream($in, $out);
fclose($in);
fflush($out);
fclose($out);

if ($written === false) {
    @unlink($tmpPath);
    json_fail('انتقال تکه ناموفق بود.', 500);
}

$declared = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null;
if ($declared !== null && $declared > 0 && $declared !== $written) {
    @unlink($tmpPath);
    json_fail('تکه ناقص دریافت شد (انتظار ' . $declared . ' بایت، دریافت ' . $written . ' بایت). تلاش دوباره...', 500);
}

if ($expected > 0 && $written !== $expected) {
    @unlink($tmpPath);
    json_fail('حجم تکه نامعتبر است (انتظار ' . human_size($expected) . '، دریافت ' . human_size($written) . ').', 400);
}

// به‌روزرسانی زمان آخرین فعالیت نشست
$meta['updated'] = time();
session_write($uploadId, $meta);

json_out([
    'ok'             => true,
    'index'          => $index,
    'bytes'          => $written,
    'received_bytes' => session_received_bytes($uploadId),
]);
