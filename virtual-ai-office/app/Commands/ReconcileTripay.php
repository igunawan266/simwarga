<?php

namespace App\Commands;

use App\Services\Payment\ReconciliationService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Every 15 minutes: recover payments whose Tripay callback never arrived. */
class ReconcileTripay extends BaseCommand
{
    protected $group       = 'Virtual AI Office';
    protected $name        = 'tripay:reconcile';
    protected $description = 'Re-check open Tripay transactions and apply missed PAID/EXPIRED statuses.';

    public function run(array $params)
    {
        if (! config('Tripay')->isConfigured()) {
            CLI::error('Tripay is not configured.');

            return EXIT_ERROR;
        }
        $counts = (new ReconciliationService())->run();
        CLI::write($counts === [] ? 'No open transactions to reconcile.' : json_encode($counts), 'green');

        return EXIT_SUCCESS;
    }
}
