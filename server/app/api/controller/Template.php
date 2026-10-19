<?php
namespace app\api\controller;

use app\common\controller\ApiController;
use app\common\exception\ApiException;
use app\common\model\Asset;
use app\common\model\AssetEvent;
use app\common\model\Task;
use app\common\model\Template as TemplateModel;
use app\common\service\AuthContext;
use app\common\service\TemplateService;
use app\common\service\TimeHelper;
use DateTimeImmutable;

/**
 * 每个用户自定义自己的通知模板
 */
class Template extends ApiController
{
    public function index()
    {
        $user   = AuthContext::user();
        $custom = TemplateModel::where('user_id', $user->id)->column('content', 'scene');
        $groups = [];
        foreach (TemplateService::GROUPS as $group => $scenes) {
            foreach ($scenes as $scene) {
                $groups[$group][] = [
                    'scene'     => $scene,
                    'content'   => $custom[$scene] ?? TemplateService::defaultContent($scene, $user->locale),
                    'default'   => TemplateService::defaultContent($scene, $user->locale),
                    'presets'   => TemplateService::presets($scene, $user->locale),
                    'custom'    => isset($custom[$scene]),
                    'variables' => TemplateService::VARIABLES[$scene],
                ];
            }
        }
        return $this->ok($groups);
    }

    public function update(string $scene)
    {
        $this->checkScene($scene);
        $data = $this->validated(['content' => 'require|max:4000']);
        $user = AuthContext::user();

        $tpl = TemplateModel::where('user_id', $user->id)->where('scene', $scene)->find();
        if ($tpl) {
            $tpl->save(['content' => $data['content']]);
        } else {
            TemplateModel::create(['tenant_id' => $this->tenantId(), 'user_id' => $user->id, 'scene' => $scene, 'content' => $data['content']]);
        }
        return $this->ok(null, lang('saved'));
    }

    public function reset(string $scene)
    {
        $this->checkScene($scene);
        TemplateModel::where('user_id', AuthContext::userId())->where('scene', $scene)->delete();
        return $this->ok(null, lang('saved'));
    }

    /**
     * 预览：可指定真实的资产 / 检查项（asset_id / task_id），否则使用示例数据
     */
    public function preview()
    {
        $data = $this->validated(['scene|field_type' => 'require', 'content' => 'require|max:4000']);
        $this->checkScene($data['scene']);
        $user  = AuthContext::user();
        $now   = new DateTimeImmutable('now', TimeHelper::utc());
        $scene = $data['scene'];
        $vars  = null;

        $assetId = (int) $this->request->param('asset_id');
        $taskId  = (int) $this->request->param('task_id');
        if ($assetId && ($asset = Asset::where('tenant_id', $this->tenantId())->find($assetId))) {
            if (in_array($scene, TemplateService::EVENT_SCENES, true)) {
                $event = AssetEvent::where('asset_id', $asset->id)->where('type', $scene)->order('id', 'desc')->find();
                $vars  = $event ? TemplateService::eventVars($asset, $user, $scene, (array) $event->data) : null;
            } elseif (in_array($scene, ['domain_expire', 'ssl_expire', 'asset_expire'], true)) {
                $vars = TemplateService::assetVars($asset, $user, $now);
            }
        } elseif ($taskId && $scene === 'task_due' && ($task = Task::where('tenant_id', $this->tenantId())->find($taskId))) {
            $vars = TemplateService::taskVars($task, $user, $now);
        }

        $vars ??= TemplateService::sampleVars($scene, $user);
        return $this->ok(['html' => TemplateService::render($data['content'], $vars)]);
    }

    private function checkScene(string $scene): void
    {
        if (!in_array($scene, TemplateModel::SCENES, true)) {
            throw new ApiException(lang('not_found'), 404);
        }
    }
}
