<?php

namespace App\Models;

class ToolCallModel extends TenantScopedModel
{
    protected $table         = 'tool_calls';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['tenant_id', 'task_id', 'agent_id', 'tool_id', 'round', 'tool_use_id', 'tool_name', 'input', 'status', 'result',
        'is_error', 'http_status', 'duration_ms', 'decided_by_user_id', 'decision_note', 'decided_at', 'executed_at'];
}
