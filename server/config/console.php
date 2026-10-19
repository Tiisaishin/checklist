<?php
// 控制台配置
return [
    'commands' => [
        \app\common\command\Install::class,
        \app\common\command\Schedule::class,
        \app\common\command\Detect::class,
        \app\common\command\TelegramPoll::class,
    ],
];
