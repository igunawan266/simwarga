<?php

namespace App\Models;

use App\Libraries\Tenancy\TenantContext;
use CodeIgniter\Model;
use Config\Services;

/**
 * Base model for every tenant-owned table.
 *
 * Every read, update, delete and count is constrained to `tenant_id = <current tenant>`
 * and every insert is stamped with it. If no verified tenant context exists the query
 * fails loudly (TenantContextException) instead of silently returning other tenants' rows.
 *
 * Platform code that legitimately needs cross-tenant access (Super Admin, cron jobs,
 * payment webhooks) must opt out explicitly with withoutTenantScope(), which makes
 * such places easy to audit with a grep.
 */
abstract class TenantScopedModel extends Model
{
    protected string $tenantColumn = 'tenant_id';

    private bool $scopeDisabled = false;

    protected $beforeInsert = ['stampTenant'];
    protected $beforeInsertBatch = ['stampTenantBatch'];
    protected $beforeUpdate = ['protectTenantColumn'];

    protected function tenantContext(): TenantContext
    {
        return Services::tenantContext();
    }

    /** Next query only runs without the tenant constraint. */
    public function withoutTenantScope(): static
    {
        $this->scopeDisabled = true;

        return $this;
    }

    private function applyScope(): void
    {
        if ($this->scopeDisabled) {
            $this->scopeDisabled = false;

            return;
        }
        $this->builder()->where($this->table . '.' . $this->tenantColumn, $this->tenantContext()->id());
    }

    protected function doFind(bool $singleton, $id = null)
    {
        $this->applyScope();

        return parent::doFind($singleton, $id);
    }

    protected function doFindAll(?int $limit = null, int $offset = 0)
    {
        $this->applyScope();

        return parent::doFindAll($limit, $offset);
    }

    protected function doFirst()
    {
        $this->applyScope();

        return parent::doFirst();
    }

    protected function doFindColumn(string $columnName)
    {
        // delegates to find(), which is already scoped
        return parent::doFindColumn($columnName);
    }

    protected function doUpdate($id = null, $row = null): bool
    {
        $this->applyScope();

        return parent::doUpdate($id, $row);
    }

    protected function doDelete($id = null, bool $purge = false)
    {
        $this->applyScope();

        return parent::doDelete($id, $purge);
    }

    public function countAllResults(bool $reset = true, bool $test = false)
    {
        $this->applyScope();

        return parent::countAllResults($reset, $test);
    }

    protected function stampTenant(array $data): array
    {
        $explicit            = $this->scopeDisabled && isset($data['data'][$this->tenantColumn]);
        $this->scopeDisabled = false;
        if ($explicit) {
            return $data; // platform code inserting for an explicit tenant
        }
        $data['data'][$this->tenantColumn] = $this->tenantContext()->id();

        return $data;
    }

    protected function stampTenantBatch(array $data): array
    {
        $tenantId = $this->tenantContext()->id();
        foreach ($data['data'] as $i => $row) {
            $data['data'][$i][$this->tenantColumn] = $tenantId;
        }

        return $data;
    }

    /** A row can never be moved to another tenant through a model update. */
    protected function protectTenantColumn(array $data): array
    {
        unset($data['data'][$this->tenantColumn]);

        return $data;
    }
}
