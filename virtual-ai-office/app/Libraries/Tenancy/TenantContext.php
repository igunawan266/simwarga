<?php

namespace App\Libraries\Tenancy;

use App\Exceptions\TenantContextException;

/**
 * Request-scoped holder of the tenant the current request acts for.
 *
 * It is only ever filled by TenantFilter after the membership has been verified
 * against the database — never from a value supplied by the browser.
 */
class TenantContext
{
    private ?int $tenantId = null;
    private ?string $tenantRole = null;
    private ?array $tenant = null;

    public function set(array $tenant, string $tenantRole): void
    {
        $this->tenantId   = (int) $tenant['id'];
        $this->tenant     = $tenant;
        $this->tenantRole = $tenantRole;
    }

    public function clear(): void
    {
        $this->tenantId = $this->tenantRole = $this->tenant = null;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }

    public function id(): int
    {
        if ($this->tenantId === null) {
            throw new TenantContextException('No tenant context for this request.');
        }

        return $this->tenantId;
    }

    public function tenant(): ?array
    {
        return $this->tenant;
    }

    public function role(): ?string
    {
        return $this->tenantRole;
    }

    public function hasRole(string ...$roles): bool
    {
        return $this->tenantRole !== null && in_array($this->tenantRole, $roles, true);
    }
}
