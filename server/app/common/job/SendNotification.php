<?php
namespace app\common\job;

use app\common\service\NotificationService;
use think\queue\Job;

class SendNotification
{
    public function fire(Job $job, array $data): void
    {
        NotificationService::deliver((int) $data['id'], (array) ($data['actions'] ?? []));
        $job->delete();
    }

    public function failed(array $data): void
    {
    }
}
