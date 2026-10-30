<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table          = 'users';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $allowedFields  = ['role_id', 'name', 'email', 'password_hash', 'avatar_url', 'status', 'last_login_at', 'last_login_ip'];

    public function findActiveByEmail(string $email): ?array
    {
        return $this->select('users.*, roles.slug AS platform_role')
            ->join('roles', 'roles.id = users.role_id')
            ->where('users.email', mb_strtolower(trim($email)))
            ->where('users.status', 'active')
            ->first();
    }

    public function findWithRole(int $id): ?array
    {
        return $this->select('users.id, users.name, users.email, users.avatar_url, users.status, roles.slug AS platform_role')
            ->join('roles', 'roles.id = users.role_id')
            ->find($id);
    }
}
