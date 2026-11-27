<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;

final class Download extends BaseController
{
    private const FILES = [
        'ysface'     => ['file' => 'ysface-plugin.zip', 'name' => 'ysface-plugin.zip'],
        'ysface_v10' => ['file' => 'ysface-v10-plugin.zip', 'name' => 'ysface-v10-plugin.zip'],
    ];

    public function index()
    {
        _f5();
        if (_f4() === null) {
            return redirect(_f3());
        }
        self::serve((string)$this->request->get('f', 'ysface'));
        exit;
    }

    public static function serve(string $key): void
    {
        if (!isset(self::FILES[$key])) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '没有这个文件。';
            return;
        }
        $file = _f34() . '/downloads/' . self::FILES[$key]['file'];
        if (!is_file($file)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '插件包还没放上来。';
            return;
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . self::FILES[$key]['name'] . '"');
        header('Content-Length: ' . (string)filesize($file));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($file);
    }
}
