<?php

namespace App\Commands;

use App\Services\SubscriptionLifecycleService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Hourly cron: renewal invoices, past_due and expiry. */
class SubscriptionLifecycle extends BaseCommand
{
    protected $group       = 'Virtual AI Office';
    protected $name        = 'office:subscriptions';
    protected $description = 'Create renewal invoices and move subscriptions to past_due / expired.';

    public function run(array $params)
    {
        $r = (new SubscriptionLifecycleService())->run();
        CLI::write("Renewal invoices: {$r['renewal_invoices']} · past_due: {$r['past_due']} · expired: {$r['expired']}", 'green');
    }
}
