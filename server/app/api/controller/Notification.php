<?php
namespace app\api\controller;

use app\common\controller\ApiController;
use app\common\model\Notification as NotificationModel;
use app\common\service\AuthContext;

class Notification extends ApiController
{
    public function index()
    {
        $query = NotificationModel::where('tenant_id', $this->tenantId())
            ->where('user_id', AuthContext::userId())
            ->field('id,channel_id,target_type,target_id,content,status,error,sent_time,create_time')
            ->order('id', 'desc');
        if ($status = $this->request->param('status')) {
            $query->where('status', $status);
        }
        return $this->ok($this->paginate($query));
    }
}
