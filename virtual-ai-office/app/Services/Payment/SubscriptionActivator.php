<?php

namespace App\Services\Payment;

use App\Services\RoomProvisioner;
use CodeIgniter\Database\BaseConnection;
use Config\Services;

/**
 * Applies a PAID invoice. Must run inside the caller's DB transaction.
 * Returns a short machine-readable outcome for the callback log.
 */
class SubscriptionActivator
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function apply(array $invoice, string $paidAt): string
    {
        $sub = $invoice['subscription_id']
            ? $this->db->table('tenant_subscriptions')->where('id', $invoice['subscription_id'])->get()->getRowArray()
            : null;
        if ($sub === null) {
            return 'paid_without_subscription';
        }

        $limits   = json_decode($sub['limits_snapshot'] ?? '{}', true) ?: [];
        $plan     = $this->db->table('subscription_plans')->where('id', $sub['plan_id'])->get()->getRowArray();
        $days     = (int) ($limits['duration_days'] ?? $plan['duration_days'] ?? 30);
        $tenantId = (int) $sub['tenant_id'];

        // Office: the reservation becomes a lease. If the reservation already lapsed, re-claim it if still free.
        $outcome = 'activated';
        if ($sub['office_id'] !== null && ! $this->leaseOffice((int) $sub['office_id'], $tenantId, $plan, $paidAt)) {
            $outcome          = 'paid_office_unavailable'; // Super Admin must assign another office or refund
            $sub['office_id'] = null;
        }

        // Period: active renews from its current end; past_due continues from the old end (grace days
        // already used are not free); a new, pending or expired subscription starts at payment time.
        $continuing = in_array($sub['status'], ['active', 'past_due'], true) && $sub['ends_at'];
        $base       = match (true) {
            $sub['status'] === 'active' && $continuing => max($sub['ends_at'], $paidAt),
            $sub['status'] === 'past_due' && $continuing => $sub['ends_at'],
            default => $paidAt,
        };
        $endsAt = date('Y-m-d H:i:s', strtotime("{$base} +{$days} days"));
        $this->db->table('tenant_subscriptions')->where('id', $sub['id'])->update([
            'status'       => 'active',
            'starts_at'    => $continuing && $sub['starts_at'] ? $sub['starts_at'] : $paidAt,
            'ends_at'      => $endsAt,
            'office_id'    => $sub['office_id'],
            'cancelled_at' => null,
            'updated_at'   => $paidAt,
        ]);

        $this->db->table('tenants')->where('id', $tenantId)->whereIn('status', ['pending', 'expired'])
            ->update(['status' => 'active', 'updated_at' => $paidAt]);

        if ($sub['office_id'] !== null) {
            $floorId = (int) $this->db->table('offices')->where('id', $sub['office_id'])->get()->getRow()->floor_id;
            $this->db->table('floors')->where('id', $floorId)->where('floor_type', 'office')->where('status !=', 'maintenance')
                ->update(['status' => 'occupied', 'updated_at' => $paidAt]);
        }

        Services::auditLogger()->record('subscription.activated', [
            'tenant_id' => $tenantId, 'entity_type' => 'tenant_subscription', 'entity_id' => (int) $sub['id'],
            'new' => ['ends_at' => $endsAt, 'invoice' => $invoice['invoice_number'], 'outcome' => $outcome],
        ]);

        return $outcome;
    }

    /** Turn the tenant's reservation into a lease, re-claiming the office if the reservation lapsed. */
    private function leaseOffice(int $officeId, int $tenantId, ?array $plan, string $now): bool
    {
        $this->db->table('offices')->where('id', $officeId)->where('tenant_id', $tenantId)
            ->update(['status' => 'occupied', 'reserved_until' => null, 'updated_at' => $now]);
        if ($this->db->affectedRows() === 1) {
            return true;
        }
        $this->db->table('offices')->where('id', $officeId)->where('tenant_id', null)->where('status', 'available')
            ->update(['tenant_id' => $tenantId, 'status' => 'occupied', 'reserved_until' => null, 'updated_at' => $now]);
        if ($this->db->affectedRows() !== 1) {
            return false; // taken by someone else meanwhile
        }
        (new RoomProvisioner($this->db))->provision($tenantId, $officeId, $plan);

        return true;
    }
}
