<?php
namespace app\admin\controller;

use app\common\controller\ApiController;
use app\common\exception\ApiException;
use app\common\model\User as UserModel;
use app\common\model\UserToken;
use app\common\service\AuthContext;

class User extends ApiController
{
    public function index()
    {
        $query = UserModel::order('id', 'desc');
        if ($kw = trim((string) $this->request->param('keyword'))) {
            $query->where(fn ($q) => $q->whereLike('email', "%{$kw}%")->whereOr('name', 'like', "%{$kw}%"));
        }
        return $this->ok($this->paginate($query));
    }

    public function update(int $id)
    {
        $user = UserModel::findOrFail($id);
        $data = $this->validated(['status' => 'boolean', 'is_super' => 'boolean', 'password' => 'min:8|max:64']);
        if ($id === AuthContext::userId() && (isset($data['status']) || isset($data['is_super']))) {
            throw new ApiException(lang('forbidden'), 403);
        }
        foreach (['status', 'is_super'] as $k) {
            if (isset($data[$k])) {
                $data[$k] = (int) filter_var($data[$k], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->save($data);
        if (isset($data['status']) && !$data['status'] || isset($data['password'])) {
            UserToken::where('user_id', $id)->delete();
        }
        return $this->ok($user, lang('saved'));
    }
}
