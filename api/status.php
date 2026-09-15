<?php
/**
 * api/status.php — اطلاعات تنظیمات و محدودیت‌های سرور برای پنل
 * GET
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';

$limits = server_limits();
$used   = total_storage_used();
$maxStorage = (int) cfg('max_storage_bytes', 0);

json_out([
    'ok' => true,
    'limits' => [
        'max_file_size' => (int) $limits['max_file_size'],
        'chunk_size'    => (int) $limits['max_chunk'],
        'max_batch'     => (int) cfg('max_files_per_batch', 50),
        'post_max_size' => (int) $limits['post_max_size'],
        'upload_max_size' => (int) $limits['upload_max_size'],
        'memory_limit'  => (int) $limits['memory_limit'],
        'fileinfo'      => (bool) $limits['fileinfo'],
        'pretty_urls'   => (bool) cfg('pretty_urls', true),
        'allow_delete'  => (bool) cfg('allow_delete', true),
        'storage_used'  => $used,
        'storage_max'   => $maxStorage,
        'free_space'    => $limits['free_space'],
    ],
    'server' => [
        'php'      => PHP_VERSION,
        'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'PHP',
    ],
    'time' => time(),
]);
