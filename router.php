<?php
/**
 * router.php — روتر برای اجرا با سرور توکار PHP یا هر جایی که .htaccess پشتیبانی نمی‌شود.
 *
 *   php -S 0.0.0.0:8080 router.php
 *
 * آدرس‌های /d/xxxx و /f/xxxx را به اسکریپت‌های مربوطه می‌فرستد و بقیه را به فایل‌های
 * واقعی یا پنل اصلی پاس می‌دهد.
 */
declare(strict_types=1);

$root = __DIR__;
$uri  = urldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$uri  = '/' . ltrim($uri, '/');

// پوشه‌های داخلی هرگز نباید مستقیم سرو شوند
if (preg_match('#^/(inc|storage)(/|$)#i', $uri)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'دسترسی به این مسیر بسته است.';
    return true;
}

// لینک دانلود کوتاه: /d/ab12cd34  →  download.php
if (preg_match('#^/d/([a-zA-Z0-9]+)/?$#', $uri, $m)) {
    $_GET['id'] = $m[1];
    require $root . '/download.php';
    return true;
}

// صفحه‌ی فایل: /f/ab12cd34  →  file.php
if (preg_match('#^/f/([a-zA-Z0-9]+)/?$#', $uri, $m)) {
    $_GET['id'] = $m[1];
    require $root . '/file.php';
    return true;
}

$path = realpath($root . $uri);

// فایل‌های واقعی داخل پوشه‌ی پروژه (css/js/font/api/...) را خود سرور بدهد
$insideRoot = $path && str_starts_with($path, rtrim($root, '/') . DIRECTORY_SEPARATOR);
if ($insideRoot && is_file($path) && $path !== __FILE__) {
    return false;
}

// پوشه‌ها: index.php داخلشان را اجرا کن
if ($path && is_dir($path)) {
    if (is_file($path . '/index.php')) {
        require $path . '/index.php';
        return true;
    }
    if (is_file($path . '/index.html')) {
        return false;
    }
}

// پیش‌فرض: پنل اصلی
require $root . '/index.php';
return true;
