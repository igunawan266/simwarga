<?php

namespace App\Database\Migrations;

use App\Database\BaseMigration;

/**
 * Self-service registration reserves an office until the first invoice is paid.
 * Expired reservations are released by `php spark office:expire-reservations` (cron).
 */
class AddReservationToOffices extends BaseMigration
{
    public function up(): void
    {
        $this->forge->addColumn('offices', ['reserved_until' => $this->datetime()]);
        $this->forge->addKey(['status', 'reserved_until'], false, false, 'offices_status_reserved_until');
        $this->forge->processIndexes('offices');
    }

    public function down(): void
    {
        $this->forge->dropKey('offices', 'offices_status_reserved_until', false);
        $this->forge->dropColumn('offices', 'reserved_until');
    }
}
