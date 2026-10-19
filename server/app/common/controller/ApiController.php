<?php
namespace app\common\controller;

use app\common\exception\ApiException;
use app\common\service\AuthContext;
use think\App;
use think\exception\ValidateException;
use think\Request;
use think\Response;
use think\Validate;

abstract class ApiController
{
    protected Request $request;

    public function __construct(protected App $app)
    {
        $this->request = $app->request;
    }

    protected function ok(mixed $data = null, string $message = 'ok'): Response
    {
        return json(['code' => 0, 'message' => $message, 'data' => $data]);
    }

    /**
     * 校验并返回规则中声明的字段
     */
    protected function validated(array $rules, ?array $data = null): array
    {
        $data     = $data ?? $this->request->param();
        $labeled = [];
        $plain   = [];
        foreach ($rules as $key => $rule) {
            [$name, $label] = array_pad(explode('|', $key, 2), 2, null);
            $plain[$name]   = $rule;
            $labeled[$name . '|' . lang($label ?: 'field_' . $name)] = $rule;
        }
        $validate = (new Validate())->rule($labeled);
        if (!$validate->check($data)) {
            throw new ValidateException($validate->getError());
        }
        return array_intersect_key($data, $plain);
    }

    protected function tenantId(): int
    {
        return AuthContext::tenantId();
    }

    protected function ensureAdmin(): void
    {
        if (!AuthContext::isAdmin()) {
            throw new ApiException(lang('forbidden'), 403);
        }
    }

    /** 分页参数 */
    protected function pageSize(): int
    {
        return min(100, max(1, (int) $this->request->param('page_size', 20)));
    }

    protected function paginate($query): array
    {
        $page = $query->paginate(['list_rows' => $this->pageSize(), 'page' => max(1, (int) $this->request->param('page', 1))]);
        return [
            'items' => $page->items(),
            'total' => $page->total(),
            'page'  => $page->currentPage(),
            'page_size' => $page->listRows(),
        ];
    }
}
