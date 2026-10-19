<?php
namespace app\api\controller;

use app\common\controller\ApiController;
use app\common\exception\ApiException;
use app\common\model\Template as TemplateModel;
use app\common\service\AuthContext;
use app\common\service\TemplateService;

/**
 * 每个用户自定义自己的通知模板
 */
class Template extends ApiController
{
    public function index()
    {
        $user   = AuthContext::user();
        $custom = TemplateModel::where('user_id', $user->id)->column('content', 'scene');
        $list   = [];
        foreach (TemplateModel::SCENES as $scene) {
            $list[] = [
                'scene'     => $scene,
                'content'   => $custom[$scene] ?? TemplateService::defaultContent($scene, $user->locale),
                'default'   => TemplateService::defaultContent($scene, $user->locale),
                'custom'    => isset($custom[$scene]),
                'variables' => TemplateService::VARIABLES[$scene],
            ];
        }
        return $this->ok($list);
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

    public function preview()
    {
        $data = $this->validated(['scene|field_type' => 'require', 'content' => 'require|max:4000']);
        $this->checkScene($data['scene']);
        $locale = AuthContext::user()->locale;
        return $this->ok(['html' => TemplateService::render($data['content'], TemplateService::sampleVars($data['scene'], $locale))]);
    }

    private function checkScene(string $scene): void
    {
        if (!in_array($scene, TemplateModel::SCENES, true)) {
            throw new ApiException(lang('not_found'), 404);
        }
    }
}
