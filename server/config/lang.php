<?php

return [
    'default_lang'        => env('DEFAULT_LANG', 'zh-cn'),
    'auto_detect_browser' => true,
    'allow_lang_list'     => ['zh-cn', 'en'],
    'detect_var'          => 'lang',
    'use_cookie'          => false,
    'cookie_var'          => 'think_lang',
    'header_var'          => 'think-lang',
    'extend_list'         => [
        'zh-cn' => [base_path() . 'common/lang/zh-cn.php'],
        'en'    => [base_path() . 'common/lang/en.php'],
    ],
    'accept_language'     => [
        'zh-hans-cn' => 'zh-cn',
        'zh-hans'    => 'zh-cn',
        'zh'         => 'zh-cn',
        'en-us'      => 'en',
        'en-gb'      => 'en',
    ],
    'allow_group'         => false,
];
