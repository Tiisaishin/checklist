<?php
use app\common\middleware\Auth;
use think\facade\Route;

// 公开接口
Route::get('meta', 'Meta/index');
Route::post('auth/register', 'Auth/register');
Route::post('auth/login', 'Auth/login');
Route::get('invitations/:token', 'Invitation/show')->pattern(['token' => '[a-f0-9]{40}']);

// 需要登录
Route::group(function () {
    Route::post('auth/logout', 'Auth/logout');
    Route::get('auth/me', 'Auth/me');
    Route::put('auth/profile', 'Auth/profile');
    Route::put('auth/password', 'Auth/password');

    Route::get('dashboard', 'Dashboard/index');

    Route::get('tenant', 'Tenant/current');
    Route::put('tenant', 'Tenant/update');
    Route::post('tenant/switch', 'Tenant/switch');

    Route::post('invitations/:token/accept', 'Invitation/accept')->pattern(['token' => '[a-f0-9]{40}']);

    Route::get('members', 'Member/index');
    Route::post('members/invite', 'Member/invite');
    Route::post('members/leave', 'Member/leave');
    Route::delete('members/invitations/:id', 'Member/revoke');
    Route::put('members/:id', 'Member/update');
    Route::delete('members/:id', 'Member/delete');
    Route::post('members/:id/transfer', 'Member/transfer');

    Route::get('groups', 'Group/index');
    Route::post('groups', 'Group/save');
    Route::put('groups/:id', 'Group/update');
    Route::delete('groups/:id', 'Group/delete');

    Route::get('assets', 'Asset/index');
    Route::post('assets', 'Asset/save');
    Route::get('assets/:id', 'Asset/read');
    Route::put('assets/:id', 'Asset/update');
    Route::delete('assets/:id', 'Asset/delete');
    Route::post('assets/:id/detect', 'Asset/detect');
    Route::post('assets/:id/ack', 'Asset/ack');
    Route::post('assets/:id/renew', 'Asset/renew');
    Route::post('assets/:id/snooze', 'Asset/snooze');

    Route::get('tasks', 'Task/index');
    Route::post('tasks', 'Task/save');
    Route::get('tasks/:id', 'Task/read');
    Route::put('tasks/:id', 'Task/update');
    Route::delete('tasks/:id', 'Task/delete');
    Route::post('tasks/:id/done', 'Task/done');
    Route::post('tasks/:id/snooze', 'Task/snooze');
    Route::get('tasks/:id/runs', 'Task/runs');

    Route::get('channels', 'Channel/index');
    Route::post('channels', 'Channel/save');
    Route::get('channels/:id', 'Channel/read');
    Route::put('channels/:id', 'Channel/update');
    Route::delete('channels/:id', 'Channel/delete');
    Route::post('channels/:id/test', 'Channel/test');
    Route::post('channels/:id/rebind', 'Channel/rebind');

    Route::get('templates', 'Template/index');
    Route::put('templates/:scene', 'Template/update');
    Route::delete('templates/:scene', 'Template/reset');
    Route::post('templates/preview', 'Template/preview');

    Route::get('notifications', 'Notification/index');
})->middleware(Auth::class);

Route::miss(fn () => json(['code' => 404, 'message' => 'Not Found', 'data' => null], 404));
