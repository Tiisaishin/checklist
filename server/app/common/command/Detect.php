<?php
namespace app\common\command;

use app\common\model\Asset;
use app\common\service\DetectService;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;

/**
 * 立即探测指定资产（调试用）：php think checklist:detect 12
 */
class Detect extends Command
{
    protected function configure()
    {
        $this->setName('checklist:detect')
            ->addArgument('id', Argument::REQUIRED, 'Asset ID')
            ->setDescription('Detect domain / SSL expiry for an asset');
    }

    protected function execute(Input $input, Output $output)
    {
        $asset = Asset::find((int) $input->getArgument('id'));
        if (!$asset) {
            $output->writeln('<error>asset not found</error>');
            return 1;
        }
        $ok    = DetectService::detect($asset);
        $asset = Asset::find($asset->id);
        $output->writeln(json_encode([
            'ok'          => $ok,
            'expire_time' => $asset->expire_time,
            'provider'    => $asset->provider,
            'error'       => $asset->detect_error,
            'meta'        => $asset->detect_meta,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $ok ? 0 : 1;
    }
}
