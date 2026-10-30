<?php

namespace App\Database\Migrations;

use App\Database\BaseMigration;

/**
 * buildings, floors, room_types, offices, rooms
 */
class CreateBuildingTables extends BaseMigration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => $this->id(),
            'name'          => $this->str(150),
            'code'          => $this->str(30),
            'total_floors'  => $this->int(12),
            'lobby_floor'   => $this->int(0),
            'theme'         => $this->json(), // colors / visual options for the 3D scene
            'status'        => $this->str(20, false, 'active'),
            ...$this->timestamps(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('code');
        $this->forge->createTable('buildings', true, $this->tableAttributes());

        $this->forge->addField([
            'id'               => $this->id(),
            'building_id'      => $this->fk(),
            'floor_number'     => $this->int(),
            'name'             => $this->str(100),
            'floor_type'       => $this->str(20, false, 'office'), // lobby|office|amenity|rooftop
            'tenant_capacity'  => $this->int(1),
            'base_monthly_price' => $this->money(),
            'status'           => $this->str(20, false, 'available'), // common|available|occupied|maintenance
            ...$this->timestamps(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['building_id', 'floor_number']);
        $this->forge->addKey('status');
        $this->forge->addForeignKey('building_id', 'buildings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('floors', true, $this->tableAttributes());

        // Catalogue of room kinds (lobby, lounge, pantry, ... it_server). Not tenant owned.
        $this->forge->addField([
            'id'          => $this->id(),
            'slug'        => $this->str(50),
            'name'        => $this->str(100),
            'category'    => $this->str(20, false, 'operational'), // common|operational
            'description' => $this->text(),
            'icon'        => $this->str(50, true),
            'layout'      => $this->json(), // default size / furniture preset for the 3D builder
            'sort_order'  => $this->int(0),
            ...$this->timestamps(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('room_types', true, $this->tableAttributes());

        // An office is the leasable unit on a floor. tenant_id is NULL while vacant.
        $this->forge->addField([
            'id'            => $this->id(),
            'floor_id'      => $this->fk(),
            'tenant_id'     => $this->fk(true),
            'office_number' => $this->str(20),
            'name'          => $this->str(150, true),
            'area_sqm'      => $this->int(0),
            'monthly_price' => $this->money(),
            'status'        => $this->str(20, false, 'available'), // available|reserved|occupied|maintenance
            'customization' => $this->json(),
            ...$this->timestamps(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('office_number');
        $this->forge->addKey('tenant_id');
        $this->forge->addKey(['floor_id', 'status']);
        $this->forge->addForeignKey('floor_id', 'floors', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('offices', true, $this->tableAttributes());

        $this->forge->addField([
            'id'           => $this->id(),
            'tenant_id'    => $this->fk(),
            'office_id'    => $this->fk(),
            'room_type_id' => $this->fk(),
            'name'         => $this->str(100),
            'description'  => $this->text(),
            'position'     => $this->json(), // {x,z,w,d} on the floor plate (meters)
            'is_enabled'   => $this->bool(1), // limited by subscription plan
            'sort_order'   => $this->int(0),
            ...$this->timestamps(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'office_id']);
        $this->forge->addUniqueKey(['office_id', 'room_type_id', 'name']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('office_id', 'offices', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('room_type_id', 'room_types', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->createTable('rooms', true, $this->tableAttributes());
    }

    public function down(): void
    {
        $this->forge->dropTable('rooms', true);
        $this->forge->dropTable('offices', true);
        $this->forge->dropTable('room_types', true);
        $this->forge->dropTable('floors', true);
        $this->forge->dropTable('buildings', true);
    }
}
