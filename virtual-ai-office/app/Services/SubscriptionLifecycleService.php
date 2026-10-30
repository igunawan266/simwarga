<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use Config\Database;
use Config\Services;

/**
 * Subscription lifecycle, run from cron (`php spark office:subscriptions`, hourly):
 *
 *  active  --(ends in <= renewal_days_before)--> renewal invoice created (once)
 *  active  --(ends_at passed)-------------------> past_due  (still usable during grace_period_days)
 *  past_due --(grace period over)---------------> expired   (tenant expired: office kept, access blocked)
 *  trial   --(ends_at passed)-------------------> expired
 *
 * Paying a renewal invoice extends the subscription (SubscriptionActivator); paying after
 * expiry starts a new period and re-activates the tenant. Offices and data are never
 * released automatically on expiry — that is a Super Admin decision.
 */
class SubscriptionLifecycleService
{
    public function __construct(private readonly NotificationService $notifications = new NotificationService())
    {
    }

    /** @return array{renewal_invoices: int, past_due: int, expired: int} */
    public function run(?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');

        return [
            'renewal_invoices' => $this->createDueRenewalInvoices($now),
            'past_due'         => $this->markPastDue($now),
            'expired'          => $this->expire($now),
        ];
    }

    public function createDueRenewalInvoices(string $now): int
    {
        $days    = (int) SettingsService::get('billing', 'renewal_days_before', 7);
        $horizon = date('Y-m-d H:i:s', strtotime("{$now} +{$days} days"));
        $subs    = Database::connect()->table('tenant_subscriptions')
            ->where('status', 'active')->where('ends_at <=', $horizon)->where('ends_at >', $now)
            ->get()->getResultArray();

        $created = 0;
        foreach ($subs as $sub) {
            if ($this->openInvoice((int) $sub['id']) === null) {
                $this->createRenewalInvoice($sub, $sub['ends_at']);
                $created++;
            }
        }

        return $created;
    }

    public function markPastDue(string $now): int
    {
        $db   = Database::connect();
        $subs = $db->table('tenant_subscriptions')->where('status', 'active')->where('ends_at <=', $now)->get()->getResultArray();
        foreach ($subs as $sub) {
            $db->table('tenant_subscriptions')->where('id', $sub['id'])->where('status', 'active')
                ->update(['status' => 'past_due', 'updated_at' => $now]);
            // A renewal invoice may not exist yet (e.g. very short plans): make sure one does.
            if ($this->openInvoice((int) $sub['id']) === null) {
                $this->createRenewalInvoice($sub, $this->graceEnd($sub['ends_at']));
            }
            $grace = $this->graceEnd($sub['ends_at']);
            $this->notifications->notify((int) $sub['tenant_id'], 'subscription.past_due', 'Langganan lewat jatuh tempo',
                "Masa aktif langganan berakhir {$sub['ends_at']}. Kantor tetap bisa dipakai sampai {$grace}; bayar invoice perpanjangan agar tidak terhenti.");
            Services::auditLogger()->record('subscription.past_due', ['tenant_id' => (int) $sub['tenant_id'], 'entity_type' => 'tenant_subscription', 'entity_id' => (int) $sub['id']]);
        }

        return count($subs);
    }

    public function expire(string $now): int
    {
        $db        = Database::connect();
        $graceDays = (int) SettingsService::get('billing', 'grace_period_days', 3);
        $cutoff    = date('Y-m-d H:i:s', strtotime("{$now} -{$graceDays} days"));

        $subs = $db->table('tenant_subscriptions')
            ->groupStart()
                ->groupStart()->where('status', 'past_due')->where('ends_at <=', $cutoff)->groupEnd()
                ->orGroupStart()->where('status', 'trial')->where('ends_at <=', $now)->groupEnd()
            ->groupEnd()
            ->get()->getResultArray();

        foreach ($subs as $sub) {
            $db->transException(true)->transStart();
            $db->table('tenant_subscriptions')->where('id', $sub['id'])->whereIn('status', ['past_due', 'trial'])
                ->update(['status' => 'expired', 'updated_at' => $now]);
            // Renewal invoices that can no longer be paid; the tenant requests a fresh one to renew.
            $db->table('invoices')->where('subscription_id', $sub['id'])->where('status', 'unpaid')->where('due_at <=', $now)
                ->update(['status' => 'expired', 'updated_at' => $now]);
            $stillUsable = $db->table('tenant_subscriptions')->where('tenant_id', $sub['tenant_id'])
                ->whereIn('status', ['trial', 'active', 'past_due'])->countAllResults();
            if ($stillUsable === 0) {
                $db->table('tenants')->where('id', $sub['tenant_id'])->where('status', 'active')
                    ->update(['status' => 'expired', 'updated_at' => $now]);
            }
            $db->transComplete();

            $this->notifications->notify((int) $sub['tenant_id'], 'subscription.expired', 'Langganan berakhir',
                'Akses kantor virtual dihentikan sementara. Data, ruangan dan Agent AI Anda tetap tersimpan; perpanjang langganan untuk membukanya kembali.');
            Services::auditLogger()->record('subscription.expired', ['tenant_id' => (int) $sub['tenant_id'], 'entity_type' => 'tenant_subscription', 'entity_id' => (int) $sub['id']]);
        }

        return count($subs);
    }

    /**
     * Tenant-initiated renewal (also after expiry): returns the open renewal invoice,
     * creating it if needed. Tenant id comes from the verified TenantContext.
     */
    public function requestRenewal(int $tenantId): array
    {
        $sub = Database::connect()->table('tenant_subscriptions')->where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'past_due', 'expired', 'trial'])
            ->orderBy('id', 'DESC')->get(1)->getRowArray();
        if ($sub === null) {
            throw new DomainRuleException('Belum ada langganan yang bisa diperpanjang.');
        }

        return $this->openInvoice((int) $sub['id'])
            ?? $this->createRenewalInvoice($sub, date('Y-m-d H:i:s', strtotime('+' . (int) SettingsService::get('billing', 'invoice_due_hours', 24) . ' hours')));
    }

    /**
     * Renewal price = current plan price (plans may have changed) + office rent.
     * A plan that is no longer sold, or a contract plan, keeps the price it was bought at.
     */
    public function createRenewalInvoice(array $sub, string $dueAt): array
    {
        $db     = Database::connect();
        $plan   = $db->table('subscription_plans')->where('id', $sub['plan_id'])->get()->getRowArray();
        $office = $sub['office_id'] ? $db->table('offices')->where('id', $sub['office_id'])->get()->getRowArray() : null;
        $usePlanPrice = $plan && (int) $plan['is_active'] === 1 && (int) $plan['is_custom'] === 0;

        $lines = [];
        if ($usePlanPrice) {
            $lines[] = ['description' => "Perpanjangan paket {$plan['name']} ({$plan['duration_days']} hari)", 'amount' => (int) $plan['price']];
            if ($office && (int) $office['monthly_price'] > 0) {
                $lines[] = ['description' => "Sewa kantor {$office['office_number']}", 'amount' => (int) $office['monthly_price']];
            }
        } else {
            $lines[] = ['description' => 'Perpanjangan langganan ' . ($plan['name'] ?? ''), 'amount' => (int) $sub['price_snapshot']];
        }
        $total = array_sum(array_column($lines, 'amount'));
        if ($total <= 0) {
            throw new DomainRuleException('Harga perpanjangan belum ditetapkan. Hubungi pengelola platform.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('invoices')->insert([
            'tenant_id' => $sub['tenant_id'], 'subscription_id' => $sub['id'], 'plan_id' => $sub['plan_id'],
            'invoice_number' => InvoiceNumber::next(), 'kind' => 'renewal',
            'description' => 'Perpanjangan langganan' . ($office ? " — Suite {$office['office_number']}" : ''),
            'subtotal' => $total, 'fee' => 0, 'total' => $total, 'currency' => 'IDR', 'status' => 'unpaid',
            'due_at' => $dueAt, 'line_items' => json_encode($lines), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $invoice = $db->table('invoices')->where('id', $db->insertID())->get()->getRowArray();

        $this->notifications->notify((int) $sub['tenant_id'], 'invoice.renewal', 'Invoice perpanjangan tersedia',
            "Invoice {$invoice['invoice_number']} sebesar Rp " . number_format($total, 0, ',', '.') . " jatuh tempo {$dueAt}.",
            ['invoice_id' => (int) $invoice['id']]);
        Services::auditLogger()->record('invoice.renewal_created', ['tenant_id' => (int) $sub['tenant_id'], 'entity_type' => 'invoice', 'entity_id' => (int) $invoice['id']]);

        return $invoice;
    }

    /** Unpaid invoice for the subscription that can still be paid (not past its due date). */
    private function openInvoice(int $subscriptionId): ?array
    {
        return Database::connect()->table('invoices')->where('subscription_id', $subscriptionId)
            ->where('status', 'unpaid')->where('due_at >', date('Y-m-d H:i:s', time() + 600))
            ->orderBy('id', 'DESC')->get(1)->getRowArray();
    }

    private function graceEnd(string $endsAt): string
    {
        return date('Y-m-d H:i:s', strtotime($endsAt . ' +' . (int) SettingsService::get('billing', 'grace_period_days', 3) . ' days'));
    }
}
