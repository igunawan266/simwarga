<?php

namespace App\Models;

class NotificationModel extends TenantScopedModel
{
    protected $table         = 'notifications';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $updatedField  = '';
    protected $allowedFields = ['tenant_id', 'audience', 'type', 'title', 'body', 'data', 'read_at'];
}
