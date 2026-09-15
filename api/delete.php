<?php
/**
 * api/delete.php — حذف فایل با کلید مخفی
 * POST JSON: { id, key }
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
header('Cache-Control: no-store');

if (!cfg('allow_delete', true)) {
    json_fail('حذف فایل غیرفعال است.', 403);
}

$in  = request_json();
$id  = preg_replace('/[^a-z0-9]/i', '', (string) ($in['id'] ?? param('id', '')));
$key = (string) ($in['key'] ?? param('key', ''));

if ($id === '' || $key === '') {
    json_fail('شناسه یا کلید حذف نامعتبر است.');
}

$f = find_file($id);
if (!$f) {
    json_fail('فایل پیدا نشد.', 404);
}
$storedKey = (string) ($f['delete_key'] ?? '');
if ($storedKey === '' || !hash_equals($storedKey, $key)) {
    app_log('delete-denied ' . $id);
    json_fail('کلید حذف درست نیست.', 403);
}

delete_file_record($id);
app_log('delete ' . $id . ' ' . ($f['name'] ?? ''));

json_out(['ok' => true, 'id' => $id]);
