<?php
namespace app\api\controller;

use app\common\controller\ApiController;
use app\common\exception\ApiException;
use app\common\model\ImportBatch;
use app\common\service\AuthContext;
use app\common\service\ImportService;

/**
 * 域名批量导入（文件在浏览器解析，这里接收结构化条目）
 */
class Import extends ApiController
{
    public function index()
    {
        $list = ImportBatch::where('tenant_id', $this->tenantId())->order('id', 'desc')->limit(20)->select()->toArray();
        return $this->ok($list);
    }

    public function save()
    {
        $items = $this->request->param('items/a', []);
        if (!$items) {
            throw new ApiException(lang('not_found'), 422);
        }
        $result = ImportService::import(AuthContext::tenant(), AuthContext::user(), $items, (array) $this->request->param('options/a', []));
        return $this->ok($result, lang('saved'));
    }

    public function read(int $id)
    {
        return $this->ok(ImportService::progress($this->find($id)));
    }

    public function delete(int $id)
    {
        $batch = $this->find($id);
        if ($batch->status === 'reverted') {
            throw new ApiException(lang('not_found'), 404);
        }
        return $this->ok(['deleted' => ImportService::revert($batch)], lang('deleted'));
    }

    private function find(int $id): ImportBatch
    {
        return ImportBatch::where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
