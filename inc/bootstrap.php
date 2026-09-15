<?php
/**
 * هسته‌ی پنل: تنظیمات، ابزارها و مدیریت ذخیره‌سازی
 * همه‌ی فایل‌های پنل از این فایل شروع می‌شوند.
 */
declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Tehran');

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

/* ------------------------------------------------------------------
 | تنظیمات
 * ----------------------------------------------------------------*/
$GLOBALS['APP_CFG'] = require __DIR__ . '/config.php';

function cfg(string $key, $default = null)
{
    $c = $GLOBALS['APP_CFG'];
    return array_key_exists($key, $c) ? $c[$key] : $default;
}

// مسیر ذخیره‌سازی (قابل تغییر با متغیر محیطی، برای تست و هاست‌های خاص)
function storage_root(): string
{
    static $root = null;
    if ($root !== null) {
        return $root;
    }
    $env = getenv('APP_STORAGE_DIR');
    if (is_string($env) && $env !== '') {
        $root = rtrim($env, '/\\');
    } else {
        $cfgDir = (string) cfg('storage_dir', '');
        $root = $cfgDir !== '' ? rtrim($cfgDir, '/\\') : APP_ROOT . '/storage';
    }
    return $root;
}

function storage_path(string ...$parts): string
{
    return storage_root() . ($parts ? '/' . implode('/', $parts) : '');
}

/* ------------------------------------------------------------------
 | ابزارهای عمومی
 * ----------------------------------------------------------------*/

/** پاسخ JSON و پایان اجرا */
function json_out(array $data, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** پاسخ خطا به شکل JSON */
function json_fail(string $message, int $code = 400, array $extra = []): void
{
    json_out(array_merge(['ok' => false, 'error' => $message], $extra), $code);
}

/** خواندن ورودی JSON بدنه‌ی درخواست */
function request_json(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    $cache = is_array($data) ? $data : [];
    return $cache;
}

/** پارامتر از POST یا GET یا JSON */
function param(string $key, $default = null)
{
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }
    $j = request_json();
    return $j[$key] ?? $default;
}

/** تبدیل حجم به متن خوانا */
function human_size($bytes): string
{
    $bytes = (float) $bytes;
    $units = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت', 'ترابایت'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    $num = $i === 0 ? number_format($bytes) : number_format($bytes, $bytes < 10 ? 2 : 1);
    return fa_num($num) . ' ' . $units[$i];
}

/** عدد فارسی */
function fa_num($n): string
{
    return str_replace(
        ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
        (string) $n
    );
}

/** آی‌پی کاربر (با در نظر گرفتن پروکسی معتبر سرور) */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return is_string($ip) ? substr($ip, 0, 45) : '0.0.0.0';
}

/** سازنده‌ی شناسه‌ی تصادفی کوتاه */
function random_id(int $len = 8): string
{
    $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/** پاکسازی نام فایل برای نمایش و ذخیره */
function clean_filename(string $name): string
{
    $name = str_replace(["\0", "\r", "\n", "\t"], '', $name);
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name;
    // کاراکترهای مشکل‌ساز در سیستم فایل / هدرها
    $name = preg_replace('/[\/\\\\:*?"<>|\']/u', '-', $name) ?? $name;
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name, " .-_");
    if ($name === '' || $name === '.' || $name === '..') {
        $name = 'file';
    }
    if (mb_strlen($name, 'UTF-8') > 180) {
        $name = mb_substr($name, 0, 180, 'UTF-8');
    }
    return $name;
}

/** پسوند فایل با حروف کوچک */
function file_ext(string $name): string
{
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $ext) ?? '');
}

/** آیا این پسوند برای ذخیره‌ی امن مناسب است؟ (در غیر این صورت .bin) */
function safe_extension(string $name): string
{
    $ext = file_ext($name);
    $blocked = array_map('strtolower', (array) cfg('blocked_extensions', []));
    if ($ext === '' || in_array($ext, $blocked, true) || strlen($ext) > 8) {
        return 'bin';
    }
    return $ext;
}

/** نوع MIME از پسوند (برای وقتی که مرورگر چیزی نمی‌فرستد) */
function mime_from_ext(string $name): string
{
    static $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'bmp' => 'image/bmp', 'avif' => 'image/avif', 'svg' => 'image/svg+xml',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mkv' => 'video/x-matroska', 'mov' => 'video/quicktime',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4',
        'pdf' => 'application/pdf', 'zip' => 'application/zip', 'rar' => 'application/vnd.rar',
        '7z' => 'application/x-7z-compressed', 'tar' => 'application/x-tar', 'gz' => 'application/gzip',
        'txt' => 'text/plain', 'csv' => 'text/csv', 'json' => 'application/json', 'xml' => 'application/xml',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'apk' => 'application/vnd.android.package-archive', 'exe' => 'application/vnd.microsoft.portable-executable',
        'iso' => 'application/x-iso9660-image', 'psd' => 'image/vnd.adobe.photoshop',
    ];
    return $map[file_ext($name)] ?? 'application/octet-stream';
}

/**
 * تشخیص نوع فایل:
 * اول محتوای واقعی فایل بررسی می‌شود؛ اگر با پسوند هم‌خوانی نداشت (مثلا فایل‌های
 * ویدیویی که finfo درست تشخیص نمی‌دهد) به پسوند معتبر اعتماد می‌کنیم.
 * نکته‌ی امنیتی: هرگز خودِ محتوا به مرورگر «اجرا» نمی‌شود؛ نمایش درون‌مرورگری
 * فقط برای انواع مجاز (عکس/ویدیو/صدا/PDF) و با هدر nosniff انجام می‌شود.
 */
function detect_mime(string $path, string $name, string $clientMime = ''): string
{
    $detected = '';
    if (extension_loaded('fileinfo') && is_file($path)) {
        $fi = @new finfo(FILEINFO_MIME_TYPE);
        if ($fi) {
            $d = @$fi->file($path);
            if (is_string($d) && $d !== '') {
                $detected = strtolower(trim(explode(';', $d)[0]));
            }
            unset($fi);
        }
    }
    $guess  = mime_from_ext($name);
    $client = strtolower(trim(explode(';', $clientMime)[0]));

    // انواع کلی که اطلاعات دقیقی از ماهیت فایل نمی‌دهند
    $generic = [
        '', 'application/octet-stream', 'application/zip', 'application/x-empty',
        'application/x-gzip', 'inode/x-empty', 'application/x-iso9660-image',
    ];

    $top = static function (string $m): string {
        return explode('/', $m)[0] ?? '';
    };

    if ($guess !== 'application/octet-stream' && $guess !== '') {
        if (in_array($detected, $generic, true) || $top($detected) !== $top($guess)) {
            return $guess;
        }
        return $detected !== '' ? $detected : $guess;
    }
    if ($detected !== '' && !in_array($detected, $generic, true)) {
        return $detected;
    }
    if ($client !== '' && $client !== 'application/octet-stream') {
        return $client;
    }
    return $detected !== '' ? $detected : 'application/octet-stream';
}

/** آیا این نوع فایل می‌تواند داخل مرورگر نمایش داده شود؟ */
function is_inline_mime(string $mime): bool
{
    $mime = strtolower(trim(explode(';', $mime)[0]));
    return in_array($mime, array_map('strtolower', (array) cfg('inline_mimes', [])), true);
}

/** آدرس پایه‌ی سایت برای ساختن لینک‌ها */
function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    // اولویت ۱: متغیر محیطی (برای محیط‌های ابری و پیش‌نمایش)
    $envBase = getenv('APP_BASE_URL');
    if (is_string($envBase) && trim($envBase) !== '') {
        return $base = rtrim(trim($envBase), '/');
    }
    // اولویت ۲: تنظیمات فایل config.php
    $configured = trim((string) cfg('base_url', ''));
    if ($configured !== '') {
        return $base = rtrim($configured, '/');
    }
    $fwdProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || $fwdProto === 'https'
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $scheme = $https ? 'https' : 'http';
    // هاست از پروکسی معتبر (برای پیش‌نمایش‌های ابری) قابل استفاده است
    $fwdHost = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0]);
    $host   = $fwdHost !== '' ? $fwdHost : ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $dir    = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    // اگر داخل پوشه‌های api/ هستیم یک پله بالا می‌آییم
    if (basename($dir) === 'api') {
        $dir = dirname($dir);
    }
    $dir = rtrim($dir, '/');
    return $base = $scheme . '://' . $host . $dir;
}

/** لینک دانلود یک فایل */
function file_url(array $f): string
{
    if (cfg('pretty_urls', true)) {
        return base_url() . '/d/' . $f['id'];
    }
    return base_url() . '/download.php?id=' . $f['id'];
}

/** لینک صفحه‌ی یک فایل */
function file_page_url(array $f): string
{
    if (cfg('pretty_urls', true)) {
        return base_url() . '/f/' . $f['id'];
    }
    return base_url() . '/file.php?id=' . $f['id'];
}

/** آدرس فایل‌های استاتیک با نسخه (برای رفع کش) */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    return base_url() . '/' . $path . '?v=' . APP_VERSION;
}

/* ------------------------------------------------------------------
 | ذخیره‌سازی: پوشه‌ها، دیتابیس JSON و نشست‌های آپلود
 * ----------------------------------------------------------------*/

/** ساخت پوشه‌ها و فایل‌های محافظ */
function ensure_storage(): void
{
    $dirs = [
        storage_root(),
        storage_path('files'),
        storage_path('tmp'),
        storage_path('data'),
    ];
    foreach ($dirs as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
    }
    // جلوگیری از دسترسی مستقیم وب به فایل‌های آپلودی
    $guard = "# دسترسی مستقیم بسته است\n"
        . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
    foreach ([storage_root(), storage_path('files'), storage_path('tmp'), storage_path('data')] as $d) {
        $ht = $d . '/.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht, $guard);
        }
    }
    $index = storage_root() . '/index.html';
    if (!file_exists($index)) {
        @file_put_contents($index, '<!doctype html><title>-</title>');
    }
}

/** خواندن دیتابیس فایل‌ها */
function db_read(): array
{
    $file = storage_path('data', 'files.json');
    if (!is_file($file)) {
        return ['files' => []];
    }
    $fp = @fopen($file, 'r');
    if (!$fp) {
        return ['files' => []];
    }
    @flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    @flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode((string) $raw, true);
    if (!is_array($data) || !isset($data['files']) || !is_array($data['files'])) {
        return ['files' => []];
    }
    return $data;
}

/** نوشتن اتمیک دیتابیس */
function db_write(array $data): bool
{
    $file = storage_path('data', 'files.json');
    $tmp  = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (@file_put_contents($tmp, (string) $json, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** تغییر اتمیک دیتابیس با قفل اختصاصی */
function db_mutate(callable $fn)
{
    $lock = storage_path('data', 'files.lock');
    $fp = @fopen($lock, 'c+');
    if (!$fp) {
        return null;
    }
    @flock($fp, LOCK_EX);
    $db = db_read();
    $result = $fn($db);
    db_write($db);
    @flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

/** جست‌وجوی یک فایل با شناسه */
function find_file(string $id): ?array
{
    $db = db_read();
    $f = $db['files'][$id] ?? null;
    return is_array($f) ? $f : null;
}

/** مسیر فیزیکی فایل ذخیره‌شده */
function stored_file_path(array $f): string
{
    return storage_path('files', (string) $f['stored']);
}

/** ثبت یک فایل جدید */
function register_file(array $meta): array
{
    $meta['id'] = $meta['id'] ?? random_id(8);
    $result = db_mutate(function (array &$db) use ($meta) {
        // محافظت از برخورد شناسه
        while (isset($db['files'][$meta['id']])) {
            $meta['id'] = random_id(8);
        }
        $db['files'][$meta['id']] = $meta;
        // مرتب‌سازی بر اساس تاریخ (جدیدترین اول) و نگه داشتن آخرین ۵۰۰ رکورد
        uasort($db['files'], fn($a, $b) => ($b['uploaded'] ?? 0) <=> ($a['uploaded'] ?? 0));
        if (count($db['files']) > 500) {
            $db['files'] = array_slice($db['files'], 0, 500, true);
        }
        return true;
    });
    return $meta;
}

/**
 * انتقال یک فایل موقت (آپلود معمولی PHP) به پوشه‌ی ذخیره‌سازی و ثبت آن
 * @return array رکورد فایل ثبت‌شده
 */
function store_uploaded_tempfile(string $tmpPath, string $name, string $clientMime = ''): array
{
    $name = clean_filename($name);
    $size = (int) @filesize($tmpPath);
    $id = random_id(8);
    $stored = $id . '.' . safe_extension($name);
    $dest = storage_path('files', $stored);

    if (!@move_uploaded_file($tmpPath, $dest) && !@rename($tmpPath, $dest)) {
        // فایل‌های آزمایشی (CLI) هم پشتیبانی شوند
        if (!@copy($tmpPath, $dest)) {
            throw new RuntimeException('انتقال فایل به پوشه‌ی ذخیره‌سازی ناموفق بود.');
        }
        @unlink($tmpPath);
    }
    @chmod($dest, 0644);

    $mime = detect_mime($dest, $name, $clientMime);

    $hashMax = (int) cfg('hash_max_bytes', 0);
    $md5 = ($hashMax > 0 && $size <= $hashMax) ? (@md5_file($dest) ?: null) : null;

    return register_file([
        'id'         => $id,
        'name'       => $name,
        'stored'     => $stored,
        'size'       => $size,
        'mime'       => $mime,
        'ext'        => file_ext($name),
        'uploaded'   => time(),
        'ip_hash'    => substr(hash('sha256', client_ip()), 0, 12),
        'downloads'  => 0,
        'md5'        => $md5,
        'delete_key' => random_id(20),
        'chunked'    => false,
    ]);
}

/** لیست فایل‌های اخیر */
function recent_files(int $limit = 12): array
{
    $db = db_read();
    $files = array_values($db['files']);
    usort($files, fn($a, $b) => ($b['uploaded'] ?? 0) <=> ($a['uploaded'] ?? 0));
    return array_slice($files, 0, $limit);
}

/** مجموع حجم فایل‌های ذخیره‌شده */
function total_storage_used(): int
{
    $db = db_read();
    $sum = 0;
    foreach ($db['files'] as $f) {
        $sum += (int) ($f['size'] ?? 0);
    }
    return $sum;
}

/** حذف کامل یک فایل (فایل فیزیکی + رکورد) */
function delete_file_record(string $id): bool
{
    $f = find_file($id);
    if (!$f) {
        return false;
    }
    $path = stored_file_path($f);
    if (is_file($path)) {
        @unlink($path);
    }
    db_mutate(function (array &$db) use ($id) {
        unset($db['files'][$id]);
        return true;
    });
    return true;
}

/** مسیر یک نشست آپلود تکه‌تکه */
function session_dir(string $uploadId): string
{
    return storage_path('tmp', preg_replace('/[^a-z0-9]/i', '', $uploadId));
}

function session_meta_path(string $uploadId): string
{
    return session_dir($uploadId) . '/meta.json';
}

function session_read(string $uploadId): ?array
{
    $p = session_meta_path($uploadId);
    if (!is_file($p)) {
        return null;
    }
    $raw = @file_get_contents($p);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : null;
}

function session_write(string $uploadId, array $meta): bool
{
    $dir = session_dir($uploadId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return false;
    }
    return @file_put_contents(session_meta_path($uploadId), json_encode($meta, JSON_UNESCAPED_UNICODE)) !== false;
}

/** حجم تکه‌های دریافت‌شده‌ی یک نشست */
function session_received_bytes(string $uploadId): int
{
    $dir = session_dir($uploadId);
    if (!is_dir($dir)) {
        return 0;
    }
    $sum = 0;
    foreach (scandir($dir) ?: [] as $entry) {
        if (substr($entry, -5) !== '.part') {
            continue;
        }
        $sum += (int) @filesize($dir . '/' . $entry);
    }
    return $sum;
}

/** شماره‌ی اولین تکه‌ی ناموجود (برای ادامه‌ی آپلود) */
function first_missing_chunk(string $uploadId, int $chunks): int
{
    $dir = session_dir($uploadId);
    if (!is_dir($dir)) {
        return 0;
    }
    for ($i = 0; $i < $chunks; $i++) {
        if (!is_file($dir . '/' . $i . '.part')) {
            return $i;
        }
    }
    return $chunks;
}

/** تعداد تکه‌های دریافت‌شده */
function session_chunk_count(string $uploadId): int
{
    $dir = session_dir($uploadId);
    if (!is_dir($dir)) {
        return 0;
    }
    $n = 0;
    foreach (scandir($dir) ?: [] as $entry) {
        if (substr($entry, -5) === '.part') {
            $n++;
        }
    }
    return $n;
}

/** پاک‌سازی نشست‌ها و نشست‌های قدیمی */
function cleanup_sessions(?int $ttl = null): int
{
    $ttl = $ttl ?? (int) cfg('session_ttl', 86400);
    $base = storage_path('tmp');
    if (!is_dir($base)) {
        return 0;
    }
    $removed = 0;
    foreach (scandir($base) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.htaccess') {
            continue;
        }
        $path = $base . '/' . $entry;
        if (!is_dir($path)) {
            continue;
        }
        $meta = @filemtime(session_meta_path($entry)) ?: @filemtime($path) ?: 0;
        if ($meta > 0 && (time() - $meta) > $ttl) {
            remove_dir($path);
            $removed++;
        }
    }
    return $removed;
}

/** حذف بازگشتی یک پوشه */
function remove_dir(string $dir): bool
{
    if (!is_dir($dir)) {
        return false;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? remove_dir($path) : @unlink($path);
    }
    return @rmdir($dir);
}

/** نوشتن در لاگ برنامه */
function app_log(string $message): void
{
    if (!cfg('enable_log', true)) {
        return;
    }
    $file = storage_path('data', 'app.log');
    $line = '[' . date('Y-m-d H:i:s') . '] ' . client_ip() . ' ' . $message . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    // هرس لاگ بزرگ
    if (@filesize($file) > 2 * 1024 * 1024) {
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        @file_put_contents($file, implode(PHP_EOL, array_slice($lines, -2000)) . PHP_EOL, LOCK_EX);
    }
}

/** محدودیت‌های واقعی سرور برای آپلود */
function server_limits(): array
{
    $toBytes = static function (string $v): int {
        $v = trim($v);
        if ($v === '') {
            return 0;
        }
        $unit = strtolower(substr($v, -1));
        $num = (float) $v;
        switch ($unit) {
            case 'g': $num *= 1024 * 1024 * 1024; break;
            case 'm': $num *= 1024 * 1024; break;
            case 'k': $num *= 1024; break;
        }
        return (int) $num;
    };
    $post   = $toBytes((string) ini_get('post_max_size'));
    $upload = $toBytes((string) ini_get('upload_max_filesize'));
    $limit = (int) cfg('max_file_size', 2 * 1024 * 1024 * 1024);
    // سقف امن درخواست‌های معمولی (multipart) بر اساس تنظیمات PHP
    $normalLimit = min(array_filter([$post, $upload, $limit]) ?: [$limit]);
    // حجم تکه با احتیاط نسبت به post_max_size انتخاب می‌شود (۸۰٪)
    $chunk = (int) cfg('chunk_size', 8 * 1024 * 1024);
    if ($post > 0) {
        $chunk = max(256 * 1024, min($chunk, (int) floor($post * 0.8)));
    }
    return [
        'post_max_size'   => $post,
        'upload_max_size' => $upload,
        'memory_limit'    => $toBytes((string) ini_get('memory_limit')),
        'max_chunk'       => $chunk,
        'normal_limit'    => $normalLimit,
        'max_file_size'   => $limit,
        'fileinfo'        => extension_loaded('fileinfo'),
        'free_space'      => @disk_free_space(storage_root()) ?: null,
    ];
}

/** بررسی محدودیت تعداد آپلود در ساعت برای هر IP */
function rate_limit_ok(): bool
{
    $max = (int) cfg('max_uploads_per_ip_per_hour', 0);
    if ($max <= 0) {
        return true;
    }
    $dir = storage_path('data', 'rate');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/' . substr(hash('sha256', client_ip()), 0, 24) . '.json';
    $fp = @fopen($file, 'c+');
    if (!$fp) {
        return true;
    }
    @flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        $data = ['window' => time(), 'count' => 0];
    }
    if (time() - (int) $data['window'] > 3600) {
        $data = ['window' => time(), 'count' => 0];
    }
    $ok = (int) $data['count'] < $max;
    $data['count'] = (int) $data['count'] + 1;
    @ftruncate($fp, 0);
    @rewind($fp);
    @fwrite($fp, json_encode($data));
    @fflush($fp);
    @flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

/** بررسی فضای آزاد کافی برای حجم مشخص */
function has_free_space(int $bytes): bool
{
    $free = @disk_free_space(storage_root());
    if ($free === false) {
        return true;
    }
    return $free > ($bytes + 50 * 1024 * 1024);
}

/** پاکسازی تصادفی (روی همه‌ی درخواست‌ها اجرا می‌شود) */
function maybe_cleanup(): void
{
    $p = max(1, (int) cfg('auto_cleanup_probability', 40));
    if (random_int(1, $p) === 1) {
        cleanup_sessions();
    }
}

/* راه‌اندازی اولیه */
ensure_storage();
if (PHP_SAPI !== 'cli') {
    maybe_cleanup();
}
