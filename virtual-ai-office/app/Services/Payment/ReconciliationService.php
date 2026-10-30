<?php

namespace App\Services\Payment;

use App\Libraries\Payment\Tripay\TripayException;
use Config\Database;

/**
 * Safety net for lost webhooks: re-checks open Tripay transactions against the API.
 * Run from cron (`php spark tripay:reconcile`, every 15 minutes).
 */
class ReconciliationService
{
    /** @return array<string, int> outcome => count */
    public function run(): array
    {
        $now      = time();
        $payments = Database::connect()->table('payments')
            ->where('provider', 'tripay')->where('status', 'UNPAID')->where('provider_reference IS NOT NULL', null, false)
            ->where('created_at <', date('Y-m-d H:i:s', $now - 300))          // give the normal callback a head start
            ->where('expired_at >', date('Y-m-d H:i:s', $now - 86400))       // and stop chasing day-old expired ones
            ->orderBy('id')->get(200)->getResultArray();

        $counts   = [];
        $callback = new CallbackService();
        foreach ($payments as $p) {
            try {
                $outcome = $callback->reconcile($p);
            } catch (TripayException $e) {
                $outcome = 'api_error';
                log_message('warning', 'Reconcile {ref} failed: {msg}', ['ref' => $p['merchant_ref'], 'msg' => $e->getMessage()]);
            }
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }

        return $counts;
    }
}
