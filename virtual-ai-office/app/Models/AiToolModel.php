<?php

namespace App\Models;

/** Tenant-defined tools only (platform tools with tenant_id NULL are not exposed here). */
class AiToolModel extends TenantScopedModel
{
    protected $table         = 'ai_tools';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['tenant_id', 'slug', 'name', 'description', 'tool_type', 'input_schema', 'config', 'is_active', 'approval_mode'];
}
