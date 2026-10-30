<?php

namespace App\Services\Admin;

use App\Exceptions\DomainRuleException;
use Config\Database;
use Config\Services;

/**
 * Super Admin: subscription plans. Running subscriptions keep their price_snapshot /
 * limits_snapshot, so editing a plan only affects new purchases and renewals.
 */
class PlanAdminService
{
    public const EDITABLE = ['name', 'description', 'price', 'billing_period', 'duration_days', 'max_offices', 'max_agents', 'max_operational_rooms', 'monthly_token_quota', 'allowed_room_types', 'features', 'is_custom', 'is_active', 'sort_order'];

    public function list(): array
    {
        $rows = Database::connect()->table('subscription_plans')->where('deleted_at', null)->orderBy('sort_order')->get()->getResultArray();

        return array_map([$this, 'present'], $rows);
    }

    public function create(array $data): array
    {
        $db  = Database::connect();
        $row = $this->normalize($data);
        $row['code'] = strtoupper(preg_replace('/[^A-Z0-9_]/i', '', $data['code']));
        if ($db->table('subscription_plans')->where('code', $row['code'])->countAllResults() > 0) {
            throw new DomainRuleException('Kode paket sudah dipakai.');
        }
        $row['created_at'] = $row['updated_at'] = date('Y-m-d H:i:s');
        $db->table('subscription_plans')->insert($row);
        $id = (int) $db->insertID();
        Services::auditLogger()->record('admin.plan.create', ['entity_type' => 'subscription_plan', 'entity_id' => $id, 'new' => $row]);

        return $this->present($db->table('subscription_plans')->where('id', $id)->get()->getRowArray());
    }

    public function update(int $id, array $data): array
    {
        $db     = Database::connect();
        $before = $db->table('subscription_plans')->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
        if ($before === null) {
            throw new DomainRuleException('Paket tidak ditemukan.', 404);
        }
        $changes = $this->normalize($data);
        if ($changes !== []) {
            $changes['updated_at'] = date('Y-m-d H:i:s');
            $db->table('subscription_plans')->where('id', $id)->update($changes);
            Services::auditLogger()->record('admin.plan.update', [
                'entity_type' => 'subscription_plan', 'entity_id' => $id,
                'old' => array_intersect_key($before, $changes), 'new' => $changes,
            ]);
        }

        return $this->present($db->table('subscription_plans')->where('id', $id)->get()->getRowArray());
    }

    private function normalize(array $data): array
    {
        $row = array_intersect_key($data, array_flip(self::EDITABLE));
        foreach (['allowed_room_types', 'features'] as $json) {
            if (array_key_exists($json, $row)) {
                $value      = is_string($row[$json]) ? json_decode($row[$json], true) : $row[$json];
                $row[$json] = $value === null || $value === '' ? null : json_encode($value);
            }
        }

        return $row;
    }

    private function present(array $p): array
    {
        return [
            'id'                    => (int) $p['id'],
            'code'                  => $p['code'],
            'name'                  => $p['name'],
            'description'           => $p['description'],
            'price'                 => (int) $p['price'],
            'billing_period'        => $p['billing_period'],
            'duration_days'         => (int) $p['duration_days'],
            'max_offices'           => (int) $p['max_offices'],
            'max_agents'            => (int) $p['max_agents'],
            'max_operational_rooms' => (int) $p['max_operational_rooms'],
            'monthly_token_quota'   => (int) $p['monthly_token_quota'],
            'allowed_room_types'    => $p['allowed_room_types'] ? json_decode($p['allowed_room_types'], true) : null,
            'features'              => $p['features'] ? json_decode($p['features'], true) : [],
            'is_custom'             => (bool) $p['is_custom'],
            'is_active'             => (bool) $p['is_active'],
        ];
    }
}
