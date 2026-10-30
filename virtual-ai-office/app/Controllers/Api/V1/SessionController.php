<?php

namespace App\Controllers\Api\V1;

use App\Models\TenantMemberModel;
use App\Services\AuthService;
use Config\Services;

/** Login / logout / current session for the single-page 3D portal (JSON). */
class SessionController extends BaseApiController
{
    public function show()
    {
        $user = (new AuthService())->currentUser();

        return $this->ok($user === null ? ['authenticated' => false] : $this->sessionPayload($user));
    }

    public function login()
    {
        $rules = [
            'email'    => 'required|valid_email|max_length[191]',
            'password' => 'required|min_length[8]|max_length[200]',
        ];
        if (! $this->validateData($this->request->getPost(['email', 'password']), $rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $result = (new AuthService())->attempt((string) $this->request->getPost('email'), (string) $this->request->getPost('password'));
        if ($result === null) {
            return $this->failUnauthorized('Email atau password salah.');
        }

        return $this->ok($this->sessionPayload($result['user']));
    }

    public function logout()
    {
        (new AuthService())->logout();

        return $this->ok(['authenticated' => false]);
    }

    private function sessionPayload(array $user): array
    {
        $memberships = model(TenantMemberModel::class)->activeMembershipsFor((int) $user['id']);
        $activeId    = (int) session('active_tenant_id');
        $active      = null;
        foreach ($memberships as $m) {
            if ((int) $m['tenant_id'] === $activeId) {
                $active = $m;
            }
        }

        $office = null;
        if ($active !== null) {
            $row = model(\App\Models\OfficeModel::class)->findForTenant($activeId);
            $office = $row === null ? null : ['number' => $row['office_number'], 'floor_number' => (int) $row['floor_number']];
        }

        return [
            'authenticated' => true,
            'user'          => ['name' => $user['name'], 'email' => $user['email'], 'is_super_admin' => ($user['platform_role'] ?? null) === 'super_admin'],
            'tenant'        => $active === null ? null : ['name' => $active['company_name'], 'role' => $active['tenant_role'], 'status' => $active['tenant_status'], 'office' => $office],
        ];
    }
}
