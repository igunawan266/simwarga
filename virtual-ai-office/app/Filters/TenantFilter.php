<?php

namespace App\Filters;

use App\Models\TenantMemberModel;
use App\Models\TenantModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Resolves the tenant for this request from the session's active_tenant_id and verifies,
 * against tenant_members, that the logged-in user really is an active member of it.
 * Must run after "auth". The tenant id is never taken from URL, body or headers.
 */
class TenantFilter implements FilterInterface
{
    use RespondsWithError;

    public function before(RequestInterface $request, $arguments = null)
    {
        $userId   = (int) session('user_id');
        $tenantId = (int) session('active_tenant_id');

        $membership = $tenantId > 0 ? model(TenantMemberModel::class)->membership($userId, $tenantId) : null;
        $tenant     = $membership ? model(TenantModel::class)->find($tenantId) : null;

        if ($membership === null || $tenant === null) {
            Services::tenantContext()->clear();

            return $this->deny($request, 403, 'Akun ini tidak terdaftar pada kantor tenant mana pun.');
        }

        Services::tenantContext()->set($tenant, $membership['tenant_role']);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
