<?php
return [
    'app_namespace'    => '',
    'with_route'       => true,
    'default_app'      => 'index',
    'default_timezone' => env('APP_TIMEZONE', 'Asia/Shanghai'),

    'app_map'          => [],
    'domain_bind'      => [],
    'deny_app_list'    => [],

    'exception_tmpl'   => app()->getThinkPath() . 'tpl/think_exception.tpl',

    'error_message'    => '服务出了点问题，稍后再试。',
    'show_error_msg'   => false,
];
