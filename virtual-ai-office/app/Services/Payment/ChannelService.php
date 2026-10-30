<?php

namespace App\Services\Payment;

use Config\Database;
use Config\Services;

/** Payment channels: synced from Tripay into payment_channels, then offered to tenants. */
class ChannelService
{
    /** @return int channels upserted */
    public function sync(): int
    {
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');
        $seen = [];
        foreach (Services::tripay()->paymentChannels() as $c) {
            $code   = (string) $c['code'];
            $seen[] = $code;
            $row    = [
                'name'           => (string) $c['name'],
                'group_name'     => $c['group'] ?? null,
                'type'           => $c['type'] ?? null,
                // Fee charged to the customer; the exact fee is confirmed by transaction/create.
                'fee_flat'       => (int) ($c['fee_customer']['flat'] ?? 0),
                'fee_percent'    => (float) ($c['fee_customer']['percent'] ?? 0),
                'minimum_amount' => (int) ($c['minimum_amount'] ?? 0),
                'maximum_amount' => (int) ($c['maximum_amount'] ?? 0),
                'icon_url'       => $c['icon_url'] ?? null,
                'is_active'      => ! empty($c['active']) ? 1 : 0,
                'synced_at'      => $now,
                'updated_at'     => $now,
            ];
            $exists = $db->table('payment_channels')->where('provider', 'tripay')->where('code', $code)->countAllResults() > 0;
            $exists
                ? $db->table('payment_channels')->where('provider', 'tripay')->where('code', $code)->update($row)
                : $db->table('payment_channels')->insert($row + ['provider' => 'tripay', 'code' => $code, 'created_at' => $now]);
        }
        // Channels no longer offered by the merchant account are switched off.
        $q = $db->table('payment_channels')->where('provider', 'tripay');
        if ($seen !== []) {
            $q->whereNotIn('code', $seen);
        }
        $q->update(['is_active' => 0, 'updated_at' => $now]);

        Services::auditLogger()->record('payment.channels_synced', ['new' => ['count' => count($seen)]]);

        return count($seen);
    }

    /** Active channels usable for this amount, with the customer fee estimate. */
    public function forAmount(int $amount): array
    {
        $rows = Database::connect()->table('payment_channels')->where('provider', 'tripay')->where('is_active', 1)
            ->orderBy('group_name')->orderBy('name')->get()->getResultArray();

        $out = [];
        foreach ($rows as $c) {
            if ((int) $c['minimum_amount'] > 0 && $amount < (int) $c['minimum_amount']) {
                continue;
            }
            if ((int) $c['maximum_amount'] > 0 && $amount > (int) $c['maximum_amount']) {
                continue;
            }
            $fee   = (int) $c['fee_flat'] + (int) ceil($amount * (float) $c['fee_percent'] / 100);
            $out[] = [
                'code'          => $c['code'],
                'name'          => $c['name'],
                'group'         => $c['group_name'] ?: 'Lainnya',
                'icon_url'      => $c['icon_url'],
                'fee_estimate'  => $fee,
                'total_estimate' => $amount + $fee,
            ];
        }

        return $out;
    }
}
