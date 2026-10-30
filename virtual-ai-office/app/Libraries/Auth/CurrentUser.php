<?php

namespace App\Libraries\Auth;

/** Request-scoped holder of the authenticated user row (filled by AuthFilter). */
class CurrentUser
{
    private ?array $user = null;

    public function set(array $user): void
    {
        $this->user = $user;
    }

    public function get(): ?array
    {
        return $this->user;
    }

    public function id(): ?int
    {
        return $this->user === null ? null : (int) $this->user['id'];
    }

    public function isSuperAdmin(): bool
    {
        return ($this->user['platform_role'] ?? null) === 'super_admin';
    }
}
