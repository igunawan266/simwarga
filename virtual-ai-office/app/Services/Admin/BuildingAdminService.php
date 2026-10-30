<?php

namespace App\Services\Admin;

use App\Exceptions\DomainRuleException;
use App\Services\RoomProvisioner;
use Config\Database;
use Config\Services;

/**
 * Super Admin: floor / office inventory and leasing an office to a tenant.
 * Cross-tenant by nature, so it works on explicit tenant ids (never TenantContext).
 */
class BuildingAdminService
{
    public function floors(): array
    {
        $db   = Database::connect();
        $rows = $db->table('floors f')
            ->select('f.id AS floor_id, f.floor_number, f.name AS floor_name, f.floor_type, f.status AS floor_status, f.base_monthly_price,
                      o.id AS office_id, o.office_number, o.monthly_price, o.status AS office_status, o.area_sqm,
                      t.id AS tenant_id, t.company_name, t.status AS tenant_status, t.brand_color')
            ->join('offices o', 'o.floor_id = f.id', 'left')
            ->join('tenants t', 't.id = o.tenant_id AND t.deleted_at IS NULL', 'left')
            ->orderBy('f.floor_number')
            ->get()->getResultArray();

        return array_map(static fn (array $r) => [
            'floor'  => ['id' => (int) $r['floor_id'], 'number' => (int) $r['floor_number'], 'name' => $r['floor_name'], 'type' => $r['floor_type'], 'status' => $r['floor_status']],
            'office' => $r['office_id'] === null ? null : [
                'id' => (int) $r['office_id'], 'number' => $r['office_number'], 'monthly_price' => (int) $r['monthly_price'],
                'status' => $r['office_status'], 'area_sqm' => (int) $r['area_sqm'],
            ],
            'tenant' => $r['tenant_id'] === null ? null : [
                'id' => (int) $r['tenant_id'], 'name' => $r['company_name'], 'status' => $r['tenant_status'], 'color' => $r['brand_color'],
            ],
        ], $rows);
    }

    public function updateOffice(int $officeId, array $data): array
    {
        $db     = Database::connect();
        $office = $db->table('offices')->where('id', $officeId)->get()->getRowArray();
        if ($office === null) {
            throw new DomainRuleException('Kantor tidak ditemukan.', 404);
        }
        $changes = array_intersect_key($data, array_flip(['monthly_price', 'status', 'name', 'area_sqm']));
        if (isset($changes['status']) && $office['tenant_id'] !== null && $changes['status'] !== 'occupied') {
            throw new DomainRuleException('Kantor sedang disewa. Kosongkan dulu sebelum mengubah status.');
        }
        if ($changes === []) {
            return $office;
        }
        $changes['updated_at'] = date('Y-m-d H:i:s');
        $db->table('offices')->where('id', $officeId)->update($changes);
        Services::auditLogger()->record('admin.office.update', [
            'entity_type' => 'office', 'entity_id' => $officeId, 'tenant_id' => $office['tenant_id'],
            'old' => array_intersect_key($office, $changes), 'new' => $changes,
        ]);

        return $db->table('offices')->where('id', $officeId)->get()->getRowArray();
    }

    /** Lease an available office to a tenant and provision its rooms — all or nothing. */
    public function assign(int $officeId, int $tenantId): array
    {
        $db     = Database::connect();
        $tenant = $db->table('tenants')->where('id', $tenantId)->where('deleted_at', null)->get()->getRowArray();
        if ($tenant === null) {
            throw new DomainRuleException('Tenant tidak ditemukan.', 404);
        }
        if ($tenant['status'] === 'suspended') {
            throw new DomainRuleException('Tenant sedang disuspend.');
        }

        $plan       = $this->planOf($tenantId);
        $maxOffices = $plan === null ? 1 : max(1, (int) $plan['max_offices']);
        $owned      = $db->table('offices')->where('tenant_id', $tenantId)->countAllResults();
        if ($owned >= $maxOffices) {
            throw new DomainRuleException("Tenant sudah memiliki {$owned} kantor (batas paket: {$maxOffices}).");
        }

        $db->transException(true)->transStart();
        // Conditional update = atomic claim: two admins cannot lease the same office twice.
        $db->table('offices')
            ->where('id', $officeId)->where('tenant_id', null)->where('status', 'available')
            ->update(['tenant_id' => $tenantId, 'status' => 'occupied', 'updated_at' => date('Y-m-d H:i:s')]);
        if ($db->affectedRows() !== 1) {
            $db->transRollback();
            throw new DomainRuleException('Kantor tidak tersedia (sudah disewa atau dalam maintenance).');
        }
        $office = $db->table('offices')->where('id', $officeId)->get()->getRowArray();
        (new RoomProvisioner($db))->provision($tenantId, $officeId, $plan);
        $this->refreshFloorStatus((int) $office['floor_id']);
        $db->table('tenant_subscriptions')->where('tenant_id', $tenantId)->where('office_id', null)
            ->update(['office_id' => $officeId]);
        $db->transComplete();

        Services::auditLogger()->record('admin.office.assign', [
            'entity_type' => 'office', 'entity_id' => $officeId, 'tenant_id' => $tenantId,
            'new' => ['tenant_id' => $tenantId, 'office_number' => $office['office_number']],
        ]);

        return $office;
    }

    /**
     * End a lease: the tenant's rooms in this office are removed (agents stay, unassigned)
     * and the office returns to inventory.
     */
    public function release(int $officeId): void
    {
        $db     = Database::connect();
        $office = $db->table('offices')->where('id', $officeId)->get()->getRowArray();
        if ($office === null) {
            throw new DomainRuleException('Kantor tidak ditemukan.', 404);
        }
        if ($office['tenant_id'] === null) {
            throw new DomainRuleException('Kantor ini sudah kosong.');
        }
        $tenantId = (int) $office['tenant_id'];

        $db->transException(true)->transStart();
        $roomIds = array_column($db->table('rooms')->select('id')->where('office_id', $officeId)->get()->getResultArray(), 'id');
        if ($roomIds !== []) {
            $db->table('ai_agents')->whereIn('room_id', $roomIds)->update(['room_id' => null]);
            $db->table('rooms')->whereIn('id', $roomIds)->delete();
        }
        $db->table('tenant_subscriptions')->where('office_id', $officeId)->update(['office_id' => null]);
        $db->table('offices')->where('id', $officeId)
            ->update(['tenant_id' => null, 'status' => 'available', 'updated_at' => date('Y-m-d H:i:s')]);
        $this->refreshFloorStatus((int) $office['floor_id']);
        $db->transComplete();

        Services::auditLogger()->record('admin.office.release', [
            'entity_type' => 'office', 'entity_id' => $officeId, 'tenant_id' => $tenantId,
            'old' => ['tenant_id' => $tenantId], 'new' => ['tenant_id' => null, 'rooms_removed' => count($roomIds)],
        ]);
    }

    /** Plan that governs the tenant: current usable subscription, else the latest pending one. */
    public function planOf(int $tenantId): ?array
    {
        return Database::connect()->table('tenant_subscriptions s')
            ->select('p.*')
            ->join('subscription_plans p', 'p.id = s.plan_id')
            ->where('s.tenant_id', $tenantId)
            ->whereIn('s.status', ['trial', 'active', 'pending', 'past_due'])
            ->orderBy("CASE WHEN s.status IN ('trial','active') THEN 0 ELSE 1 END", '', false)
            ->orderBy('s.id', 'DESC')
            ->get(1)->getRowArray();
    }

    private function refreshFloorStatus(int $floorId): void
    {
        $db       = Database::connect();
        $floor    = $db->table('floors')->where('id', $floorId)->get()->getRowArray();
        $occupied = $db->table('offices')->where('floor_id', $floorId)->where('tenant_id IS NOT NULL', null, false)->countAllResults();
        if ($floor['floor_type'] !== 'office' || $floor['status'] === 'maintenance') {
            return;
        }
        $status = $occupied >= max(1, (int) $floor['tenant_capacity']) ? 'occupied' : 'available';
        $db->table('floors')->where('id', $floorId)->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
