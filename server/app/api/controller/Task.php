<?php
namespace app\api\controller;

use app\common\controller\ApiController;
use app\common\model\Group as GroupModel;
use app\common\model\Task as TaskModel;
use app\common\model\TaskRun;
use app\common\service\AssetService;
use app\common\service\AuthContext;
use app\common\service\QuotaService;
use app\common\service\TaskService;
use app\common\service\TimeHelper;
use DateTimeImmutable;

class Task extends ApiController
{
    private const RULE_FIELDS = ['freq', 'interval', 'by_weekday', 'by_monthday', 'by_month', 'time_of_day', 'start_date', 'mode'];

    public function index()
    {
        $query = TaskModel::where('tenant_id', $this->tenantId())
            ->where('status', $this->request->param('status', 'active'));

        if ($groupId = (int) $this->request->param('group_id')) {
            $query->where('group_id', $groupId);
        }
        if ($freq = $this->request->param('freq')) {
            $query->where('freq', $freq);
        }
        if ($keyword = trim((string) $this->request->param('keyword'))) {
            $query->whereLike('title', "%{$keyword}%");
        }
        if ($within = (int) $this->request->param('within')) {
            $query->where('next_due_time', '<=', now_utc("+{$within} days"));
        }
        $query->order(['next_due_time' => 'asc', 'id' => 'desc']);

        $result          = $this->paginate($query);
        $result['items'] = array_map(fn ($t) => self::present($t), $result['items']);
        return $this->ok($result);
    }

    public function read(int $id)
    {
        return $this->ok(self::present($this->find($id)));
    }

    public function save()
    {
        QuotaService::ensure(AuthContext::tenant(), 'tasks');
        $data = $this->input() + [
            'tenant_id' => $this->tenantId(),
            'user_id'   => AuthContext::userId(),
            'group_id'  => GroupModel::resolveId($this->tenantId(), 0),
            'freq'      => 'daily',
            'interval'  => 1,
            'mode'      => 'fixed',
            'time_of_day' => '09:00',
        ];
        if (empty($data['start_date'])) {
            $data['start_date'] = (new DateTimeImmutable('now', TimeHelper::tz(AuthContext::user()->timezone)))->format('Y-m-d');
        }

        $task = new TaskModel($data);
        $task->next_due_time = TaskService::computeInitialDue($task, AuthContext::user()->timezone);
        $task->save();

        return $this->ok(self::present(TaskModel::find($task->id)), lang('saved'));
    }

    public function update(int $id)
    {
        $task = $this->find($id);
        $data = $this->input();
        $ruleChanged = false;
        foreach (self::RULE_FIELDS as $field) {
            if (array_key_exists($field, $data) && (string) $data[$field] !== (string) $task->getData($field)) {
                $ruleChanged = true;
            }
        }
        $task->data($data, true);
        if ($ruleChanged) {
            $task->next_due_time = TaskService::computeInitialDue($task, TaskService::ownerTimezone($task));
            $task->snooze_until  = null;
        }
        $task->save();
        return $this->ok(self::present(TaskModel::find($id)), lang('saved'));
    }

    public function delete(int $id)
    {
        $this->find($id)->delete();
        return $this->ok(null, lang('deleted'));
    }

    public function done(int $id)
    {
        $task = TaskService::complete($this->find($id), AuthContext::user(), mb_substr((string) $this->request->param('note', ''), 0, 2000));
        return $this->ok(self::present($task));
    }

    public function snooze(int $id)
    {
        $task = $this->find($id);
        AssetService::snooze($task, (string) $this->request->param('period', '1h'));
        return $this->ok(self::present($task));
    }

    public function runs(int $id)
    {
        $this->find($id);
        $query = TaskRun::with('user')->where('task_id', $id)->order('id', 'desc');
        return $this->ok($this->paginate($query));
    }

    private function find(int $id): TaskModel
    {
        return TaskModel::where('tenant_id', $this->tenantId())->findOrFail($id);
    }

    private function input(): array
    {
        $data = $this->validated([
            'title'           => 'require|max:191',
            'description'     => 'max:5000',
            'group_id'        => 'integer',
            'freq'            => 'require|in:' . implode(',', TaskModel::FREQS),
            'interval'        => 'integer|between:1,365',
            'by_weekday'      => 'max:32',
            'by_monthday'     => 'integer|between:-1,31',
            'by_month'        => 'integer|between:0,12',
            'time_of_day'     => ['regex' => '/^([01]\d|2[0-3]):[0-5]\d$/'],
            'mode'            => 'in:' . implode(',', TaskModel::MODES),
            'start_date'      => 'dateFormat:Y-m-d',
            'remind_offsets'  => 'max:128',
            'remind_overdue'  => 'boolean',
            'remind_channels' => 'array',
            'steps'           => 'array',
            'status'          => 'in:active,archived',
        ]);

        if (isset($data['by_weekday'])) {
            $value = is_array($data['by_weekday']) ? $data['by_weekday'] : explode(',', (string) $data['by_weekday']);
            $days  = array_unique(array_filter(array_map('intval', $value), fn ($d) => $d >= 1 && $d <= 7));
            sort($days);
            $data['by_weekday'] = implode(',', $days);
        }
        if (isset($data['remind_offsets'])) {
            $value = is_array($data['remind_offsets']) ? implode(',', $data['remind_offsets']) : (string) $data['remind_offsets'];
            $data['remind_offsets'] = implode(',', parse_offsets($value)) ?: '0';
        }
        if (isset($data['remind_channels'])) {
            $data['remind_channels'] = array_values(array_map('intval', $data['remind_channels']));
        }
        if (isset($data['steps'])) {
            $data['steps'] = array_values(array_filter(array_map(fn ($s) => mb_substr(trim((string) (is_array($s) ? ($s['title'] ?? '') : $s)), 0, 200), $data['steps'])));
        }
        if (isset($data['remind_overdue'])) {
            $data['remind_overdue'] = (int) filter_var($data['remind_overdue'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('group_id', $data)) {
            $data['group_id'] = GroupModel::resolveId($this->tenantId(), (int) $data['group_id']);
        }
        return $data;
    }

    public static function present(TaskModel $task): array
    {
        $data = $task->toArray();
        $tz   = TimeHelper::tz(AuthContext::user()->timezone);
        $now  = new DateTimeImmutable('now', TimeHelper::utc());
        $due  = TimeHelper::fromDb($task->next_due_time);
        $data['days_left'] = $due ? TimeHelper::daysBetween($now->setTimezone($tz), $due->setTimezone($tz)) : null;
        $data['overdue']   = $due && $due <= $now;
        $data['snoozed']   = $task->snooze_until && $task->snooze_until > now_utc();
        return $data;
    }
}
