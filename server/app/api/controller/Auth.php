<?php
namespace app\api\controller;

use app\common\controller\ApiController;
use app\common\exception\ApiException;
use app\common\model\Setting;
use app\common\service\AuthContext;
use app\common\service\AuthService;
use app\common\service\QuotaService;
use app\common\service\TimeHelper;

class Auth extends ApiController
{
    public function register()
    {
        if (Setting::getValue('register_enabled', '1') !== '1') {
            throw new ApiException(lang('register_disabled'), 403);
        }
        $data = $this->validated([
            'email'    => 'require|email|max:191',
            'password' => 'require|min:8|max:64',
            'name'     => 'max:64',
            'locale'   => 'max:16',
            'timezone' => 'max:64',
        ]);

        AuthService::register($data['email'], $data['password'], $data['name'] ?? '', $data['locale'] ?? '', $data['timezone'] ?? '');
        $result = AuthService::login($data['email'], $data['password'], $this->request->ip(), (string) $this->request->header('user-agent'));

        return $this->ok(['token' => $result['token']]);
    }

    public function login()
    {
        $data   = $this->validated(['email' => 'require|email', 'password' => 'require']);
        $result = AuthService::login($data['email'], $data['password'], $this->request->ip(), (string) $this->request->header('user-agent'));
        return $this->ok(['token' => $result['token']]);
    }

    public function logout()
    {
        $header = (string) $this->request->header('authorization', '');
        AuthService::revoke(trim(substr($header, 7)));
        return $this->ok();
    }

    public function me()
    {
        $user   = AuthContext::user();
        $tenant = AuthContext::tenant();
        $plan   = QuotaService::plan($tenant);

        return $this->ok([
            'user'    => $user->toArray(),
            'tenant'  => [
                'id'               => $tenant->id,
                'name'             => $tenant->name,
                'role'             => AuthContext::$role,
                'plan'             => $plan?->toArray(),
                'plan_expire_time' => $tenant->plan_expire_time,
                'usage'            => QuotaService::usage($tenant),
            ],
            'tenants' => AuthService::memberships($user),
        ]);
    }

    public function profile()
    {
        $data = $this->validated([
            'name'        => 'require|max:64',
            'locale'      => 'require|in:' . implode(',', config('app.locales')),
            'timezone'    => 'require|max:64',
            'notify_time' => ['require', 'regex' => '/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);
        if (!TimeHelper::isValidTimezone($data['timezone'])) {
            throw new ApiException(lang('field_timezone') . ' ✗', 422);
        }
        AuthContext::user()->save($data);
        return $this->ok(AuthContext::user()->toArray(), lang('saved'));
    }

    public function password()
    {
        $data = $this->validated(['old_password|field_password' => 'require', 'password' => 'require|min:8|max:64']);
        $user = AuthContext::user();
        if (!$user->checkPassword($data['old_password'])) {
            throw new ApiException(lang('password_wrong'), 422);
        }
        $user->save(['password' => $data['password']]);
        return $this->ok(null, lang('saved'));
    }
}
