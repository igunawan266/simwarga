<?php

namespace App\Models;

class KnowledgeBaseModel extends TenantScopedModel
{
    protected $table          = 'knowledge_bases';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $allowedFields  = ['tenant_id', 'name', 'description', 'source_type', 'content', 'file_path', 'status', 'chunk_count',
        'original_filename', 'mime_type', 'size_bytes', 'char_count', 'error_message'];
}
