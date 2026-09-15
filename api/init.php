<?php
/**
 * api/init.php — شروع یا ادامه‌ی یک آپلود تکه‌تکه
 * POST JSON: { name, size, mime, chunks, fingerprint, last_modified }
 * پاسخ: { ok, upload_id, chunk_size, resume_from, received_bytes }
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_fail('فقط درخواست POST مجاز است.', 405);
}

$in = request_json();

$name  = clean_filename((string) ($in['name'] ?? ''));
$size  = (int) ($in['size'] ?? 0);
$mime  = (string) ($in['mime'] ?? '');
$fp    = substr((string) ($in['fingerprint'] ?? ''), 0, 128);

$maxFile = (int) cfg('max_file_size', 2 * 1024 * 1024 * 1024);
if ($name === '') {
    json_fail('نام فایل نامعتبر است.');
}
if ($size <= 0) {
    json_fail('فایل خالی است.');
}
if ($size > $maxFile) {
    json_fail('حجم فایل بیشتر از حد مجاز است (حداکثر ' . human_size($maxFile) . ').', 413);
}

// پسوندهای کاملاً ممنوع
$denied = array_map('strtolower', (array) cfg('denied_extensions', []));
if ($denied && in_array(file_ext($name), $denied, true)) {
    json_fail('این نوع فایل اجازه‌ی آپلود ندارد.');
}

// فضای دیسک
if (!has_free_space($size)) {
    json_fail('فضای کافی روی سرور وجود ندارد.', 507);
}

// سقف کل فضای ذخیره‌سازی
$maxStorage = (int) cfg('max_storage_bytes', 0);
if ($maxStorage > 0 && (total_storage_used() + $size) > $maxStorage) {
    json_fail('ظرفیت ذخیره‌سازی سرور پر شده است.', 507);
}

// محدودیت تعداد آپلود در ساعت
if (!rate_limit_ok()) {
    json_fail('تعداد آپلودهای شما بیش از حد مجاز در این ساعت بوده است. کمی بعد تلاش کنید.', 429);
}

// حجم تکه: پیشنهاد مرورگر، محدود به سقف امن سرور
$maxChunk  = max(256 * 1024, (int) server_limits()['max_chunk']);
$chunkHint = (int) ($in['chunk_size'] ?? 0);
$chunkSize = $chunkHint > 0 ? max(256 * 1024, min($chunkHint, $maxChunk)) : $maxChunk;
$chunks = (int) ceil($size / $chunkSize);

$ipHash = substr(hash('sha256', client_ip() . '|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 16);

// اگر نشستی با همین اثر انگشت وجود دارد، ادامه بده (آپلود قابل ادامه پس از قطعی)
$existingId = null;
if ($fp !== '') {
    $base = storage_path('tmp');
    foreach (scandir($base) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || !is_dir($base . '/' . $entry)) {
            continue;
        }
        $meta = session_read($entry);
        if (!$meta) {
            continue;
        }
        if (($meta['fingerprint'] ?? '') === $fp
            && (int) ($meta['size'] ?? 0) === $size
            && ($meta['ip_hash'] ?? '') === $ipHash
            && (time() - (int) ($meta['updated'] ?? 0)) < (int) cfg('session_ttl', 86400)) {
            $existingId = $entry;
            break;
        }
    }
}

if ($existingId !== null) {
    $meta = session_read($existingId) ?? [];
    $received = session_received_bytes($existingId);
    json_out([
        'ok'           => true,
        'resumed'      => true,
        'upload_id'    => $existingId,
        'chunk_size'   => (int) ($meta['chunk_size'] ?? $chunkSize),
        'chunks'       => (int) ($meta['chunks'] ?? $chunks),
        'resume_from'  => first_missing_chunk($existingId, (int) ($meta['chunks'] ?? $chunks)),
        'received_bytes' => $received,
    ]);
}

// نشست جدید
$uploadId = random_id(12);
// پاکسازی نشست‌های قدیمی به‌صورت دوره‌ای
if (random_int(1, 20) === 1) {
    cleanup_sessions();
}

$meta = [
    'id'          => $uploadId,
    'name'        => $name,
    'size'        => $size,
    'mime'        => substr($mime, 0, 120),
    'chunks'      => $chunks,
    'chunk_size'  => $chunkSize,
    'fingerprint' => $fp,
    'ip_hash'     => $ipHash,
    'created'     => time(),
    'updated'     => time(),
];

if (!session_write($uploadId, $meta)) {
    json_fail('امکان ساخت پوشه‌ی موقت روی سرور نیست. دسترسی پوشه storage بررسی شود.', 500);
}
app_log('init ' . $uploadId . ' ' . $name . ' (' . $size . ' bytes)');

json_out([
    'ok'             => true,
    'resumed'        => false,
    'upload_id'      => $uploadId,
    'chunk_size'     => $chunkSize,
    'chunks'         => $chunks,
    'resume_from'    => 0,
    'received_bytes' => 0,
]);
