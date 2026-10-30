<?php

namespace App\Filters;

use App\Models\UserModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/** Requires a logged-in, still-active user. Re-checks the user row on every request. */
class AuthFilter implements FilterInterface
{
    use RespondsWithError;

    public function before(RequestInterface $request, $arguments = null)
    {
        $userId = session('user_id');
        $user   = $userId ? model(UserModel::class)->findWithRole((int) $userId) : null;

        if ($user === null || $user['status'] !== 'active') {
            session()->remove(['user_id', 'active_tenant_id']);

            return $this->deny($request, 401, 'Silakan login terlebih dahulu.');
        }

        Services::currentUser()->set($user);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
