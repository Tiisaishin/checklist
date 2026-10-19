<?php
use think\facade\Route;

// Telegram Webhook：平台 Bot / 用户自建 Bot
Route::post('telegram/system', 'Telegram/system');
Route::post('telegram/:id', 'Telegram/channel')->pattern(['id' => '\d+']);

Route::miss(fn () => json(['code' => 404, 'message' => 'Not Found'], 404));
