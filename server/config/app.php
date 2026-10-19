<?php

return [
    'app_namespace'    => '',
    'with_route'       => true,
    'default_app'      => 'api',
    'default_timezone' => 'UTC', // 数据库统一存储 UTC，按用户时区展示

    // URL 前缀 => 应用目录
    'app_map'          => [
        'api'      => 'api',
        'adminapi' => 'admin',
        'bot'      => 'bot',
    ],
    'domain_bind'      => [],
    'deny_app_list'    => ['common'],

    'exception_tmpl'   => app()->getThinkPath() . 'tpl/think_exception.tpl',
    'error_message'    => 'Server error',
    'show_error_msg'   => false,

    // 业务配置
    'app_url'          => rtrim((string) env('APP_URL', ''), '/'),
    'app_key'          => env('APP_KEY', ''),
    'locales'          => ['zh-cn', 'en'],
    'default_user_timezone' => env('DEFAULT_TIMEZONE', 'Asia/Shanghai'),
];
