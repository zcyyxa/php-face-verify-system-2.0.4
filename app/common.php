<?php

require_once dirname(__DIR__) . '/version.php';

defined('LIVE_TTL_MIN') or define('LIVE_TTL_MIN', 30);
defined('LIVE_TTL_MAX') or define('LIVE_TTL_MAX', 300);
defined('LIVE_TTL_DEFAULT') or define('LIVE_TTL_DEFAULT', 300);

defined('FACE_DIR_REL') or define('FACE_DIR_REL', 'uploads/face');
defined('LIVE_PAGE') or define('LIVE_PAGE', 'live');

function _f6(): string
{
    return rtrim(str_replace('\\', '/', app()->getRootPath()), '/');
}

function _f28(): string
{
    return _f6() . '/public';
}

function _f34(): string
{
    return _f28() . '/storage';
}

function _f19(): bool
{
    return is_file(_f34() . '/install.lock');
}

function _f2(): string
{
    return '/';
}

function _f3(): string
{
    return '/';
}

function _f32(): string
{
    try {
        $s = trim((string)\app\service\Settings::get('site_url', ''));
    } catch (\Throwable $ex) {
        $s = '';
    }
    if ($s !== '' && preg_match('~^https?://~i', $s)) {
        return rtrim($s, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        $host = 'localhost';
    }
    return $scheme . '://' . $host;
}

function _f1(string $path): string
{
    return _f32() . '/' . ltrim($path, '/');
}

function _f23(): string
{
    return _f1(LIVE_PAGE);
}

function _f14($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function _f9(): string
{
    $cands = [];
    $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($xff !== '') {
        foreach (explode(',', $xff) as $one) {
            $cands[] = trim($one);
        }
    }
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $cands[] = trim((string)$_SERVER[$k]);
        }
    }
    foreach ($cands as $ip) {
        $ip = (string)preg_replace('~^\[|\]$~', '', $ip);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '';
}

function _f31(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

function _f16(): string
{
    return date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(6)));
}

function _f26(string $s, int $n = 4): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    if (strlen($s) <= $n) {
        return str_repeat('*', strlen($s));
    }
    return '****' . substr($s, -$n);
}

function _f10(string $s, int $max = 65536): string
{
    if (strlen($s) <= $max) {
        return $s;
    }
    return substr($s, 0, $max) . "\n…（超过 " . $max . " 字节，已截断）";
}

function _f37(string $s): bool
{
    return trim($s) !== '' && mb_strlen(trim($s), 'UTF-8') <= 30;
}

function _f36(string $s): bool
{
    return (bool)preg_match('~^\d{17}[\dXx]$~', trim($s));
}

function _f35(string $s): bool
{
    return (bool)preg_match('~^https?://~i', $s);
}

function need_upgrade(): bool
{
    try {
        return (string)\app\service\Settings::get('db_ver', '') !== APP_VERSION;
    } catch (\Throwable $ex) {
        return true;
    }
}


function _f5(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name((string)env('SESSION_NAME', 'SFRID1'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

function _f12(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = _f31(16);
    }
    return (string)$_SESSION['csrf'];
}

function _f11(): bool
{
    $got = (string)($_POST['csrf'] ?? $_GET['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $has = (string)($_SESSION['csrf'] ?? '');
    return $has !== '' && $got !== '' && hash_equals($has, $got);
}

function _f20(): bool
{
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
}


function _f4(): ?array
{
    static $loaded = false;
    static $row = null;
    if ($loaded) {
        return $row;
    }
    $loaded = true;
    $id = (int)($_SESSION['aid'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    try {
        $r = \think\facade\Db::name('admins')->where('id', $id)->find();
    } catch (\Throwable $ex) {
        $r = null;
    }
    if (!is_array($r)) {
        unset($_SESSION['aid']);
        return null;
    }
    $row = $r;
    return $row;
}

function _f24(array $a): void
{
    session_regenerate_id(true);
    $_SESSION['aid']  = (int)$a['id'];
    $_SESSION['csrf'] = _f31(16);
}

function _f25(): void
{
    unset($_SESSION['aid']);
    $_SESSION['csrf'] = _f31(16);
}

function _f29(string $p): string
{
    return password_hash($p, PASSWORD_DEFAULT);
}

function _f30(string $p, string $hash): bool
{
    return $hash !== '' && password_verify($p, $hash);
}


function _f21(int $http, array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function _f22(int $http, array $payload): \think\Response
{
    return json($payload, $http, [
        'Cache-Control'          => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
    ]);
}

function _f7(int $http, string $title, string $body, string $state = 'error'): \think\Response
{
    $t = _f14($title);
    $b = $body;
    $s = _f14($state);
    $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . $t . '</title>'
        . '<style>*{box-sizing:border-box}html,body{height:100%}'
        . 'body{margin:0;background:#f4f6fb;color:#172033;font:16px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",system-ui,sans-serif}'
        . '.lvw{min-height:100%;display:flex;align-items:center;justify-content:center;padding:20px}'
        . '.lvb{width:100%;max-width:380px;background:#fff;border:1px solid #e3e8f0;border-radius:10px;padding:32px 24px;text-align:center}'
        . '.lvb h1{font-size:22px;line-height:1.35;margin:0;font-weight:650;color:#172033}'
        . '.lvb p{margin:10px 0 0;color:#68738a;font-size:14px;overflow-wrap:anywhere}'
        . '.lvb[data-state="pass"] h1{color:#15803d}.lvb[data-state="fail"] h1{color:#c02525}'
        . '.lvb[data-state="wait"] h1{color:#2f5cf6}.lvb[data-state="warn"] h1{color:#b45309}'
        . '@media (max-width:420px){.lvw{padding:16px}.lvb{padding:28px 20px}}</style></head><body>'
        . '<main class="lvw"><div class="lvb" data-state="' . $s . '">' . $b . '</div></main></body></html>';
    return response($html, $http, [
        'Content-Type'           => 'text/html; charset=utf-8',
        'Cache-Control'          => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Referrer-Policy'        => 'no-referrer',
    ]);
}
