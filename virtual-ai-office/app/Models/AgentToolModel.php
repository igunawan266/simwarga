<?php

namespace App\Models;

class AgentToolModel extends TenantScopedModel
{
    protected $table         = 'ai_agent_tools';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $updatedField  = '';
    protected $allowedFields = ['tenant_id', 'agent_id', 'tool_id'];
}
