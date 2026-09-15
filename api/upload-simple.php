<?php
/**
 * api/upload-simple.php — آپلود معمولی (multipart) بدون تکه‌تکه کردن
 * برای مرورگرهای بدون جاوااسکریپت یا سرورهایی که آپلود تکه‌ای ندارند.
 * POST multipart: file[]  (چند فایل مجاز است)
 * اگر درخواست از نوع XHR باشد JSON برمی‌گرداند، در غیر این صورت به پنل ریدایرکت می‌شود.
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';

/** ریدایرکت به پنل همراه با پیام (برای حالت بدون جاوااسکریپت) */
function redirect_with_message(string $type, string $message): void
{
    $url = base_url() . '/?up=' . urlencode($type) . '&msg=' . urlencode($message);
    if (!headers_sent()) {
        header('Location: ' . $url, true, 302);
    } else {
        echo '<script>location.href=' . json_encode($url) . ';</script>';
    }
    exit;
}

$isXhr = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') || (($_GET['json'] ?? '') === '1');

$files = $_FILES['file'] ?? $_FILES['files'] ?? null;
if (!$files || !isset($files['name'])) {
    $isXhr ? json_fail('فایلی ارسال نشد.')
        : redirect_with_message('error', 'فایلی انتخاب نشد.');
}

// نرمال‌سازی ساختار آرایه‌ای $_FILES
$items = [];
if (is_array($files['name'])) {
    foreach ($files['name'] as $i => $n) {
        $items[] = [
            'name'     => (string) $n,
            'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
            'error'    => (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'size'     => (int) ($files['size'][$i] ?? 0),
            'type'     => (string) ($files['type'][$i] ?? ''),
        ];
    }
} else {
    $items[] = [
        'name'     => (string) $files['name'],
        'tmp_name' => (string) $files['tmp_name'],
        'error'    => (int) $files['error'],
        'size'     => (int) $files['size'],
        'type'     => (string) $files['type'],
    ];
}

$maxFile = (int) cfg('max_file_size', 2 * 1024 * 1024 * 1024);
$denied  = array_map('strtolower', (array) cfg('denied_extensions', []));
$storedResults = [];
$errors = [];

foreach (array_slice($items, 0, (int) cfg('max_files_per_batch', 50)) as $item) {
    $displayName = clean_filename(basename(str_replace('\\', '/', $item['name'])));

    if ($item['error'] === UPLOAD_ERR_INI_SIZE || $item['error'] === UPLOAD_ERR_FORM_SIZE) {
        $errors[] = $displayName . ': حجم فایل از حد مجاز تنظیمات PHP بیشتر است.';
        continue;
    }
    if ($item['error'] === UPLOAD_ERR_PARTIAL) {
        $errors[] = $displayName . ': آپلود ناقص ماند.';
        continue;
    }
    if ($item['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($item['tmp_name'])) {
        $errors[] = $displayName . ': آپلود ناموفق بود.';
        continue;
    }
    if ($item['size'] > $maxFile) {
        $errors[] = $displayName . ': حجم بیشتر از حد مجاز (' . human_size($maxFile) . ') است.';
        continue;
    }
    if ($denied && in_array(file_ext($displayName), $denied, true)) {
        $errors[] = $displayName . ': این نوع فایل مجاز نیست.';
        continue;
    }
    if (!rate_limit_ok()) {
        $errors[] = $displayName . ': تعداد آپلود در ساعت بیش از حد مجاز است.';
        continue;
    }

    try {
        $rec = store_uploaded_tempfile($item['tmp_name'], $displayName, $item['type']);
        $storedResults[] = [
            'id'         => $rec['id'],
            'name'       => $rec['name'],
            'size'       => $rec['size'],
            'size_text'  => human_size((int) $rec['size']),
            'mime'       => $rec['mime'],
            'url'        => file_url($rec),
            'page_url'   => file_page_url($rec),
            'delete_key' => (bool) cfg('allow_delete', true) ? $rec['delete_key'] : null,
            'inline'     => is_inline_mime((string) $rec['mime']),
            'uploaded'   => $rec['uploaded'],
            'downloads'  => 0,
            'extension'  => $rec['ext'],
        ];
        app_log('upload-simple ' . $rec['id'] . ' ' . $rec['name'] . ' ' . human_size((int) $rec['size']));
    } catch (Throwable $e) {
        $errors[] = $displayName . ': ذخیره‌سازی ناموفق بود.';
    }
}

if ($isXhr) {
    json_out(['ok' => (bool) $storedResults, 'files' => $storedResults, 'errors' => $errors]);
}

// حالت بدون جاوااسکریپت: ریدایرکت به پنل با پیام
$msg = count($storedResults) . ' فایل آپلود شد';
if ($errors) {
    $msg .= ' — خطاها: ' . implode(' | ', $errors);
}
redirect_with_message('info', $msg);
