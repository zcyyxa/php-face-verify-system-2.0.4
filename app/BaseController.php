<?php
declare(strict_types=1);

namespace app;

use think\App;

abstract class BaseController
{
    protected App $app;
    protected \think\Request $request;

    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $app->request;
    }
}
