<?php
/**
 * api/abort.php — لغو یک آپلود نیمه‌کاره و پاک کردن تکه‌ها
 * POST JSON: { upload_id }
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
header('Cache-Control: no-store');

$uploadId = preg_replace('/[^a-z0-9]/i', '', (string) param('upload_id', param('u', '')));
if ($uploadId === '') {
    json_fail('شناسه‌ی آپلود نامعتبر است.');
}

$dir = session_dir($uploadId);
$existed = is_dir($dir);
if ($existed) {
    remove_dir($dir);
    app_log('abort ' . $uploadId);
}

json_out(['ok' => true, 'removed' => $existed]);
