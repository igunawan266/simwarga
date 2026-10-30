<?php

namespace App\Controllers\Api\V1;

use App\Exceptions\DomainRuleException;
use App\Services\AI\AgentService;
use App\Services\AI\TaskService;
use Config\Services;

/**
 * /api/v1/office/agents and /tasks — tenant scoped. Mutations of agents are restricted to
 * owners/admins by route filters; every member may assign and follow tasks.
 */
class AgentsController extends BaseApiController
{
    private const AGENT_RULES = [
        'name'                => 'permit_empty|string|min_length[2]|max_length[100]',
        'job_title'           => 'permit_empty|string|min_length[2]|max_length[100]',
        'department'          => 'permit_empty|string|max_length[50]',
        'job_description'     => 'permit_empty|string|max_length[2000]',
        'system_prompt'       => 'permit_empty|string|max_length[20000]',
        'room_id'             => 'permit_empty|is_natural_no_zero',
        'ai_model_id'         => 'permit_empty|is_natural_no_zero',
        'manager_agent_id'    => 'permit_empty|is_natural',
        'token_limit_monthly' => 'permit_empty|is_natural|less_than_equal_to[1000000000]',
        'effort'              => 'permit_empty|in_list[low,medium,high,xhigh,max]',
    ];

    private AgentService $agents;
    private TaskService $tasks;

    public function __construct()
    {
        $this->agents = new AgentService();
        $this->tasks  = new TaskService();
    }

    public function index()
    {
        return $this->run(fn () => ['agents' => $this->agents->list(), 'limits' => $this->agents->limits()]);
    }

    /** Staff see the agent but not its configuration (system prompt, effort). */
    public function show(int $id)
    {
        return $this->run(function () use ($id) {
            $agent = $this->agents->detail($id);
            if (! Services::tenantContext()->hasRole('tenant_owner', 'tenant_admin')) {
                unset($agent['system_prompt'], $agent['effort']);
            }

            return ['agent' => $agent, 'can_manage' => Services::tenantContext()->hasRole('tenant_owner', 'tenant_admin')];
        });
    }

    public function models()
    {
        return $this->ok(['models' => $this->agents->models()]);
    }

    public function create()
    {
        $data  = $this->input([...AgentService::EDITABLE, 'effort']);
        $rules = ['name' => 'required|string|min_length[2]|max_length[100]', 'job_title' => 'required|string|min_length[2]|max_length[100]',
            'department' => 'required|string|max_length[50]'] + self::AGENT_RULES;
        if ($fail = $this->invalid($data, $rules)) {
            return $fail;
        }

        return $this->run(fn () => ['agent' => $this->agents->create($data)], 201);
    }

    public function update(int $id)
    {
        $data = $this->input([...AgentService::EDITABLE, 'effort']);
        if ($fail = $this->invalid($data, self::AGENT_RULES)) {
            return $fail;
        }

        return $this->run(fn () => ['agent' => $this->agents->update($id, $data)]);
    }

    public function delete(int $id)
    {
        return $this->run(function () use ($id) {
            $this->agents->delete($id);

            return ['deleted' => true];
        });
    }

    public function activate(int $id)
    {
        return $this->run(fn () => ['agent' => $this->agents->setActive($id, true)]);
    }

    public function deactivate(int $id)
    {
        return $this->run(fn () => ['agent' => $this->agents->setActive($id, false)]);
    }

    public function assign(int $id)
    {
        $data = $this->input(['title', 'instruction', 'priority']);
        $fail = $this->invalid($data, [
            'title'       => 'required|string|min_length[3]|max_length[200]',
            'instruction' => 'required|string|min_length[10]|max_length[20000]',
            'priority'    => 'permit_empty|in_list[low,normal,high,urgent]',
        ]);
        if ($fail) {
            return $fail;
        }

        return $this->run(fn () => $this->tasks->assign($id, $data['title'], $data['instruction'], ($data['priority'] ?? '') ?: 'normal', (int) Services::currentUser()->id()), 201);
    }

    public function tasks()
    {
        $filters = $this->request->getGet(['agent_id', 'status', 'top_level', 'limit']);

        return $this->run(fn () => ['tasks' => $this->tasks->list(array_filter($filters, static fn ($v) => $v !== null && $v !== ''))]);
    }

    public function task(int $id)
    {
        return $this->run(fn () => $this->tasks->detail($id));
    }

    public function cancel(int $id)
    {
        return $this->run(fn () => $this->tasks->cancel($id));
    }

    public function live()
    {
        return $this->ok(['agents' => $this->tasks->liveStatus()]);
    }

    private function run(callable $fn, int $status = 200)
    {
        try {
            $data = $fn();
        } catch (DomainRuleException $e) {
            return $this->domainError($e);
        }

        return $status === 201 ? $this->respondCreated(['data' => $data, 'csrf' => csrf_hash()]) : $this->ok($data);
    }
}
