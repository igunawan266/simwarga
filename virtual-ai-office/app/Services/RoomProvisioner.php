<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Creates a tenant's rooms inside an office from the room_types catalogue.
 * Rooms the plan does not include are still created (so the 3D layout is complete)
 * but with is_enabled = 0, which the UI shows as locked.
 */
class RoomProvisioner
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    /**
     * @param array|null $plan subscription_plans row; null = no plan yet (common rooms only)
     *
     * @return array<string, int> room_type slug => room id
     */
    public function provision(int $tenantId, int $officeId, ?array $plan): array
    {
        $now   = date('Y-m-d H:i:s');
        $rooms = [];
        foreach ($this->db->table('room_types')->orderBy('sort_order')->get()->getResultArray() as $type) {
            $this->db->table('rooms')->insert([
                'tenant_id'    => $tenantId,
                'office_id'    => $officeId,
                'room_type_id' => $type['id'],
                'name'         => $type['name'],
                'description'  => $type['description'],
                'position'     => $type['layout'],
                'is_enabled'   => $this->allows($plan, $type) ? 1 : 0,
                'sort_order'   => $type['sort_order'],
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            $rooms[$type['slug']] = (int) $this->db->insertID();
        }

        return $rooms;
    }

    public function allows(?array $plan, array $roomType): bool
    {
        if ($plan === null) {
            return $roomType['category'] === 'common';
        }
        if (empty($plan['allowed_room_types'])) {
            return true; // null = every room type
        }
        $allowed = is_array($plan['allowed_room_types']) ? $plan['allowed_room_types'] : json_decode($plan['allowed_room_types'], true);

        return in_array($roomType['slug'], (array) $allowed, true);
    }
}
