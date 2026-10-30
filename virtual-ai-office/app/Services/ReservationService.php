<?php

namespace App\Services;

use Config\Database;
use Config\Services;

/**
 * Releases offices whose reservation expired without payment:
 * office -> available, rooms removed, pending subscription -> cancelled, unpaid invoices -> expired.
 * The tenant and its owner account remain (status pending) so they can register a new reservation.
 */
class ReservationService
{
    /** @return int number of reservations released */
    public function expireOverdue(?string $now = null): int
    {
        $db      = Database::connect();
        $now   ??= date('Y-m-d H:i:s');
        $overdue = $db->table('offices')->where('status', 'reserved')->where('reserved_until <', $now)->get()->getResultArray();

        $released = 0;
        foreach ($overdue as $office) {
            $db->transException(true)->transStart();
            // Re-check inside the transaction: a payment callback may have activated it meanwhile.
            $db->table('offices')->where('id', $office['id'])->where('status', 'reserved')->where('reserved_until <', $now)
                ->update(['status' => 'available', 'tenant_id' => null, 'reserved_until' => null, 'updated_at' => $now]);
            if ($db->affectedRows() !== 1) {
                $db->transRollback();

                continue;
            }
            $tenantId = (int) $office['tenant_id'];
            $db->table('rooms')->where('office_id', $office['id'])->delete();
            $subIds = array_column($db->table('tenant_subscriptions')->select('id')
                ->where('tenant_id', $tenantId)->where('office_id', $office['id'])->where('status', 'pending')->get()->getResultArray(), 'id');
            if ($subIds !== []) {
                $db->table('tenant_subscriptions')->whereIn('id', $subIds)
                    ->update(['status' => 'cancelled', 'cancelled_at' => $now, 'office_id' => null, 'updated_at' => $now]);
                $db->table('invoices')->whereIn('subscription_id', $subIds)->where('status', 'unpaid')
                    ->update(['status' => 'expired', 'updated_at' => $now]);
            }
            $db->transComplete();

            Services::auditLogger()->record('reservation.expired', [
                'tenant_id' => $tenantId, 'entity_type' => 'office', 'entity_id' => (int) $office['id'],
                'old' => ['reserved_until' => $office['reserved_until']],
            ]);
            $released++;
        }

        return $released;
    }
}
