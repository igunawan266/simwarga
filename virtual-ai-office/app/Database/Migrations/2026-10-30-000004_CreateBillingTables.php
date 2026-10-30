<?php

namespace App\Database\Migrations;

use App\Database\BaseMigration;

/**
 * payment_channels, invoices, payments, payment_callbacks
 */
class CreateBillingTables extends BaseMigration
{
    public function up(): void
    {
        // Synced from Tripay GET /merchant/payment-channel (Phase 4).
        $this->forge->addField([
            'id'            => $this->id(),
            'provider'      => $this->str(30, false, 'tripay'),
            'code'          => $this->str(50), // e.g. BRIVA, QRIS, OVO
            'name'          => $this->str(100),
            'group_name'    => $this->str(50, true), // Virtual Account | E-Wallet | Convenience Store ...
            'type'          => $this->str(20, true), // DIRECT|REDIRECT
            'fee_flat'      => $this->money(),
            'fee_percent'   => ['type' => 'DECIMAL', 'constraint' => '6,3', 'default' => 0],
            'minimum_amount' => $this->money(),
            'maximum_amount' => $this->money(),
            'icon_url'      => $this->str(255, true),
            'is_active'     => $this->bool(1),
            'synced_at'     => $this->datetime(),
            ...$this->timestamps(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['provider', 'code']);
        $this->forge->createTable('payment_channels', true, $this->tableAttributes());

        $this->forge->addField([
            'id'              => $this->id(),
            'tenant_id'       => $this->fk(),
            'subscription_id' => $this->fk(true),
            'plan_id'         => $this->fk(true),
            'invoice_number'  => $this->str(40),
            'description'     => $this->str(255),
            'subtotal'        => $this->money(),
            'fee'             => $this->money(),
            'total'           => $this->money(),
            'currency'        => $this->str(3, false, 'IDR'),
            'status'          => $this->str(20, false, 'unpaid'), // unpaid|paid|expired|failed|refunded|cancelled
            'due_at'          => $this->datetime(),
            'paid_at'         => $this->datetime(),
            'line_items'      => $this->json(),
            ...$this->timestamps(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('invoice_number');
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('subscription_id', 'tenant_subscriptions', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('plan_id', 'subscription_plans', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('invoices', true, $this->tableAttributes());

        // One row per payment attempt sent to the gateway.
        $this->forge->addField([
            'id'               => $this->id(),
            'tenant_id'        => $this->fk(),
            'invoice_id'       => $this->fk(),
            'channel_id'       => $this->fk(true),
            'provider'         => $this->str(30, false, 'tripay'),
            'merchant_ref'     => $this->str(64),  // our reference sent to Tripay
            'provider_reference' => $this->str(64, true), // Tripay "reference"
            'method_code'      => $this->str(50),
            'amount'           => $this->money(),
            'fee_merchant'     => $this->money(),
            'fee_customer'     => $this->money(),
            'amount_received'  => $this->money(),
            'pay_code'         => $this->str(100, true),
            'checkout_url'     => $this->str(500, true),
            'qr_url'           => $this->str(500, true),
            'status'           => $this->str(20, false, 'UNPAID'), // UNPAID|PAID|EXPIRED|FAILED|REFUND
            'refund_status'    => $this->str(20, true), // requested|processing|refunded|rejected
            'expired_at'       => $this->datetime(),
            'paid_at'          => $this->datetime(),
            'is_sandbox'       => $this->bool(1),
            'request_payload'  => $this->json(),
            'response_payload' => $this->json(),
            ...$this->timestamps(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('merchant_ref');
        $this->forge->addUniqueKey(['provider', 'provider_reference']);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addKey('invoice_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('invoice_id', 'invoices', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('channel_id', 'payment_channels', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('payments', true, $this->tableAttributes());

        // Raw, append-only log of every webhook hit. event_hash makes processing idempotent.
        $this->forge->addField([
            'id'               => $this->id(),
            'provider'         => $this->str(30, false, 'tripay'),
            'event'            => $this->str(50, true),  // X-Callback-Event
            'event_hash'       => $this->str(64),        // sha256(reference|status|raw body)
            'merchant_ref'     => $this->str(64, true),
            'provider_reference' => $this->str(64, true),
            'payment_id'       => $this->fk(true),
            'signature_valid'  => $this->bool(0),
            'process_status'   => $this->str(20, false, 'received'), // received|processed|duplicate|rejected|error
            'process_message'  => $this->str(255, true),
            'ip_address'       => $this->str(45, true),
            'headers'          => $this->json(),
            'raw_body'         => $this->text(),
            'processed_at'     => $this->datetime(),
            ...$this->timestamps(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('event_hash');
        $this->forge->addKey('merchant_ref');
        $this->forge->addForeignKey('payment_id', 'payments', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('payment_callbacks', true, $this->tableAttributes());
    }

    public function down(): void
    {
        $this->forge->dropTable('payment_callbacks', true);
        $this->forge->dropTable('payments', true);
        $this->forge->dropTable('invoices', true);
        $this->forge->dropTable('payment_channels', true);
    }
}
