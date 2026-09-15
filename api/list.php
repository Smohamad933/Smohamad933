<?php
/**
 * api/list.php — لیست فایل‌های اخیر
 * GET ?limit=12
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
header('Cache-Control: no-store');

$limit = (int) ($_GET['limit'] ?? cfg('recent_limit', 12));
$limit = max(1, min(100, $limit));

$out = [];
foreach (recent_files($limit) as $f) {
    $out[] = [
        'id'         => $f['id'],
        'name'       => $f['name'],
        'size'       => (int) $f['size'],
        'size_text'  => human_size((int) $f['size']),
        'mime'       => $f['mime'] ?? 'application/octet-stream',
        'uploaded'   => (int) ($f['uploaded'] ?? 0),
        'downloads'  => (int) ($f['downloads'] ?? 0),
        'url'        => file_url($f),
        'page_url'   => file_page_url($f),
        'inline'     => is_inline_mime((string) ($f['mime'] ?? '')),
        'extension'  => $f['ext'] ?? '',
    ];
}

json_out([
    'ok'    => true,
    'files' => $out,
    'stats' => [
        'count' => count($out),
        'used'  => total_storage_used(),
        'used_text' => human_size(total_storage_used()),
    ],
]);
