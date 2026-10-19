<?php
namespace app\admin\controller;

use app\common\controller\ApiController;
use app\common\model\Tenant as TenantModel;
use app\common\service\QuotaService;
use app\common\service\TimeHelper;

class Tenant extends ApiController
{
    public function index()
    {
        $query = TenantModel::with(['owner' => fn ($q) => $q->field('id,email,name')])->order('id', 'desc');
        if ($kw = trim((string) $this->request->param('keyword'))) {
            $query->whereLike('name', "%{$kw}%");
        }
        $result          = $this->paginate($query);
        $result['items'] = array_map(fn ($t) => $t->toArray() + ['usage' => QuotaService::usage($t)], $result['items']);
        return $this->ok($result);
    }

    public function update(int $id)
    {
        $tenant = TenantModel::findOrFail($id);
        $data   = $this->validated(['name' => 'max:128', 'plan_id' => 'integer', 'plan_expire_time' => 'max:40', 'status' => 'boolean']);
        if (array_key_exists('plan_expire_time', $data)) {
            $data['plan_expire_time'] = TimeHelper::parseInput($data['plan_expire_time']);
        }
        if (isset($data['status'])) {
            $data['status'] = (int) filter_var($data['status'], FILTER_VALIDATE_BOOLEAN);
        }
        $tenant->save($data);
        return $this->ok($tenant, lang('saved'));
    }
}
