<?php

namespace App\Services;

use App\Models\TenantMemberModel;
use App\Models\UserModel;
use Config\Services;

/**
 * Session based authentication.
 *
 * The session stores only identifiers (user_id, active_tenant_id). Roles and memberships
 * are re-read from the database on every request by the filters, so revoking a member
 * takes effect immediately.
 */
class AuthService
{
    public function __construct(
        private readonly UserModel $users = new UserModel(),
        private readonly TenantMemberModel $members = new TenantMemberModel(),
    ) {
    }

    /**
     * @return array{user: array, memberships: list<array>}|null
     */
    public function attempt(string $email, string $password): ?array
    {
        $user = $this->users->findActiveByEmail($email);
        // Unknown e-mail still pays for one hash check so response timing does not reveal it.
        $hash = $user['password_hash'] ?? password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);

        if (! password_verify($password, $hash) || $user === null) {
            Services::auditLogger()->record('auth.login_failed', ['new' => ['email' => mb_substr($email, 0, 191)]]);

            return null;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->users->update($user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }

        $memberships = $this->members->activeMembershipsFor((int) $user['id']);

        $session = session();
        $session->regenerate(true); // prevent session fixation
        $session->set([
            'user_id'          => (int) $user['id'],
            'active_tenant_id' => $memberships[0]['tenant_id'] ?? null,
            'logged_in_at'     => time(),
        ]);

        $this->users->update($user['id'], [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => Services::request()->getIPAddress(),
        ]);
        Services::auditLogger()->record('auth.login', [
            'user_id'   => (int) $user['id'],
            'tenant_id' => $memberships[0]['tenant_id'] ?? null,
        ]);

        return ['user' => $user, 'memberships' => $memberships];
    }

    public function logout(): void
    {
        if (session('user_id')) {
            Services::auditLogger()->record('auth.logout');
        }
        session()->destroy();
    }

    public function currentUser(): ?array
    {
        $id = session('user_id');

        return $id ? $this->users->findWithRole((int) $id) : null;
    }
}
