<?php

namespace App\Models;

class KnowledgeChunkModel extends TenantScopedModel
{
    protected $table         = 'knowledge_chunks';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $updatedField  = '';
    protected $allowedFields = ['tenant_id', 'knowledge_base_id', 'seq', 'content', 'char_count'];
}
