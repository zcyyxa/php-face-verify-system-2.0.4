<?php
use think\App;

$reqPath = rtrim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
if ($reqPath !== '' && preg_match('~/rewrite-check$~', $reqPath)) {
    $via = '';
    if (isset($_GET['s']) && is_string($_GET['s'])) {
        $via = $_GET['s'];
    } elseif (isset($_SERVER['PATH_INFO'])) {
        $via = (string)$_SERVER['PATH_INFO'];
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo trim($via, '/') === 'rewrite-check' ? 'ok' : 'bad';
    exit;
}

if (!isset($_GET['s']) && empty($_SERVER['PATH_INFO'])) {
    $p = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $p = trim($p, '/');
    $base = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    if ($base !== '' && ('/' . $p === $base || strpos('/' . $p, $base . '/') === 0)) {
        $p = ltrim(substr('/' . $p, strlen($base)), '/');
    }
    if ($p !== '' && $p !== 'index.php') {
        $_GET['s'] = '/' . $p;
    }
}

if (!is_file(__DIR__ . '/storage/install.lock')) {
    header('Location: /install/');
    exit;
}

if (!is_file(dirname(__DIR__) . '/.env')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>缺少配置</title>'
        . '<div style="font:16px/1.7 system-ui,\'Microsoft YaHei\',sans-serif;max-width:560px;margin:90px auto;padding:0 20px">'
        . '<h2 style="font-size:20px;margin:0 0 8px">缺少数据库配置</h2>'
        . '<p style="color:#5b6270;margin:0">项目根目录的 .env 不在。把上次的 .env 放回来，或者删掉 public/storage/install.lock 重新安装。</p></div>';
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new App();

try {
    \app\service\Upgrade::settle();
} catch (\Throwable $ex) {
}
$http = $app->http;
$response = $http->run();
$response->send();
$http->end($response);
