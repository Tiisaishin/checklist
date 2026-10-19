<?php
namespace app\common\job;

use app\common\model\Asset;
use app\common\service\DetectService;
use think\queue\Job;

class DetectAsset
{
    public function fire(Job $job, array $data): void
    {
        $asset = Asset::find((int) $data['id']);
        if ($asset) {
            DetectService::detect($asset);
        }
        $job->delete();
    }
}
