<?php

namespace App\Commands;

use App\Libraries\Payment\Tripay\TripayException;
use App\Services\Payment\ChannelService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Run after configuring Tripay keys, and daily from cron to pick up channel/fee changes. */
class SyncTripayChannels extends BaseCommand
{
    protected $group       = 'Virtual AI Office';
    protected $name        = 'tripay:sync-channels';
    protected $description = 'Sync payment channels (VA, QRIS, e-wallet...) from the Tripay merchant account.';

    public function run(array $params)
    {
        if (! config('Tripay')->isConfigured()) {
            CLI::error('Tripay is not configured (tripay.merchantCode / apiKey / privateKey in .env).');

            return EXIT_ERROR;
        }
        try {
            CLI::write('Synced ' . (new ChannelService())->sync() . ' channel(s) from Tripay (' . config('Tripay')->mode . ').', 'green');
        } catch (TripayException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
