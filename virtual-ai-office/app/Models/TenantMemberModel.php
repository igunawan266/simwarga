<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Membership lookups are the trust anchor of tenant isolation, so this model is not
 * tenant scoped: it is what TenantFilter uses to decide which tenant a user may act for.
 */
class TenantMemberModel extends Model
{
    protected $table         = 'tenant_members';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['tenant_id', 'user_id', 'role_id', 'status', 'joined_at', 'invited_by', 'invite_token_hash', 'invite_expires_at'];

    /** Active memberships of a user in active tenants, with the tenant role slug. */
    public function activeMembershipsFor(int $userId): array
    {
        return $this->select('tenant_members.tenant_id, roles.slug AS tenant_role, tenants.company_name, tenants.slug')
            ->join('tenants', 'tenants.id = tenant_members.tenant_id')
            ->join('roles', 'roles.id = tenant_members.role_id')
            ->where('tenant_members.user_id', $userId)
            ->where('tenant_members.status', 'active')
            ->where('tenants.deleted_at', null)
            ->whereIn('tenants.status', ['active'])
            ->orderBy('tenant_members.id')
            ->findAll();
    }

    public function membership(int $userId, int $tenantId): ?array
    {
        foreach ($this->activeMembershipsFor($userId) as $m) {
            if ((int) $m['tenant_id'] === $tenantId) {
                return $m;
            }
        }

        return null;
    }
}
