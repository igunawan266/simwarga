<?php

namespace App\Services;

use App\Models\AuditLogModel;
use Config\Services;
use Throwable;

/** Writes audit_logs rows. Failures are logged but never break the user's request. */
class AuditService
{
    public function record(string $action, array $attributes = []): void
    {
        $request = Services::request();
        $context = Services::tenantContext();

        $row = [
            'tenant_id'   => $attributes['tenant_id'] ?? ($context->has() ? $context->id() : null),
            'user_id'     => $attributes['user_id'] ?? session('user_id'),
            'agent_id'    => $attributes['agent_id'] ?? null,
            'action'      => $action,
            'entity_type' => $attributes['entity_type'] ?? null,
            'entity_id'   => $attributes['entity_id'] ?? null,
            'old_values'  => isset($attributes['old']) ? json_encode($attributes['old']) : null,
            'new_values'  => isset($attributes['new']) ? json_encode($attributes['new']) : null,
            'ip_address'  => method_exists($request, 'getIPAddress') ? $request->getIPAddress() : null,
            'user_agent'  => method_exists($request, 'getUserAgent') ? mb_substr((string) $request->getUserAgent(), 0, 255) : null,
        ];

        try {
            model(AuditLogModel::class)->insert($row);
        } catch (Throwable $e) {
            log_message('error', 'Audit log write failed for {action}: {msg}', ['action' => $action, 'msg' => $e->getMessage()]);
        }
    }
}
