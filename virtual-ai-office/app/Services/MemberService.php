<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use Config\Database;
use Config\Services;

/**
 * Tenant team management: invitations, roles and removal.
 *
 * Every method takes an explicit tenant id plus the acting role, so the same rules serve
 * the tenant's own owner/admin (tenant id from the verified TenantContext) and Super Admin.
 *
 * Who may do what:
 *  - super_admin  : everything
 *  - tenant_owner : everything inside the tenant, but the tenant always keeps one active owner
 *  - tenant_admin : invite / remove / reinvite staff only
 *  - tenant_staff : read-only
 * Nobody (except super_admin) can change or remove their own membership.
 */
class MemberService
{
    public const ROLES       = ['tenant_owner', 'tenant_admin', 'tenant_staff'];
    public const INVITE_DAYS = 7;

    public function list(int $tenantId): array
    {
        $rows = Database::connect()->table('tenant_members m')
            ->select('m.id, m.status, m.joined_at, m.invite_expires_at, m.created_at, u.id AS user_id, u.name, u.email, r.slug AS role')
            ->join('users u', 'u.id = m.user_id')
            ->join('roles r', 'r.id = m.role_id')
            ->where('m.tenant_id', $tenantId)
            ->orderBy("CASE r.slug WHEN 'tenant_owner' THEN 0 WHEN 'tenant_admin' THEN 1 ELSE 2 END", '', false)
            ->orderBy('m.id')
            ->get()->getResultArray();

        return array_map(static fn (array $m) => [
            'id'                => (int) $m['id'],
            'user_id'           => (int) $m['user_id'],
            'name'              => $m['name'],
            'email'             => $m['email'],
            'role'              => $m['role'],
            'status'            => $m['status'],
            'joined_at'         => $m['joined_at'],
            'invite_expires_at' => $m['status'] === 'invited' ? $m['invite_expires_at'] : null,
        ], $rows);
    }

    /**
     * @return array{member: array, invite_url: string}
     */
    public function invite(int $tenantId, string $email, string $role, string $actorRole, int $actorUserId): array
    {
        $this->assertRole($role);
        $this->assertCanManage($actorRole, null, $role);

        $db     = Database::connect();
        $email  = mb_strtolower(trim($email));
        $tenant = $db->table('tenants')->where('id', $tenantId)->where('deleted_at', null)->get()->getRowArray();
        if ($tenant === null) {
            throw new DomainRuleException('Tenant tidak ditemukan.', 404);
        }

        $user = $db->table('users')->where('email', $email)->where('deleted_at', null)->get()->getRowArray();
        if ($user !== null && $user['status'] === 'suspended') {
            throw new DomainRuleException('Akun dengan email ini sedang disuspend.');
        }
        if ($user !== null) {
            $existing = $db->table('tenant_members')->where('tenant_id', $tenantId)->where('user_id', $user['id'])->get()->getRowArray();
            if ($existing !== null) {
                throw new DomainRuleException($existing['status'] === 'invited'
                    ? 'Email ini sudah diundang. Gunakan "Kirim ulang undangan".'
                    : 'Email ini sudah menjadi anggota tenant.');
            }
        }

        [$token, $hash] = $this->newToken();
        $now = date('Y-m-d H:i:s');

        $db->transException(true)->transStart();
        if ($user === null) {
            // Placeholder account: unusable random password, status "invited" (login refuses it).
            $db->table('users')->insert([
                'role_id'       => $this->roleId('user'),
                'name'          => ucfirst(strtok($email, '@')),
                'email'         => $email,
                'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                'status'        => 'invited',
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
            $userId = (int) $db->insertID();
        } else {
            $userId = (int) $user['id'];
        }
        $db->table('tenant_members')->insert([
            'tenant_id'         => $tenantId,
            'user_id'           => $userId,
            'role_id'           => $this->roleId($role),
            'status'            => 'invited',
            'invited_by'        => $actorUserId,
            'invite_token_hash' => $hash,
            'invite_expires_at' => date('Y-m-d H:i:s', strtotime('+' . self::INVITE_DAYS . ' days')),
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
        $memberId = (int) $db->insertID();
        $db->transComplete();

        Services::auditLogger()->record('member.invite', [
            'tenant_id' => $tenantId, 'entity_type' => 'tenant_member', 'entity_id' => $memberId,
            'new'       => ['email' => $email, 'role' => $role],
        ]);

        return ['member' => $this->find($tenantId, $memberId), 'invite_url' => $this->inviteUrl($token)];
    }

    /** New link for a pending invitation; the old link stops working. */
    public function reinvite(int $tenantId, int $memberId, string $actorRole, int $actorUserId): array
    {
        $member = $this->findOrFail($tenantId, $memberId);
        if ($member['status'] !== 'invited') {
            throw new DomainRuleException('Anggota ini sudah aktif.');
        }
        $this->assertCanManage($actorRole, $member['role'], $member['role']);

        [$token, $hash] = $this->newToken();
        Database::connect()->table('tenant_members')->where('id', $memberId)->update([
            'invite_token_hash' => $hash,
            'invite_expires_at' => date('Y-m-d H:i:s', strtotime('+' . self::INVITE_DAYS . ' days')),
            'invited_by'        => $actorUserId,
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);
        Services::auditLogger()->record('member.reinvite', ['tenant_id' => $tenantId, 'entity_type' => 'tenant_member', 'entity_id' => $memberId]);

        return ['member' => $this->find($tenantId, $memberId), 'invite_url' => $this->inviteUrl($token)];
    }

    /** Change role and/or status (active|disabled). */
    public function update(int $tenantId, int $memberId, array $changes, string $actorRole, int $actorUserId): array
    {
        $member = $this->findOrFail($tenantId, $memberId);
        $this->assertNotSelf($member, $actorRole, $actorUserId);

        $newRole = $changes['role'] ?? $member['role'];
        $this->assertRole($newRole);
        $this->assertCanManage($actorRole, $member['role'], $newRole);

        $set = [];
        if ($newRole !== $member['role']) {
            $set['role_id'] = $this->roleId($newRole);
        }
        if (isset($changes['status']) && $changes['status'] !== $member['status']) {
            if ($member['status'] === 'invited') {
                throw new DomainRuleException('Undangan belum diterima; status belum bisa diubah.');
            }
            $set['status'] = $changes['status'];
        }
        if ($set === []) {
            return $member;
        }
        $losesOwner = $member['role'] === 'tenant_owner' && $member['status'] === 'active'
            && ($newRole !== 'tenant_owner' || ($set['status'] ?? 'active') !== 'active');
        if ($losesOwner) {
            $this->assertAnotherActiveOwner($tenantId, $memberId);
        }

        $set['updated_at'] = date('Y-m-d H:i:s');
        Database::connect()->table('tenant_members')->where('id', $memberId)->where('tenant_id', $tenantId)->update($set);
        Services::auditLogger()->record('member.update', [
            'tenant_id' => $tenantId, 'entity_type' => 'tenant_member', 'entity_id' => $memberId,
            'old' => ['role' => $member['role'], 'status' => $member['status']],
            'new' => ['role' => $newRole, 'status' => $set['status'] ?? $member['status']],
        ]);

        return $this->find($tenantId, $memberId);
    }

    public function remove(int $tenantId, int $memberId, string $actorRole, int $actorUserId): void
    {
        $member = $this->findOrFail($tenantId, $memberId);
        $this->assertNotSelf($member, $actorRole, $actorUserId);
        $this->assertCanManage($actorRole, $member['role'], $member['role']);
        if ($member['role'] === 'tenant_owner' && $member['status'] === 'active') {
            $this->assertAnotherActiveOwner($tenantId, $memberId);
        }

        Database::connect()->table('tenant_members')->where('id', $memberId)->where('tenant_id', $tenantId)->delete();
        Services::auditLogger()->record('member.remove', [
            'tenant_id' => $tenantId, 'entity_type' => 'tenant_member', 'entity_id' => $memberId,
            'old'       => ['email' => $member['email'], 'role' => $member['role']],
        ]);
    }

    // ------------------------------------------------------------------ invitation acceptance

    /** Pending, unexpired invitation for a raw token, or null. */
    public function findInvitation(string $token): ?array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $row = Database::connect()->table('tenant_members m')
            ->select('m.id, m.tenant_id, m.invite_expires_at, u.id AS user_id, u.email, u.name, u.status AS user_status, r.slug AS role, t.company_name')
            ->join('users u', 'u.id = m.user_id')
            ->join('roles r', 'r.id = m.role_id')
            ->join('tenants t', 't.id = m.tenant_id')
            ->where('m.invite_token_hash', hash('sha256', $token))
            ->where('m.status', 'invited')
            ->where('t.deleted_at', null)
            ->get()->getRowArray();

        if ($row === null || strtotime($row['invite_expires_at']) < time()) {
            return null;
        }
        $row['is_new_user'] = $row['user_status'] === 'invited';

        return $row;
    }

    /**
     * New users set their name + password; existing users confirm with their current password.
     *
     * @return array the accepted invitation
     */
    public function accept(string $token, array $input): array
    {
        $invite = $this->findInvitation($token);
        if ($invite === null) {
            throw new DomainRuleException('Undangan tidak valid atau sudah kedaluwarsa.', 404);
        }

        $db   = Database::connect();
        $user = $db->table('users')->where('id', $invite['user_id'])->get()->getRowArray();
        if (! $invite['is_new_user'] && ! password_verify((string) ($input['password'] ?? ''), $user['password_hash'])) {
            throw new DomainRuleException('Password akun Anda salah.');
        }

        $now = date('Y-m-d H:i:s');
        $db->transException(true)->transStart();
        if ($invite['is_new_user']) {
            $db->table('users')->where('id', $user['id'])->update([
                'name'          => trim($input['name']),
                'password_hash' => password_hash($input['password'], PASSWORD_DEFAULT),
                'status'        => 'active',
                'updated_at'    => $now,
            ]);
        }
        // Conditional on the token hash so a link can only ever be used once.
        $db->table('tenant_members')
            ->where('id', $invite['id'])->where('status', 'invited')->where('invite_token_hash', hash('sha256', $token))
            ->update(['status' => 'active', 'joined_at' => $now, 'invite_token_hash' => null, 'invite_expires_at' => null, 'updated_at' => $now]);
        if ($db->affectedRows() !== 1) {
            $db->transRollback();
            throw new DomainRuleException('Undangan sudah digunakan.', 409);
        }
        $db->transComplete();

        Services::auditLogger()->record('member.accept', [
            'tenant_id' => (int) $invite['tenant_id'], 'user_id' => (int) $invite['user_id'],
            'entity_type' => 'tenant_member', 'entity_id' => (int) $invite['id'],
        ]);

        return $invite;
    }

    // ------------------------------------------------------------------ rules & helpers

    private function assertCanManage(string $actorRole, ?string $targetRole, string $newRole): void
    {
        $allowed = match ($actorRole) {
            'super_admin', 'tenant_owner' => true,
            'tenant_admin' => ($targetRole === null || $targetRole === 'tenant_staff') && $newRole === 'tenant_staff',
            default        => false,
        };
        if (! $allowed) {
            throw new DomainRuleException('Peran Anda tidak boleh mengelola anggota dengan peran ini.', 403);
        }
    }

    private function assertNotSelf(array $member, string $actorRole, int $actorUserId): void
    {
        if ($actorRole !== 'super_admin' && $member['user_id'] === $actorUserId) {
            throw new DomainRuleException('Anda tidak dapat mengubah atau menghapus keanggotaan Anda sendiri.');
        }
    }

    private function assertAnotherActiveOwner(int $tenantId, int $exceptMemberId): void
    {
        $owners = Database::connect()->table('tenant_members m')
            ->join('roles r', 'r.id = m.role_id')
            ->where('m.tenant_id', $tenantId)->where('m.status', 'active')->where('r.slug', 'tenant_owner')
            ->where('m.id !=', $exceptMemberId)
            ->countAllResults();
        if ($owners === 0) {
            throw new DomainRuleException('Tenant harus memiliki minimal satu Owner aktif.');
        }
    }

    private function assertRole(string $role): void
    {
        if (! in_array($role, self::ROLES, true)) {
            throw new DomainRuleException('Peran tidak valid.');
        }
    }

    private function find(int $tenantId, int $memberId): ?array
    {
        foreach ($this->list($tenantId) as $m) {
            if ($m['id'] === $memberId) {
                return $m;
            }
        }

        return null;
    }

    private function findOrFail(int $tenantId, int $memberId): array
    {
        return $this->find($tenantId, $memberId) ?? throw new DomainRuleException('Anggota tidak ditemukan.', 404);
    }

    private function roleId(string $slug): int
    {
        return (int) Database::connect()->table('roles')->where('slug', $slug)->get()->getRow()->id;
    }

    /** @return array{0: string, 1: string} raw token, sha256 hash */
    private function newToken(): array
    {
        $token = bin2hex(random_bytes(32));

        return [$token, hash('sha256', $token)];
    }

    private function inviteUrl(string $token): string
    {
        return site_url('invite/' . $token);
    }
}
