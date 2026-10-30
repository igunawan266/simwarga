<?php

namespace App\Models;

class AiAgentModel extends TenantScopedModel
{
    public const STATUSES = ['online', 'working', 'idle', 'waiting', 'error', 'offline'];

    protected $table          = 'ai_agents';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $allowedFields  = [
        'tenant_id', 'room_id', 'ai_model_id', 'knowledge_base_id', 'manager_agent_id', 'name', 'avatar',
        'job_title', 'department', 'job_description', 'system_prompt', 'api_config', 'status', 'is_active',
        'token_limit_monthly', 'tokens_used_month', 'tokens_used_total', 'last_active_at',
    ];

    /** Columns safe to send to the browser (system prompt / api config are excluded). */
    public function publicColumns(): static
    {
        $this->select('ai_agents.id, ai_agents.room_id, ai_agents.manager_agent_id, ai_agents.name, ai_agents.avatar, ai_agents.job_title, ai_agents.department, ai_agents.job_description, ai_agents.status, ai_agents.is_active, ai_agents.tokens_used_month, ai_agents.token_limit_monthly, ai_agents.last_active_at');

        return $this;
    }
}
