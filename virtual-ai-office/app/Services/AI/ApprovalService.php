<?php

namespace App\Services\AI;

use App\Exceptions\DomainRuleException;
use App\Models\AgentTaskModel;
use App\Models\ToolCallModel;
use Config\Database;
use Config\Services;

/** Human-in-the-loop decisions on agent tool calls (owners/admins, TenantContext scoped). */
class ApprovalService
{
    public function pending(): array
    {
        $rows = model(ToolCallModel::class)
            ->select('tool_calls.id, tool_calls.task_id, tool_calls.tool_name, tool_calls.input, tool_calls.created_at,
                      agent_tasks.title AS task_title, ai_agents.name AS agent_name, ai_tools.name AS tool_label, ai_tools.config AS tool_config')
            ->join('agent_tasks', 'agent_tasks.id = tool_calls.task_id')
            ->join('ai_agents', 'ai_agents.id = tool_calls.agent_id', 'left')
            ->join('ai_tools', 'ai_tools.id = tool_calls.tool_id', 'left')
            ->where('tool_calls.status', 'pending_approval')
            ->orderBy('tool_calls.id')->findAll();

        return array_map(static function (array $r) {
            $config = json_decode($r['tool_config'] ?? '{}', true) ?: [];

            return [
                'id' => (int) $r['id'], 'task_id' => (int) $r['task_id'], 'task_title' => $r['task_title'], 'agent_name' => $r['agent_name'],
                'tool' => $r['tool_label'] ?? $r['tool_name'], 'method' => $config['method'] ?? null, 'url' => $config['url'] ?? null,
                'input' => json_decode($r['input'] ?? '{}', true), 'created_at' => $r['created_at'],
            ];
        }, $rows);
    }

    public function decide(int $id, bool $approve, ?string $note, int $userId): array
    {
        $call = model(ToolCallModel::class)->find($id) ?? throw new DomainRuleException('Permintaan tool tidak ditemukan.', 404);
        if ($call['status'] !== 'pending_approval') {
            throw new DomainRuleException('Permintaan ini sudah diputuskan.', 409);
        }

        $db = Database::connect();
        $db->transException(true)->transStart();
        // Conditional on the status: two people deciding at once cannot both win.
        model(ToolCallModel::class)->where('id', $id)->where('status', 'pending_approval')->set([
            'status' => $approve ? 'approved' : 'rejected', 'decided_by_user_id' => $userId,
            'decision_note' => $note ? mb_substr($note, 0, 255) : null, 'decided_at' => date('Y-m-d H:i:s'),
        ])->update();
        if ($db->affectedRows() !== 1) {
            $db->transRollback();
            throw new DomainRuleException('Permintaan ini sudah diputuskan.', 409);
        }
        $open = model(ToolCallModel::class)->where('task_id', $call['task_id'])->where('status', 'pending_approval')->countAllResults();
        if ($open === 0) {
            model(AgentTaskModel::class)->where('id', $call['task_id'])->where('status', 'waiting_approval')
                ->set(['status' => 'queued', 'queued_at' => date('Y-m-d H:i:s')])->update();
        }
        $db->transComplete();

        Services::auditLogger()->record($approve ? 'tool.approved' : 'tool.rejected', [
            'entity_type' => 'tool_call', 'entity_id' => $id, 'new' => ['tool' => $call['tool_name'], 'note' => $note],
        ]);

        return ['decided' => $approve ? 'approved' : 'rejected', 'task_resumed' => $open === 0];
    }
}
