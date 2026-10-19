<?php
namespace app\api\controller;

use app\common\controller\ApiController;
use app\common\model\Asset as AssetModel;
use app\common\model\Channel;
use app\common\model\Task as TaskModel;
use app\common\service\AuthContext;
use app\common\service\QuotaService;

class Dashboard extends ApiController
{
    public function index()
    {
        $tid = $this->tenantId();

        $assets = AssetModel::where('tenant_id', $tid)->where('status', 'active')
            ->whereNotNull('expire_time')->where('expire_time', '<=', now_utc('+90 days'))
            ->order('expire_time')->limit(100)->select();
        $tasks = TaskModel::where('tenant_id', $tid)->where('status', 'active')
            ->whereNotNull('next_due_time')->where('next_due_time', '<=', now_utc('+7 days'))
            ->order('next_due_time')->limit(100)->select();

        $assetItems = array_map(fn ($a) => Asset::present($a), $assets->all());
        $taskItems  = array_map(fn ($t) => Task::present($t), $tasks->all());

        $bucket = fn (array $items, int $from, int $to) => count(array_filter($items, fn ($i) => $i['days_left'] !== null && $i['days_left'] >= $from && $i['days_left'] <= $to));

        // 未来 12 个月的续费支出（按币种）
        $costs = [];
        $rows  = AssetModel::where('tenant_id', $tid)->where('status', 'active')->where('price', '>', 0)
            ->field('price,currency,renew_cycle,expire_time')->select();
        foreach ($rows as $row) {
            $times = match ($row->renew_cycle) {
                'monthly'   => 12,
                'quarterly' => 4,
                'yearly'    => 1,
                default     => ($row->expire_time && $row->expire_time <= now_utc('+1 year')) ? 1 : 0,
            };
            $costs[$row->currency] = round(($costs[$row->currency] ?? 0) + $row->price * $times, 2);
        }

        return $this->ok([
            'stats' => [
                'overdue'   => $bucket($assetItems, PHP_INT_MIN, -1) + count(array_filter($taskItems, fn ($t) => $t['overdue'])),
                'in7'       => $bucket($assetItems, 0, 7),
                'in30'      => $bucket($assetItems, 0, 30),
                'in90'      => $bucket($assetItems, 0, 90),
                'tasks_due' => count(array_filter($taskItems, fn ($t) => $t['days_left'] !== null && $t['days_left'] <= 0)),
            ],
            'assets'   => array_slice($assetItems, 0, 20),
            'tasks'    => array_slice($taskItems, 0, 20),
            'costs'    => $costs,
            'usage'    => QuotaService::usage(AuthContext::tenant()),
            'channels' => Channel::where('tenant_id', $tid)->where('user_id', AuthContext::userId())->where('status', 'active')->count(),
        ]);
    }
}
