<?php

namespace App\Database\Migrations;

use App\Database\BaseMigration;

/**
 * subscription_plans, tenant_subscriptions
 */
class CreateSubscriptionTables extends BaseMigration
{
    public function up(): void
    {
        // Every price / limit is editable by Super Admin — nothing is hard-coded in the app.
        $this->forge->addField([
            'id'                  => $this->id(),
            'code'                => $this->str(30),
            'name'                => $this->str(100),
            'description'         => $this->text(),
            'price'               => $this->money(),
            'billing_period'      => $this->str(20, false, 'monthly'), // monthly|quarterly|yearly
            'duration_days'       => $this->int(30),
            'max_offices'         => $this->int(1),
            'max_agents'          => $this->int(2),
            'max_operational_rooms' => $this->int(0),
            'monthly_token_quota' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0],
            'allowed_room_types'  => $this->json(), // list of room_type slugs; null = all
            'features'            => $this->json(), // {"api_integration":true,"custom_office":false,...}
            'is_custom'           => $this->bool(0), // Enterprise: priced per contract
            'is_active'           => $this->bool(1),
            'sort_order'          => $this->int(0),
            ...$this->timestamps(true),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('code');
        $this->forge->createTable('subscription_plans', true, $this->tableAttributes());

        $this->forge->addField([
            'id'          => $this->id(),
            'tenant_id'   => $this->fk(),
            'plan_id'     => $this->fk(),
            'office_id'   => $this->fk(true),
            'status'      => $this->str(20, false, 'pending'), // pending|trial|active|past_due|expired|cancelled
            'starts_at'   => $this->datetime(),
            'ends_at'     => $this->datetime(),
            'auto_renew'  => $this->bool(0),
            'price_snapshot' => $this->money(), // price at purchase time
            'limits_snapshot' => $this->json(),  // plan limits at purchase time
            'cancelled_at' => $this->datetime(),
            ...$this->timestamps(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addKey('ends_at');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('plan_id', 'subscription_plans', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addForeignKey('office_id', 'offices', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('tenant_subscriptions', true, $this->tableAttributes());
    }

    public function down(): void
    {
        $this->forge->dropTable('tenant_subscriptions', true);
        $this->forge->dropTable('subscription_plans', true);
    }
}
