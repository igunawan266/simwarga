<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * RBAC gate. Usage in routes: ['filter' => 'role:super_admin'] or 'role:tenant_owner,tenant_admin'.
 * super_admin is a platform role (users.role_id); the others are tenant roles from the
 * verified TenantContext, so "tenant" must run before this filter for tenant roles.
 */
class RoleFilter implements FilterInterface
{
    use RespondsWithError;

    public function before(RequestInterface $request, $arguments = null)
    {
        $allowed = (array) $arguments;

        if (Services::currentUser()->isSuperAdmin() && in_array('super_admin', $allowed, true)) {
            return;
        }
        if (Services::tenantContext()->hasRole(...$allowed)) {
            return;
        }

        return $this->deny($request, 403, 'Anda tidak memiliki akses ke fitur ini.');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
