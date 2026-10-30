<?php

namespace App\Commands;

use App\Services\ReservationService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Run from cron every 5 minutes, e.g. a crontab line with schedule "every 5 minutes":
 *   cd /path/to/app && php spark office:expire-reservations
 */
class ExpireReservations extends BaseCommand
{
    protected $group       = 'Virtual AI Office';
    protected $name        = 'office:expire-reservations';
    protected $description = 'Release offices whose reservation expired without payment.';

    public function run(array $params)
    {
        $count = (new ReservationService())->expireOverdue();
        CLI::write("Released {$count} expired reservation(s).", $count > 0 ? 'yellow' : 'green');
    }
}
