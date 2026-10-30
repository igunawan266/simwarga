<?php

namespace App\Database\Migrations;

use App\Database\BaseMigration;

/**
 * roles, users, tenants, tenant_members
 */
class CreateIdentityTables extends BaseMigration
{
    public function up(): void
    {
        // roles: scope "platform" (super_admin, user) or "tenant" (owner/admin/staff/ai_agent)
        $this->forge->addField([
            'id'          => $this->id(),
            'slug'        => $this->str(50),
            'name'        => $this->str(100),
            'scope'       => $this->str(20, false, 'tenant'),
            'permissions' => $this->json(),
            ...$this->timestamps(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('roles', true, $this->tableAttributes());

        $this->forge->addField([
            'id'              => $this->id(),
            'role_id'         => $this->fk(),
            'name'            => $this->str(150),
            'email'           => $this->str(191),
            'password_hash'   => $this->str(255),
            'avatar_url'      => $this->str(255, true),
            'status'          => $this->str(20, false, 'active'), // active|suspended
            'last_login_at'   => $this->datetime(),
            'last_login_ip'   => $this->str(45, true),
            ...$this->timestamps(true),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('email');
        $this->forge->addKey('role_id');
        $this->forge->addForeignKey('role_id', 'roles', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('users', true, $this->tableAttributes());

        $this->forge->addField([
            'id'             => $this->id(),
            'uuid'           => $this->str(36),
            'slug'           => $this->str(100),
            'company_name'   => $this->str(150),
            'logo_url'       => $this->str(255, true),
            'brand_color'    => $this->str(9, false, '#22d3ee'),
            'industry'       => $this->str(100, true),
            'business_profile' => $this->text(),
            'contact_email'  => $this->str(191, true),
            'contact_phone'  => $this->str(30, true),
            'status'         => $this->str(20, false, 'pending'), // pending|active|suspended|expired
            'is_listed'      => $this->bool(1), // shown on the public building directory
            ...$this->timestamps(true),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('uuid');
        $this->forge->addUniqueKey('slug');
        $this->forge->addKey('status');
        $this->forge->createTable('tenants', true, $this->tableAttributes());

        $this->forge->addField([
            'id'         => $this->id(),
            'tenant_id'  => $this->fk(),
            'user_id'    => $this->fk(),
            'role_id'    => $this->fk(),
            'status'     => $this->str(20, false, 'active'), // invited|active|disabled
            'joined_at'  => $this->datetime(),
            ...$this->timestamps(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'user_id']);
        $this->forge->addKey('user_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('role_id', 'roles', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('tenant_members', true, $this->tableAttributes());
    }

    public function down(): void
    {
        $this->forge->dropTable('tenant_members', true);
        $this->forge->dropTable('tenants', true);
        $this->forge->dropTable('users', true);
        $this->forge->dropTable('roles', true);
    }
}
