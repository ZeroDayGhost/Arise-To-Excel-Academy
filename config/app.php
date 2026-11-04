<?php
// Shared settings and helpers for the XAMPP fee management system.
if (!defined('APP_SESSION_IDLE_TIMEOUT')) {
    define('APP_SESSION_IDLE_TIMEOUT', 300);
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) (14 * 24 * 60 * 60));
    $httpsEnabled = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $httpsEnabled,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!empty($_SESSION['admin_id'])) {
    $lastActivityAt = (int) ($_SESSION['last_activity_at'] ?? 0);
    if ($lastActivityAt > 0 && time() - $lastActivityAt >= APP_SESSION_IDLE_TIMEOUT) {
        $_SESSION = ['_session_expired' => true];
        session_regenerate_id(true);
    } elseif ($lastActivityAt <= 0) {
        $_SESSION['last_activity_at'] = time();
    }
}

date_default_timezone_set('Africa/Nairobi');

$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = preg_replace('#/(?:admin|parent)$#', '', $scriptDirectory);
$basePath = ($basePath === '/' || $basePath === '\\') ? '' : rtrim($basePath, '/');

if ($basePath !== '') {
    $segments = explode('/', trim($basePath, '/'));
    $segments = array_map(function ($segment) {
        return rawurlencode(rawurldecode($segment));
    }, $segments);
    $basePath = '/' . implode('/', $segments);
}

define('APP_NAME', 'Arise To Excel Fee Management');
define('SCHOOL_NAME', 'Arise To Excel Academy');
define('SCHOOL_ADDRESS', 'P.O Box 00629, Nairobi, Kenya');
define('SCHOOL_PHONE', '+254 116 004005');
define('SCHOOL_EMAIL', 'arisetoexcel@gmail.com');
define('BASE_URL', $basePath);

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function asset_version(string $path): string
{
    $cleanPath = ltrim($path, '/');
    $filePath = dirname(__DIR__) . '/assets/' . $cleanPath;
    $version = is_file($filePath) ? filemtime($filePath) : time();

    return asset($cleanPath) . '?v=' . rawurlencode((string) $version);
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function money(float $amount): string
{
    return 'KES ' . number_format($amount, 2);
}

function time_of_day_greeting(?int $hour = null): string
{
    $hour = $hour ?? (int) date('G');

    if ($hour >= 5 && $hour < 12) {
        return 'Good morning';
    }

    if ($hour >= 12 && $hour < 17) {
        return 'Good afternoon';
    }

    return 'Good evening';
}

function redirect(string $path): void
{
    $basePath = rtrim(BASE_URL, '/');
    $pathOnly = parse_url($path, PHP_URL_PATH) ?: $path;
    $alreadyPrefixed = $basePath !== ''
        && ($pathOnly === $basePath || str_starts_with($pathOnly, $basePath . '/'));

    header('Location: ' . ($alreadyPrefixed ? $path : url($path)));
    exit;
}

function is_admin_logged_in(): bool
{
    return isset($_SESSION['admin_id']);
}

function app_session_record_activity(): void
{
    if (is_admin_logged_in()) {
        $_SESSION['last_activity_at'] = time();
        if (empty($_SESSION['_activity_nonce'])) {
            $_SESSION['_activity_nonce'] = bin2hex(random_bytes(16));
        }
    }
}

function app_destroy_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        $options = [
            'expires' => time() - 42000,
            'path' => $cookie['path'] ?? '/',
            'secure' => (bool) ($cookie['secure'] ?? false),
            'httponly' => (bool) ($cookie['httponly'] ?? true),
            'samesite' => $cookie['samesite'] ?? 'Lax',
        ];
        if (!empty($cookie['domain'])) {
            $options['domain'] = $cookie['domain'];
        }
        setcookie(session_name(), '', $options);
    }
    session_destroy();
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrf_require_valid_request(): void
{
    $submittedToken = $_POST['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($submittedToken) || !hash_equals(csrf_token(), $submittedToken)) {
        http_response_code(419);
        if (stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
            || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Your session expired. Refresh the page and try again.']);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Your session expired. Refresh the page and try again.';
        }
        exit;
    }
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }

    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $message = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $message;
}
