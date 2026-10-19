<?php
use app\common\middleware\Auth;
use app\common\middleware\SuperAdmin;
use think\facade\Route;

// 平台超级管理后台接口（URL 前缀 /adminapi）
Route::group(function () {
    Route::get('stats', 'Dashboard/index');

    Route::get('users', 'User/index');
    Route::put('users/:id', 'User/update');

    Route::get('tenants', 'Tenant/index');
    Route::put('tenants/:id', 'Tenant/update');

    Route::get('plans', 'Plan/index');
    Route::post('plans', 'Plan/save');
    Route::put('plans/:id', 'Plan/update');
    Route::delete('plans/:id', 'Plan/delete');

    Route::get('settings', 'Setting/index');
    Route::put('settings', 'Setting/update');
})->middleware([Auth::class, SuperAdmin::class]);

Route::miss(fn () => json(['code' => 404, 'message' => 'Not Found'], 404));
