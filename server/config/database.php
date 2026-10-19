<?php

return [
    'default'         => env('DB_DRIVER', 'mysql'),
    'time_query_rule' => [],
    'auto_timestamp'  => 'datetime',
    'datetime_format' => 'Y-m-d H:i:s',
    'datetime_field'  => '',

    'connections'     => [
        'mysql'  => [
            'type'            => 'mysql',
            'hostname'        => env('DB_HOST', '127.0.0.1'),
            'database'        => env('DB_NAME', 'checklist'),
            'username'        => env('DB_USER', 'root'),
            'password'        => env('DB_PASS', ''),
            'hostport'        => env('DB_PORT', '3306'),
            'params'          => [],
            'charset'         => env('DB_CHARSET', 'utf8mb4'),
            'prefix'          => env('DB_PREFIX', ''),
            'deploy'          => 0,
            'rw_separate'     => false,
            'master_num'      => 1,
            'slave_no'        => '',
            'fields_strict'   => true,
            'break_reconnect' => true,
            'trigger_sql'     => env('APP_DEBUG', false),
            'fields_cache'    => false,
        ],
        // 本地开发/测试使用
        'sqlite' => [
            'type'          => 'sqlite',
            'database'      => env('DB_NAME', root_path() . 'runtime/checklist.sqlite'),
            'hostname'      => '',
            'username'      => '',
            'password'      => '',
            'hostport'      => '',
            'charset'       => 'utf8',
            'prefix'        => '',
            'suffix'        => '',
            'deploy'        => 0,
            'fields_strict' => true,
            'trigger_sql'   => env('APP_DEBUG', false),
            'fields_cache'  => false,
        ],
    ],
];
