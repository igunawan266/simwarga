<?php

namespace App\Models;

class RoomModel extends TenantScopedModel
{
    protected $table         = 'rooms';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['tenant_id', 'office_id', 'room_type_id', 'name', 'description', 'position', 'is_enabled', 'sort_order'];

    public function withType(): static
    {
        $this->select('rooms.*, room_types.slug AS type_slug, room_types.category, room_types.icon')
            ->join('room_types', 'room_types.id = rooms.room_type_id')
            ->orderBy('rooms.sort_order');

        return $this;
    }
}
