<?php
namespace app\admin\controller;

use app\common\controller\ApiController;
use app\common\exception\ApiException;
use app\common\model\Plan as PlanModel;
use app\common\model\Tenant;

class Plan extends ApiController
{
    public function index()
    {
        return $this->ok(PlanModel::order(['sort', 'id'])->select());
    }

    public function save()
    {
        $data = $this->input();
        $plan = PlanModel::create($data);
        $this->syncDefault($plan);
        return $this->ok($plan, lang('saved'));
    }

    public function update(int $id)
    {
        $plan = PlanModel::findOrFail($id);
        $plan->save($this->input());
        $this->syncDefault($plan);
        return $this->ok($plan, lang('saved'));
    }

    public function delete(int $id)
    {
        $plan = PlanModel::findOrFail($id);
        if ($plan->is_default || Tenant::where('plan_id', $id)->count()) {
            throw new ApiException(lang('forbidden'), 403);
        }
        $plan->delete();
        return $this->ok(null, lang('deleted'));
    }

    private function input(): array
    {
        $data = $this->validated([
            'code'            => 'require|alphaDash|max:32',
            'name'            => 'require|max:64',
            'max_assets'      => 'require|integer|egt:-1',
            'max_domains'     => 'require|integer|egt:-1',
            'max_ssl'         => 'require|integer|egt:-1',
            'max_tasks'       => 'require|integer|egt:-1',
            'max_channels'    => 'require|integer|egt:-1',
            'max_members'     => 'require|integer|egt:-1',
            'max_vault'       => 'integer|egt:-1',
            'detect_interval' => 'require|integer|egt:30',
            'price_month'     => 'float|egt:0',
            'price_year'      => 'float|egt:0',
            'currency'        => 'max:8',
            'is_default'      => 'boolean',
            'sort'            => 'integer',
            'status'          => 'boolean',
        ]);
        foreach (['is_default', 'status'] as $k) {
            if (isset($data[$k])) {
                $data[$k] = (int) filter_var($data[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }
        return $data;
    }

    private function syncDefault(PlanModel $plan): void
    {
        if ($plan->is_default) {
            PlanModel::where('id', '<>', $plan->id)->update(['is_default' => 0]);
        }
    }
}
