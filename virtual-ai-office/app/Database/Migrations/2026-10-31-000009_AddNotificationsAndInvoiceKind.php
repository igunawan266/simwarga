<?php

namespace App\Database\Migrations;

use App\Database\BaseMigration;

/**
 * notifications: in-app messages per tenant (billing, payments, subscription lifecycle).
 * invoices.kind: initial (registration) | renewal (subscription extension).
 */
class AddNotificationsAndInvoiceKind extends BaseMigration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => $this->id(),
            'tenant_id'  => $this->fk(),
            'audience'   => $this->str(20, false, 'all'), // all | billing (owners & admins only)
            'type'       => $this->str(50),               // invoice.created, payment.paid, subscription.past_due, ...
            'title'      => $this->str(150),
            'body'       => $this->text(),
            'data'       => $this->json(),
            'read_at'    => $this->datetime(),
            'created_at' => $this->datetime(),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'created_at']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('notifications', true, $this->tableAttributes());

        $this->forge->addColumn('invoices', ['kind' => $this->str(20, false, 'initial')]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('invoices', 'kind');
        $this->forge->dropTable('notifications', true);
    }
}
