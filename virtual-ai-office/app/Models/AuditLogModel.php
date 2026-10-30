<?php

namespace App\Models;

use CodeIgniter\Model;

/** Append-only: the app never updates or deletes audit rows. */
class AuditLogModel extends Model
{
    protected $table         = 'audit_logs';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $updatedField  = '';
    protected $allowedFields = ['tenant_id', 'user_id', 'agent_id', 'action', 'entity_type', 'entity_id', 'old_values', 'new_values', 'ip_address', 'user_agent'];
}
