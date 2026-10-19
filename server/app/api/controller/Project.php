<?php
namespace app\api\controller;

use app\common\controller\ApiController;
use app\common\model\Asset;
use app\common\model\Project as ProjectModel;
use app\common\model\Task;

class Project extends ApiController
{
    public function index()
    {
        return $this->ok(ProjectModel::where('tenant_id', $this->tenantId())->order(['sort', 'id'])->select());
    }

    public function save()
    {
        $data = $this->validated(['name' => 'require|max:128', 'color' => 'max:16', 'sort' => 'integer']);
        $data['tenant_id'] = $this->tenantId();
        return $this->ok(ProjectModel::create($data), lang('saved'));
    }

    public function update(int $id)
    {
        $project = ProjectModel::where('tenant_id', $this->tenantId())->findOrFail($id);
        $project->save($this->validated(['name' => 'require|max:128', 'color' => 'max:16', 'sort' => 'integer']));
        return $this->ok($project, lang('saved'));
    }

    public function delete(int $id)
    {
        $project = ProjectModel::where('tenant_id', $this->tenantId())->findOrFail($id);
        Asset::where('project_id', $id)->update(['project_id' => 0]);
        Task::where('project_id', $id)->update(['project_id' => 0]);
        $project->delete();
        return $this->ok(null, lang('deleted'));
    }
}
