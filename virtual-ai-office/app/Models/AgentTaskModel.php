<?php

namespace App\Models;

class AgentTaskModel extends TenantScopedModel
{
    protected $table         = 'agent_tasks';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'tenant_id', 'parent_task_id', 'agent_id', 'assigned_by_user_id', 'assigned_by_agent_id', 'title', 'instruction',
        'priority', 'status', 'result', 'error_message', 'input_tokens', 'output_tokens', 'attempts', 'queued_at', 'started_at', 'completed_at', 'sources', 'state', 'tool_rounds',
    ];
}
