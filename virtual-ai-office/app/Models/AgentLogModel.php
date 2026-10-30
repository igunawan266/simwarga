<?php

namespace App\Models;

class AgentLogModel extends TenantScopedModel
{
    protected $table         = 'agent_logs';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $updatedField  = '';
    protected $allowedFields = ['tenant_id', 'agent_id', 'task_id', 'level', 'event', 'message', 'context', 'input_tokens', 'output_tokens', 'duration_ms'];
}
