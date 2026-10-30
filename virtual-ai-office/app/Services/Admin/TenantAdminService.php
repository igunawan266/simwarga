<?php

namespace App\Services\Admin;

use App\Exceptions\DomainRuleException;
use App\Models\TenantModel;
use Config\Database;
use Config\Services;

/** Super Admin: tenant directory, profile and lifecycle status. */
class TenantAdminService
{
    public const EDITABLE = ['company_name', 'industry', 'business_profile', 'contact_email', 'contact_phone', 'brand_color', 'status', 'is_listed', 'logo_url'];

    public function list(): array
    {
        $db = Database::connect();
        $tenants = $db->table('tenants t')
            ->select('t.id, t.company_name, t.slug, t.status, t.industry, t.brand_color, t.created_at, o.office_number, f.floor_number')
            ->join('offices o', 'o.tenant_id = t.id', 'left')
            ->join('floors f', 'f.id = o.floor_id', 'left')
            ->where('t.deleted_at', null)
            ->orderBy('t.company_name')
            ->get()->getResultArray();

        $agents = array_column($db->table('ai_agents')->select('tenant_id, COUNT(*) AS n')->where('deleted_at', null)->groupBy('tenant_id')->get()->getResultArray(), 'n', 'tenant_id');

        return array_map(fn (array $t) => [
            'id'           => (int) $t['id'],
            'name'         => $t['company_name'],
            'slug'         => $t['slug'],
            'status'       => $t['status'],
            'industry'     => $t['industry'],
            'color'        => $t['brand_color'],
            'office'       => $t['office_number'],
            'floor_number' => $t['floor_number'] === null ? null : (int) $t['floor_number'],
            'agents'       => (int) ($agents[$t['id']] ?? 0),
            'subscription' => $this->subscription((int) $t['id']),
        ], $tenants);
    }

    public function show(int $id): array
    {
        $db     = Database::connect();
        $tenant = model(TenantModel::class)->find($id);
        if ($tenant === null) {
            throw new DomainRuleException('Tenant tidak ditemukan.', 404);
        }
        $office = $db->table('offices o')->select('o.id, o.office_number, o.monthly_price, f.floor_number')
            ->join('floors f', 'f.id = o.floor_id')->where('o.tenant_id', $id)->get()->getRowArray();
        $rooms = $db->table('rooms r')->select('r.id, r.name, r.is_enabled, rt.category')
            ->join('room_types rt', 'rt.id = r.room_type_id')->where('r.tenant_id', $id)->orderBy('r.sort_order')->get()->getResultArray();
        $agents = $db->table('ai_agents')->select('id, name, job_title, department, status, room_id, tokens_used_month')
            ->where('tenant_id', $id)->where('deleted_at', null)->orderBy('id')->get()->getResultArray();
        $members = (new \App\Services\MemberService())->list($id);

        return [
            'tenant'       => array_intersect_key($tenant, array_flip(['id', 'uuid', 'slug', 'created_at', ...self::EDITABLE])),
            'office'       => $office,
            'rooms'        => array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'enabled' => (bool) $r['is_enabled'], 'category' => $r['category']], $rooms),
            'agents'       => $agents,
            'members'      => $members,
            'subscription' => $this->subscription($id),
        ];
    }

    public function create(array $data): array
    {
        $model = model(TenantModel::class);
        $row   = array_intersect_key($data, array_flip(self::EDITABLE));
        $row['slug']   = $this->uniqueSlug($data['company_name']);
        $row['uuid']   = $this->uuid();
        $row['status'] ??= 'pending';
        $id = $model->insert($row, true);

        Services::auditLogger()->record('admin.tenant.create', ['entity_type' => 'tenant', 'entity_id' => $id, 'tenant_id' => $id, 'new' => $row]);

        return $model->find($id);
    }

    public function update(int $id, array $data): array
    {
        $model  = model(TenantModel::class);
        $before = $model->find($id);
        if ($before === null) {
            throw new DomainRuleException('Tenant tidak ditemukan.', 404);
        }
        $changes = array_intersect_key($data, array_flip(self::EDITABLE));
        if ($changes !== []) {
            $model->update($id, $changes);
            Services::auditLogger()->record('admin.tenant.update', [
                'entity_type' => 'tenant', 'entity_id' => $id, 'tenant_id' => $id,
                'old' => array_intersect_key($before, $changes), 'new' => $changes,
            ]);
        }

        return $model->find($id);
    }

    private function subscription(int $tenantId): ?array
    {
        $s = Database::connect()->table('tenant_subscriptions s')
            ->select('s.status, s.starts_at, s.ends_at, p.code AS plan_code, p.name AS plan_name')
            ->join('subscription_plans p', 'p.id = s.plan_id')
            ->where('s.tenant_id', $tenantId)->orderBy('s.id', 'DESC')->get(1)->getRowArray();

        return $s ?: null;
    }

    private function uniqueSlug(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'tenant';
        $base = substr($base, 0, 90);
        $slug = $base;
        $db   = Database::connect();
        for ($i = 2; $db->table('tenants')->where('slug', $slug)->countAllResults() > 0; $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function uuid(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
