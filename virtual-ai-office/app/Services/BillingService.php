<?php

namespace App\Services;

use App\Models\InvoiceModel;
use App\Models\OfficeModel;
use Config\Database;
use Config\Services;

/** Tenant-facing billing summary (subscription, reservation, invoices). Tenant scoped. */
class BillingService
{
    public function summary(): array
    {
        $tenantId = Services::tenantContext()->id();
        $db       = Database::connect();

        $subscription = $db->table('tenant_subscriptions s')
            ->select('s.id, s.status, s.starts_at, s.ends_at, s.price_snapshot, p.code AS plan_code, p.name AS plan_name')
            ->join('subscription_plans p', 'p.id = s.plan_id')
            ->where('s.tenant_id', $tenantId)
            ->orderBy("CASE WHEN s.status IN ('trial','active') THEN 0 WHEN s.status = 'pending' THEN 1 ELSE 2 END", '', false)
            ->orderBy('s.id', 'DESC')
            ->get(1)->getRowArray();

        $office   = model(OfficeModel::class)->findForTenant($tenantId);
        $invoices = model(InvoiceModel::class)
            ->select('id, invoice_number, description, subtotal, fee, total, currency, status, due_at, paid_at, line_items, created_at')
            ->orderBy('id', 'DESC')->findAll(20);

        return [
            'tenant_status' => Services::tenantContext()->tenant()['status'],
            'subscription'  => $subscription ?: null,
            'office'        => $office === null ? null : [
                'number'         => $office['office_number'],
                'floor_number'   => (int) $office['floor_number'],
                'status'         => $office['status'],
                'reserved_until' => $office['reserved_until'],
            ],
            'invoices' => array_map(static function (array $i) {
                $i['line_items'] = json_decode($i['line_items'] ?? '[]', true);
                foreach (['subtotal', 'fee', 'total'] as $k) {
                    $i[$k] = (int) $i[$k];
                }

                return $i;
            }, $invoices),
        ];
    }
}
