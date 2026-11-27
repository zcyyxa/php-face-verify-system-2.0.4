<?php
namespace app;

use think\exception\Handle;
use think\Response;
use Throwable;

class ExceptionHandle extends Handle
{
    public function render($request, Throwable $e): Response
    {
        $path = strtolower(trim((string)$request->pathinfo(), '/'));
        error_log('[app] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

        if (str_starts_with($path, 'api/')) {
            return json_response(500, [
                'code'    => 500,
                'msg'     => '服务出了点问题，稍后再试。',
                'data'    => (object)[],
                'trace'   => '',
                'cost'    => 0,
                'balance' => null,
            ]);
        }
        if ($path === 'live' || $path === 'live.php') {
            return bare_html(503, '出了点问题', '<h1>出了点问题</h1>', 'warn');
        }
        return parent::render($request, $e);
    }
}
