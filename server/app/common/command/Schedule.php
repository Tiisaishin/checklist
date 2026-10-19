<?php
namespace app\common\command;

use app\common\model\UserToken;
use app\common\service\DetectService;
use app\common\service\ReminderService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use Throwable;

/**
 * 调度器：每分钟执行提醒；每 10 分钟投递自动探测；每天清理过期令牌。
 *   php think checklist:schedule          执行一次（配合系统 cron 每分钟调用）
 *   php think checklist:schedule --loop   常驻进程（Docker 中使用）
 */
class Schedule extends Command
{
    protected function configure()
    {
        $this->setName('checklist:schedule')
            ->addOption('loop', null, Option::VALUE_NONE, 'Run forever, once per minute')
            ->setDescription('Run reminder scheduler');
    }

    protected function execute(Input $input, Output $output)
    {
        if (!$input->getOption('loop')) {
            $this->tick($output);
            return 0;
        }

        $output->writeln('<info>scheduler started</info>');
        while (true) {
            $this->tick($output);
            // 对齐到下一分钟
            sleep(max(1, 60 - (int) date('s')));
        }
    }

    private function tick(Output $output): void
    {
        $minute = (int) gmdate('i');
        try {
            $sent = (new ReminderService())->run();
            if ($sent) {
                $output->writeln(gmdate('[Y-m-d H:i:s]') . " reminders queued: {$sent}");
            }

            if ($minute % 10 === 0) {
                $count = DetectService::dispatchDue();
                if ($count) {
                    $output->writeln(gmdate('[Y-m-d H:i:s]') . " detections queued: {$count}");
                }
            }

            if (gmdate('H:i') === '03:00') {
                UserToken::where('expire_time', '<', now_utc())->delete();
            }
        } catch (Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            // 长连接失效时重连
            Db::close();
        }
    }
}
