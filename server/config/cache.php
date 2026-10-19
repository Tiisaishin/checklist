<?php

return [
    'default' => env('CACHE_DRIVER', 'file'),

    'stores'  => [
        'file'  => [
            'type'       => 'File',
            'path'       => '',
            'prefix'     => '',
            'expire'     => 0,
            'tag_prefix' => 'tag:',
            'serialize'  => [],
        ],
        'redis' => [
            'type'     => 'redis',
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'port'     => (int) env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', ''),
            'select'   => 1,
            'prefix'   => 'cl:',
            'expire'   => 0,
        ],
    ],
];
