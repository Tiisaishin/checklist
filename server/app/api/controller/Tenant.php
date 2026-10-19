<?php
namespace app\api\controller;

use app\common\controller\ApiController;
use app\common\exception\ApiException;
use app\common\model\TenantUser;
use app\common\service\AuthContext;
use app\common\service\QuotaService;

class Tenant extends ApiController
{
    public function current()
    {
        $tenant = AuthContext::tenant();
        return $this->ok([
            'id'    => $tenant->id,
            'name'  => $tenant->name,
            'role'  => AuthContext::$role,
            'plan'  => QuotaService::plan($tenant)?->toArray(),
            'plan_expire_time' => $tenant->plan_expire_time,
            'usage' => QuotaService::usage($tenant),
        ]);
    }

    public function update()
    {
        $this->ensureAdmin();
        $data = $this->validated(['name' => 'require|max:128']);
        AuthContext::tenant()->save($data);
        return $this->ok(null, lang('saved'));
    }

    public function switch()
    {
        $id = (int) $this->request->param('tenant_id');
        if (!TenantUser::where('tenant_id', $id)->where('user_id', AuthContext::userId())->count()) {
            throw new ApiException(lang('forbidden'), 403);
        }
        AuthContext::user()->save(['current_tenant_id' => $id]);
        return $this->ok();
    }
}
