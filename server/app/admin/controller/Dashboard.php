<?php
namespace app\admin\controller;

use app\common\controller\ApiController;
use app\common\model\Asset;
use app\common\model\Channel;
use app\common\model\Notification;
use app\common\model\Task;
use app\common\model\Tenant;
use app\common\model\User;

class Dashboard extends ApiController
{
    public function index()
    {
        return $this->ok([
            'users'          => User::count(),
            'users_7d'       => User::where('create_time', '>=', now_utc('-7 days'))->count(),
            'tenants'        => Tenant::count(),
            'assets'         => Asset::count(),
            'tasks'          => Task::count(),
            'channels'       => Channel::where('status', 'active')->count(),
            'sent_24h'       => Notification::where('status', 'sent')->where('create_time', '>=', now_utc('-1 day'))->count(),
            'failed_24h'     => Notification::where('status', 'failed')->where('create_time', '>=', now_utc('-1 day'))->count(),
        ]);
    }
}
