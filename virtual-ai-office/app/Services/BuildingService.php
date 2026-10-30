<?php

namespace App\Services;

use App\Models\BuildingModel;
use Config\Database;

/**
 * Public building directory used by the 3D exterior view.
 *
 * Only directory-safe fields leave this service (company name, colour, industry, floor and
 * office number). Agent counts are reduced to a coarse activity level for the window lights.
 */
class BuildingService
{
    public function directory(string $code = 'VAOT'): ?array
    {
        $building = model(BuildingModel::class)->where('code', $code)->first();
        if ($building === null) {
            return null;
        }

        $db   = Database::connect();
        $rows = $db->table('floors f')
            ->select('f.id, f.floor_number, f.name, f.floor_type, f.status, o.office_number, o.status AS office_status,
                      t.id AS tenant_id, t.company_name, t.brand_color, t.industry, t.logo_url, t.is_listed')
            ->join('offices o', 'o.floor_id = f.id', 'left')
            ->join('tenants t', "t.id = o.tenant_id AND t.status = 'active' AND t.deleted_at IS NULL", 'left')
            ->where('f.building_id', $building['id'])
            ->orderBy('f.floor_number')
            ->get()->getResultArray();

        $activity = [];
        foreach ($db->table('ai_agents')
            ->select('tenant_id, COUNT(*) AS total, SUM(CASE WHEN status IN (\'working\',\'online\') THEN 1 ELSE 0 END) AS busy')
            ->where('deleted_at', null)->where('is_active', 1)
            ->groupBy('tenant_id')->get()->getResultArray() as $a) {
            $activity[(int) $a['tenant_id']] = $a['total'] > 0 ? (int) ceil(3 * $a['busy'] / $a['total']) : 0;
        }

        $floors = [];
        foreach ($rows as $r) {
            $n = (int) $r['floor_number'];
            $floors[$n] ??= [
                'number' => $n,
                'name'   => $r['name'],
                'type'   => $r['floor_type'],
                'status' => $r['status'],
                'office' => null,
                'tenant' => null,
            ];
            if ($r['office_number'] !== null) {
                $floors[$n]['office'] = ['number' => $r['office_number'], 'status' => $r['office_status']];
            }
            if ($r['tenant_id'] !== null) {
                $listed = (bool) $r['is_listed'];
                $floors[$n]['tenant'] = [
                    'name'           => $listed ? $r['company_name'] : 'Private Tenant',
                    'color'          => $r['brand_color'],
                    'industry'       => $listed ? $r['industry'] : null,
                    'logo_url'       => $listed ? $r['logo_url'] : null,
                    'activity_level' => $activity[(int) $r['tenant_id']] ?? 0,
                ];
            }
        }

        return [
            'building' => [
                'name'         => $building['name'],
                'code'         => $building['code'],
                'total_floors' => (int) $building['total_floors'],
                'theme'        => json_decode($building['theme'] ?? '{}', true),
            ],
            'floors' => array_values($floors),
        ];
    }
}
