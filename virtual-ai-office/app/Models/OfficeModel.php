<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Offices are platform inventory (they exist while vacant, tenant_id NULL), so the model
 * is not tenant scoped. Tenant-facing code must use findForTenant().
 */
class OfficeModel extends Model
{
    protected $table         = 'offices';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['floor_id', 'tenant_id', 'office_number', 'name', 'area_sqm', 'monthly_price', 'status', 'customization'];

    public function findForTenant(int $tenantId): ?array
    {
        return $this->select('offices.*, floors.floor_number, floors.name AS floor_name')
            ->join('floors', 'floors.id = offices.floor_id')
            ->where('offices.tenant_id', $tenantId)
            ->orderBy('floors.floor_number')
            ->first();
    }
}
