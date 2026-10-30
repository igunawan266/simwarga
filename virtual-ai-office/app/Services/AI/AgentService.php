<?php

namespace App\Services\AI;

use App\Exceptions\DomainRuleException;
use App\Models\AiAgentModel;
use App\Models\RoomModel;
use App\Models\TenantSubscriptionModel;
use Config\Database;
use Config\Services;

/**
 * Tenant-side management of AI agents (all queries run inside the verified TenantContext).
 * Plan limits are enforced here, on the server, not just hidden in the UI.
 */
class AgentService
{
    public const EFFORTS  = ['low', 'medium', 'high', 'xhigh', 'max'];
    public const EDITABLE = ['name', 'job_title', 'department', 'job_description', 'system_prompt', 'room_id', 'ai_model_id', 'manager_agent_id', 'token_limit_monthly', 'avatar', 'knowledge_base_id'];

    public function list(): array
    {
        $rows = model(AiAgentModel::class)
            ->select('ai_agents.*, ai_models.display_name AS model_name, ai_models.model_key, rooms.name AS room_name')
            ->join('ai_models', 'ai_models.id = ai_agents.ai_model_id', 'left')
            ->join('rooms', 'rooms.id = ai_agents.room_id', 'left')
            ->orderBy('ai_agents.id')->findAll();

        return array_map(fn (array $a) => $this->present($a, false), $rows);
    }

    /** Full configuration, including the system prompt (owners/admins only — enforced by route). */
    public function detail(int $id): array
    {
        $agent = $this->find($id);
        $row   = model(AiAgentModel::class)
            ->select('ai_agents.*, ai_models.display_name AS model_name, ai_models.model_key, rooms.name AS room_name')
            ->join('ai_models', 'ai_models.id = ai_agents.ai_model_id', 'left')
            ->join('rooms', 'rooms.id = ai_agents.room_id', 'left')
            ->find($agent['id']);

        return $this->present($row, true) + ['tool_ids' => (new ToolService())->toolIdsForAgent($agent['id']), 'team' => array_map(
            static fn ($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'job_title' => $t['job_title']],
            model(AiAgentModel::class)->where('manager_agent_id', $agent['id'])->where('is_active', 1)->findAll(),
        )];
    }

    public function create(array $data): array
    {
        $limits = $this->limits();
        $count  = model(AiAgentModel::class)->countAllResults();
        if ($count >= $limits['max_agents']) {
            throw new DomainRuleException("Batas paket tercapai: maksimal {$limits['max_agents']} Agent AI. Upgrade paket untuk menambah agent.", 403);
        }

        $row = $this->validated($data, null) + [
            'status'     => 'offline',
            'is_active'  => 0,
            'api_config' => json_encode(['effort' => $data['effort'] ?? 'medium']),
        ];
        $row['ai_model_id'] ??= $this->defaultModelId();
        $id = model(AiAgentModel::class)->insert($row, true);
        if (isset($data['tool_ids'])) {
            (new ToolService())->setAgentTools($id, $this->ids($data['tool_ids']));
        }
        $this->audit('agent.create', $id, null, $row);

        return $this->detail($id);
    }

    public function update(int $id, array $data): array
    {
        $before  = $this->find($id);
        $changes = $this->validated($data, $before);
        if (isset($data['effort'])) {
            $config            = json_decode($before['api_config'] ?? '{}', true) ?: [];
            $config['effort']  = $data['effort'];
            $changes['api_config'] = json_encode($config);
        }
        if (isset($data['tool_ids'])) {
            (new ToolService())->setAgentTools($id, $this->ids($data['tool_ids']));
        }
        if ($changes !== []) {
            model(AiAgentModel::class)->update($id, $changes);
            $this->audit('agent.update', $id, array_intersect_key($before, $changes), $changes);
        }

        return $this->detail($id);
    }

    public function setActive(int $id, bool $active): array
    {
        $agent = $this->find($id);
        if ($active && model(TenantSubscriptionModel::class)->current() === null) {
            throw new DomainRuleException('Langganan tidak aktif; agent tidak dapat diaktifkan.', 402);
        }
        model(AiAgentModel::class)->update($id, ['is_active' => $active ? 1 : 0, 'status' => $active ? 'online' : 'offline']);
        $this->audit($active ? 'agent.activate' : 'agent.deactivate', $id, ['is_active' => (int) $agent['is_active']], ['is_active' => (int) $active]);

        return $this->detail($id);
    }

    /** Soft delete; open tasks are cancelled and subordinates lose their manager link. */
    public function delete(int $id): void
    {
        $this->find($id);
        $tenantId = Services::tenantContext()->id();
        $db       = Database::connect();
        $db->transException(true)->transStart();
        $db->table('agent_tasks')->where('tenant_id', $tenantId)->where('agent_id', $id)->whereIn('status', ['queued', 'waiting', 'waiting_approval'])
            ->update(['status' => 'cancelled', 'error_message' => 'Agent dihapus', 'updated_at' => date('Y-m-d H:i:s')]);
        $db->table('ai_agents')->where('tenant_id', $tenantId)->where('manager_agent_id', $id)->update(['manager_agent_id' => null]);
        model(AiAgentModel::class)->delete($id);
        $db->transComplete();
        $this->audit('agent.delete', $id, null, null);
    }

    public function models(): array
    {
        return Database::connect()->table('ai_models')->select('id, provider, model_key, display_name')
            ->where('is_active', 1)->orderBy('id')->get()->getResultArray();
    }

    /** max_agents / monthly_token_quota of the tenant's current (or latest) subscription. */
    public function limits(): array
    {
        $sub = model(TenantSubscriptionModel::class)->current()
            ?? model(TenantSubscriptionModel::class)->orderBy('id', 'DESC')->first();
        if ($sub === null) {
            return ['max_agents' => 0, 'monthly_token_quota' => 0];
        }
        $snapshot = json_decode($sub['limits_snapshot'] ?? '{}', true) ?: [];
        $plan     = Database::connect()->table('subscription_plans')->where('id', $sub['plan_id'])->get()->getRowArray() ?? [];

        return [
            'max_agents'          => (int) ($snapshot['max_agents'] ?? $plan['max_agents'] ?? 0),
            'monthly_token_quota' => (int) ($snapshot['monthly_token_quota'] ?? $plan['monthly_token_quota'] ?? 0),
        ];
    }

    public function find(int $id): array
    {
        return model(AiAgentModel::class)->find($id) ?? throw new DomainRuleException('Agent tidak ditemukan.', 404);
    }

    private function validated(array $data, ?array $before): array
    {
        $row = array_intersect_key($data, array_flip(self::EDITABLE));
        foreach (['room_id', 'ai_model_id', 'manager_agent_id', 'knowledge_base_id'] as $fk) {
            if (array_key_exists($fk, $row)) {
                $row[$fk] = $row[$fk] === '' || $row[$fk] === null ? null : (int) $row[$fk];
            }
        }
        if (! empty($row['room_id'])) {
            $room = model(RoomModel::class)->find($row['room_id']); // tenant scoped: foreign rooms are not found
            if ($room === null) {
                throw new DomainRuleException('Ruangan tidak ditemukan.', 404);
            }
            if (! (int) $room['is_enabled']) {
                throw new DomainRuleException('Ruangan ini terkunci oleh paket Anda.');
            }
        }
        if (! empty($row['ai_model_id']) && Database::connect()->table('ai_models')->where('id', $row['ai_model_id'])->where('is_active', 1)->countAllResults() === 0) {
            throw new DomainRuleException('Model AI tidak tersedia.');
        }
        if (! empty($row['manager_agent_id'])) {
            if ($before !== null && (int) $row['manager_agent_id'] === (int) $before['id']) {
                throw new DomainRuleException('Agent tidak bisa menjadi manager dirinya sendiri.');
            }
            $this->find((int) $row['manager_agent_id']);
            if ($before !== null && $this->isAncestor((int) $before['id'], (int) $row['manager_agent_id'])) {
                throw new DomainRuleException('Struktur manager melingkar tidak diizinkan.');
            }
        }
        if (! empty($row['knowledge_base_id'])) {
            (new KnowledgeService())->find((int) $row['knowledge_base_id']); // tenant scoped: 404 for other tenants
        }
        if (array_key_exists('token_limit_monthly', $row)) {
            $row['token_limit_monthly'] = max(0, (int) $row['token_limit_monthly']);
        }

        return $row;
    }

    /** Is $agentId somewhere above $candidate in the reporting line? */
    private function isAncestor(int $agentId, int $candidate): bool
    {
        $seen = [];
        while ($candidate && ! isset($seen[$candidate])) {
            if ($candidate === $agentId) {
                return true;
            }
            $seen[$candidate] = true;
            $candidate        = (int) (model(AiAgentModel::class)->find($candidate)['manager_agent_id'] ?? 0);
        }

        return false;
    }

    /** "1,2,3" or [1,2,3] → [1,2,3] */
    private function ids(mixed $value): array
    {
        $list = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map('intval', $list)));
    }

    private function defaultModelId(): ?int
    {
        $row = Database::connect()->table('ai_models')->where('model_key', config('Ai')->defaultModel)->where('is_active', 1)->get()->getRow();

        return $row ? (int) $row->id : null;
    }

    private function present(array $a, bool $full): array
    {
        $out = [
            'id'               => (int) $a['id'],
            'name'             => $a['name'],
            'avatar'           => $a['avatar'],
            'job_title'        => $a['job_title'],
            'department'       => $a['department'],
            'job_description'  => $a['job_description'],
            'room_id'          => $a['room_id'] === null ? null : (int) $a['room_id'],
            'room_name'        => $a['room_name'] ?? null,
            'manager_agent_id' => $a['manager_agent_id'] === null ? null : (int) $a['manager_agent_id'],
            'knowledge_base_id' => $a['knowledge_base_id'] === null ? null : (int) $a['knowledge_base_id'],
            'model'            => ['id' => $a['ai_model_id'] === null ? null : (int) $a['ai_model_id'], 'name' => $a['model_name'] ?? null, 'key' => $a['model_key'] ?? null],
            'status'           => $a['status'],
            'is_active'        => (bool) $a['is_active'],
            'tokens_used_month' => (int) $a['tokens_used_month'],
            'tokens_used_total' => (int) $a['tokens_used_total'],
            'token_limit_monthly' => (int) $a['token_limit_monthly'],
            'last_active_at'   => $a['last_active_at'],
        ];
        if ($full) {
            $out['system_prompt'] = $a['system_prompt'];
            $out['effort']        = (json_decode($a['api_config'] ?? '{}', true) ?: [])['effort'] ?? 'medium';
        }

        return $out;
    }

    private function audit(string $action, int $id, ?array $old, ?array $new): void
    {
        Services::auditLogger()->record($action, ['entity_type' => 'ai_agent', 'entity_id' => $id, 'old' => $old, 'new' => $new]);
    }
}
