<?php
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
if (str_starts_with($path, '/storage/')) {
    http_response_code(404);
    exit;
}
if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}
if ($path === '/rewrite-check') {
    $_SERVER['PATH_INFO'] = $path;
    require __DIR__ . '/index.php';
    return true;
}
if ($path !== '/') {
    $dir = realpath(__DIR__ . rtrim($path, '/'));
    $idx = $dir === false ? false : realpath($dir . '/index.php');
    if ($idx !== false && strpos($idx, realpath(__DIR__) . DIRECTORY_SEPARATOR) === 0) {
        require $idx;
        return true;
    }
    if (!is_dir(__DIR__ . $path) && !isset($_GET['s']) && $path !== '/index.php') {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>404</title>'
            . '<div style="font:16px/1.7 system-ui,sans-serif;max-width:560px;margin:90px auto;padding:0 20px">'
            . '<h2 style="font-size:20px;margin:0 0 8px">页面不存在</h2>'
            . '<p style="color:#5b6270;margin:0">这个地址没有对应的页面。</p></div>';
        exit;
    }
}
require __DIR__ . '/index.php';
