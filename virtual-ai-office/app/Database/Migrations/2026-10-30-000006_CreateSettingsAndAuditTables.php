<?php

namespace App\Database\Migrations;

use App\Database\BaseMigration;

/**
 * tenant_settings, system_settings, audit_logs
 */
class CreateSettingsAndAuditTables extends BaseMigration
{
    public function up(): void
    {
        // is_encrypted values are encrypted with CI4 Encrypter (key from .env) before storage.
        $this->forge->addField([
            'id'           => $this->id(),
            'tenant_id'    => $this->fk(),
            'setting_key'  => $this->str(100),
            'setting_value' => $this->text(),
            'is_encrypted' => $this->bool(0),
            ...$this->timestamps(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'setting_key']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('tenant_settings', true, $this->tableAttributes());

        // Non-secret platform settings. Gateway keys and LLM keys live in .env, never here.
        $this->forge->addField([
            'id'            => $this->id(),
            'setting_group' => $this->str(50, false, 'general'),
            'setting_key'   => $this->str(100),
            'setting_value' => $this->text(),
            'value_type'    => $this->str(20, false, 'string'), // string|int|bool|json
            'updated_by'    => $this->fk(true),
            ...$this->timestamps(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['setting_group', 'setting_key']);
        $this->forge->addForeignKey('updated_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('system_settings', true, $this->tableAttributes());

        // Append-only. tenant_id NULL = platform-level event (super admin actions, failed logins...).
        $this->forge->addField([
            'id'          => $this->id(),
            'tenant_id'   => $this->fk(true),
            'user_id'     => $this->fk(true),
            'agent_id'    => $this->fk(true),
            'action'      => $this->str(100), // e.g. auth.login, agent.create, payment.paid
            'entity_type' => $this->str(50, true),
            'entity_id'   => $this->fk(true),
            'old_values'  => $this->json(),
            'new_values'  => $this->json(),
            'ip_address'  => $this->str(45, true),
            'user_agent'  => $this->str(255, true),
            'created_at'  => $this->datetime(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'created_at']);
        $this->forge->addKey(['entity_type', 'entity_id']);
        $this->forge->addKey('action');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('agent_id', 'ai_agents', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('audit_logs', true, $this->tableAttributes());
    }

    public function down(): void
    {
        $this->forge->dropTable('audit_logs', true);
        $this->forge->dropTable('system_settings', true);
        $this->forge->dropTable('tenant_settings', true);
    }
}
