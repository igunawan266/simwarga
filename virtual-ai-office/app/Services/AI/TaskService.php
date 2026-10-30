<?php

namespace App\Services\AI;

use App\Exceptions\DomainRuleException;
use App\Models\AgentLogModel;
use App\Models\AgentTaskModel;
use App\Models\AiAgentModel;
use App\Models\TenantSubscriptionModel;
use Config\Database;
use Config\Services;

/** Tenant-side task assignment and monitoring (TenantContext scoped). */
class TaskService
{
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public function assign(int $agentId, string $title, string $instruction, string $priority, int $userId): array
    {
        $agent = (new AgentService())->find($agentId);
        if (! (int) $agent['is_active']) {
            throw new DomainRuleException('Agent belum aktif. Aktifkan agent terlebih dahulu.');
        }
        if (model(TenantSubscriptionModel::class)->current() === null) {
            throw new DomainRuleException('Langganan tidak aktif.', 402);
        }

        $now = date('Y-m-d H:i:s');
        $id  = model(AgentTaskModel::class)->insert([
            'agent_id' => $agentId, 'assigned_by_user_id' => $userId, 'title' => $title, 'instruction' => $instruction,
            'priority' => $priority, 'status' => 'queued', 'queued_at' => $now,
        ], true);
        AgentConversation::add(Services::tenantContext()->id(), $id, 'user', $instruction, senderUserId: $userId, recipientAgentId: $agentId);
        model(AgentLogModel::class)->insert(['agent_id' => $agentId, 'task_id' => $id, 'event' => 'task_queued', 'message' => "Task \"{$title}\" masuk antrean."]);
        Services::auditLogger()->record('task.assign', ['entity_type' => 'agent_task', 'entity_id' => $id, 'new' => ['agent_id' => $agentId, 'title' => $title]]);

        return $this->detail($id);
    }

    public function list(array $filters = []): array
    {
        $q = model(AgentTaskModel::class)
            ->select('agent_tasks.id, agent_tasks.parent_task_id, agent_tasks.agent_id, agent_tasks.title, agent_tasks.priority, agent_tasks.status,
                      agent_tasks.input_tokens, agent_tasks.output_tokens, agent_tasks.created_at, agent_tasks.completed_at, ai_agents.name AS agent_name')
            ->join('ai_agents', 'ai_agents.id = agent_tasks.agent_id', 'left');
        if (! empty($filters['agent_id'])) {
            $q->where('agent_tasks.agent_id', (int) $filters['agent_id']);
        }
        if (! empty($filters['status'])) {
            $q->where('agent_tasks.status', $filters['status']);
        }
        if (! empty($filters['top_level'])) {
            $q->where('agent_tasks.parent_task_id', null);
        }

        return $q->orderBy('agent_tasks.id', 'DESC')->findAll(min(100, (int) ($filters['limit'] ?? 30)));
    }

    public function detail(int $id): array
    {
        $task = model(AgentTaskModel::class)
            ->select('agent_tasks.*, ai_agents.name AS agent_name, ai_agents.job_title AS agent_title')
            ->join('ai_agents', 'ai_agents.id = agent_tasks.agent_id', 'left')
            ->find($id) ?? throw new DomainRuleException('Task tidak ditemukan.', 404);

        $children = model(AgentTaskModel::class)
            ->select('agent_tasks.id, agent_tasks.title, agent_tasks.status, agent_tasks.result, agent_tasks.error_message, ai_agents.name AS agent_name, ai_agents.job_title AS agent_title')
            ->join('ai_agents', 'ai_agents.id = agent_tasks.agent_id', 'left')
            ->where('agent_tasks.parent_task_id', $id)->orderBy('agent_tasks.id')->findAll();
        $logs = model(AgentLogModel::class)->select('id, agent_id, level, event, message, input_tokens, output_tokens, duration_ms, created_at')
            ->where('task_id', $id)->orderBy('id')->findAll(100);
        $conversation = Database::connect()->table('agent_conversations c')
            ->select('c.sender_type, c.content, c.tokens, c.created_at, s.name AS sender_agent, r.name AS recipient_agent, u.name AS sender_user')
            ->join('ai_agents s', 's.id = c.sender_agent_id', 'left')
            ->join('ai_agents r', 'r.id = c.recipient_agent_id', 'left')
            ->join('users u', 'u.id = c.sender_user_id', 'left')
            ->where('c.tenant_id', Services::tenantContext()->id())->where('c.task_id', $id)
            ->orderBy('c.id')->get()->getResultArray();

        $task['sources'] = json_decode($task['sources'] ?? 'null', true) ?: [];
        unset($task['state']); // internal conversation state (may hold document text) is not sent to the browser
        $toolCalls = array_map(static fn ($c) => $c + ['input_decoded' => json_decode($c['input'] ?? '{}', true)],
            model(\App\Models\ToolCallModel::class)->select('id, round, tool_name, input, status, result, is_error, http_status, duration_ms, decision_note, decided_at, executed_at')
                ->where('task_id', $id)->orderBy('id')->findAll());
        $task['tool_calls'] = $toolCalls;

        return ['task' => $task, 'children' => $children, 'logs' => $logs, 'conversation' => $conversation];
    }

    public function cancel(int $id): array
    {
        $task = model(AgentTaskModel::class)->find($id) ?? throw new DomainRuleException('Task tidak ditemukan.', 404);
        if (! in_array($task['status'], ['queued', 'waiting', 'waiting_approval'], true)) {
            throw new DomainRuleException('Hanya task yang menunggu yang bisa dibatalkan.');
        }
        $now = date('Y-m-d H:i:s');
        model(AgentTaskModel::class)->whereIn('status', ['queued', 'waiting', 'waiting_approval'])
            ->groupStart()->where('id', $id)->orWhere('parent_task_id', $id)->groupEnd()
            ->set(['status' => 'cancelled', 'error_message' => 'Dibatalkan oleh pengguna', 'completed_at' => $now])->update();
        Services::auditLogger()->record('task.cancel', ['entity_type' => 'agent_task', 'entity_id' => $id]);

        return $this->detail($id);
    }

    /** Lightweight status feed for the 3D office (polled every few seconds). */
    public function liveStatus(): array
    {
        $agents  = model(AiAgentModel::class)->select('id, status, room_id')->findAll();
        $running = model(AgentTaskModel::class)->select('agent_id, title')->whereIn('status', ['running', 'waiting', 'waiting_approval'])->findAll();
        $current = array_column($running, 'title', 'agent_id');

        return array_map(static fn ($a) => [
            'id' => (int) $a['id'], 'status' => $a['status'], 'room_id' => $a['room_id'] === null ? null : (int) $a['room_id'],
            'task' => $current[$a['id']] ?? null,
        ], $agents);
    }
}
