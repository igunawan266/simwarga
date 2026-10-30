<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\MembersController;
use Config\Database;

/**
 * /api/v1/admin/tenants/{tenant}/members — Super Admin managing any tenant's team
 * (typically inviting the first Owner of a tenant it just created).
 */
class TenantMembersController extends MembersController
{
    private int $tenant = 0;

    public function inviteFor(int $tenantId)
    {
        return $this->withTenant($tenantId, fn () => $this->invite());
    }

    public function updateFor(int $tenantId, int $id)
    {
        return $this->withTenant($tenantId, fn () => $this->update($id));
    }

    public function removeFor(int $tenantId, int $id)
    {
        return $this->withTenant($tenantId, fn () => $this->remove($id));
    }

    public function reinviteFor(int $tenantId, int $id)
    {
        return $this->withTenant($tenantId, fn () => $this->reinvite($id));
    }

    private function withTenant(int $tenantId, callable $fn)
    {
        if (Database::connect()->table('tenants')->where('id', $tenantId)->where('deleted_at', null)->countAllResults() === 0) {
            return $this->failNotFound('Tenant tidak ditemukan.');
        }
        $this->tenant = $tenantId;

        return $fn();
    }

    protected function tenantId(): int
    {
        return $this->tenant;
    }

    protected function role(): string
    {
        return 'super_admin'; // route is guarded by role:super_admin
    }
}
