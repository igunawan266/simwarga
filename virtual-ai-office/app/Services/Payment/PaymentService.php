<?php

namespace App\Services\Payment;

use App\Exceptions\DomainRuleException;
use App\Libraries\Payment\Tripay\TripayException;
use App\Models\InvoiceModel;
use Config\Database;
use Config\Services;

/**
 * Tenant side of paying an invoice through Tripay. Tenant scoped (TenantContext).
 * Creating a transaction never marks anything as paid — only a verified callback does.
 */
class PaymentService
{
    public function invoiceForTenant(int $invoiceId): array
    {
        $invoice = model(InvoiceModel::class)->find($invoiceId);
        if ($invoice === null) {
            throw new DomainRuleException('Invoice tidak ditemukan.', 404);
        }

        return $invoice;
    }

    public function channels(int $invoiceId): array
    {
        $invoice = $this->invoiceForTenant($invoiceId);
        $this->assertPayable($invoice);
        $this->assertGateway();

        return [
            'invoice'  => ['id' => (int) $invoice['id'], 'number' => $invoice['invoice_number'], 'total' => (int) $invoice['total'], 'due_at' => $invoice['due_at']],
            'channels' => (new ChannelService())->forAmount((int) $invoice['total']),
            'sandbox'  => config('Tripay')->isSandbox(),
        ];
    }

    /**
     * Create (or reuse) a Tripay transaction for the invoice with the chosen channel.
     */
    public function pay(int $invoiceId, string $method, array $customer): array
    {
        $invoice = $this->invoiceForTenant($invoiceId);
        $this->assertPayable($invoice);
        $this->assertGateway();

        $db      = Database::connect();
        $channel = $db->table('payment_channels')->where('provider', 'tripay')->where('code', $method)->where('is_active', 1)->get()->getRowArray();
        if ($channel === null) {
            throw new DomainRuleException('Metode pembayaran tidak tersedia.');
        }

        // Same invoice + same method + still open -> show the existing instructions again.
        $existing = $db->table('payments')->where('invoice_id', $invoice['id'])->where('method_code', $method)
            ->where('status', 'UNPAID')->where('expired_at >', date('Y-m-d H:i:s'))
            ->orderBy('id', 'DESC')->get(1)->getRowArray();
        if ($existing !== null) {
            return $this->present($existing);
        }

        $tripay    = Services::tripay();
        $attempt   = $db->table('payments')->where('invoice_id', $invoice['id'])->countAllResults() + 1;
        $ref       = $invoice['invoice_number'] . '-' . $attempt;
        $expiresAt = min(time() + 60 * config('Tripay')->expiryMinutes, strtotime($invoice['due_at']) ?: PHP_INT_MAX);
        if ($expiresAt <= time() + 300) {
            throw new DomainRuleException('Waktu pembayaran invoice ini hampir habis. Silakan hubungi admin.');
        }
        // Order items mirror the invoice lines; Tripay derives and signs the amount from them.
        $items = [];
        foreach (json_decode($invoice['line_items'] ?? '[]', true) ?: [] as $i => $line) {
            $items[] = ['sku' => 'INV-LINE-' . ($i + 1), 'name' => mb_substr($line['description'], 0, 100), 'price' => (int) $line['amount'], 'quantity' => 1];
        }
        $amount = array_sum(array_column($items, 'price'));
        if ($amount !== (int) $invoice['total']) {
            throw new DomainRuleException('Rincian invoice tidak konsisten. Hubungi admin.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('payments')->insert([
            'tenant_id' => $invoice['tenant_id'], 'invoice_id' => $invoice['id'], 'channel_id' => $channel['id'],
            'provider' => 'tripay', 'merchant_ref' => $ref, 'method_code' => $method, 'amount' => $amount,
            'status' => 'UNPAID', 'is_sandbox' => config('Tripay')->isSandbox() ? 1 : 0,
            'expired_at' => date('Y-m-d H:i:s', $expiresAt),
            'request_payload' => json_encode(['method' => $method, 'merchant_ref' => $ref, 'amount' => $amount, 'order_items' => $items, 'expired_time' => $expiresAt]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $paymentId = (int) $db->insertID();

        try {
            $data = $tripay->createTransaction($method, $ref, $items, $customer, $expiresAt, site_url('/?payment=return'));
        } catch (TripayException $e) {
            $db->table('payments')->where('id', $paymentId)->update(['status' => 'FAILED', 'response_payload' => json_encode(['error' => $e->getMessage()]), 'updated_at' => date('Y-m-d H:i:s')]);
            Services::auditLogger()->record('payment.create_failed', ['entity_type' => 'payment', 'entity_id' => $paymentId, 'new' => ['error' => $e->getMessage()]]);

            throw new DomainRuleException($e->getMessage(), 502);
        }

        if (($data['merchant_ref'] ?? null) !== $ref) {
            throw new DomainRuleException('Respons Tripay tidak cocok dengan transaksi.', 502);
        }
        $db->table('payments')->where('id', $paymentId)->update([
            'provider_reference' => $data['reference'] ?? null,
            'fee_merchant'       => (int) ($data['fee_merchant'] ?? 0),
            'fee_customer'       => (int) ($data['fee_customer'] ?? 0),
            'pay_code'           => $data['pay_code'] ?? null,
            'checkout_url'       => $data['checkout_url'] ?? null,
            'qr_url'             => $data['qr_url'] ?? null,
            'expired_at'         => isset($data['expired_time']) ? date('Y-m-d H:i:s', (int) $data['expired_time']) : date('Y-m-d H:i:s', $expiresAt),
            'response_payload'   => json_encode($data),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);
        Services::auditLogger()->record('payment.created', ['entity_type' => 'payment', 'entity_id' => $paymentId, 'new' => ['merchant_ref' => $ref, 'method' => $method, 'amount' => $amount]]);

        return $this->present($db->table('payments')->where('id', $paymentId)->get()->getRowArray());
    }

    /** Latest payment attempts of an invoice (for polling after the customer pays). */
    public function status(int $invoiceId): array
    {
        $invoice  = $this->invoiceForTenant($invoiceId);
        $payments = Database::connect()->table('payments')->where('invoice_id', $invoice['id'])->orderBy('id', 'DESC')->get(5)->getResultArray();

        return ['invoice_status' => $invoice['status'], 'payments' => array_map([$this, 'present'], $payments)];
    }

    private function present(array $p): array
    {
        $response = json_decode($p['response_payload'] ?? '{}', true) ?: [];

        return [
            'id'           => (int) $p['id'],
            'merchant_ref' => $p['merchant_ref'],
            'reference'    => $p['provider_reference'],
            'method'       => $p['method_code'],
            'method_name'  => $response['payment_name'] ?? $p['method_code'],
            'status'       => $p['status'],
            'amount'       => (int) $p['amount'],
            'fee_customer' => (int) $p['fee_customer'],
            'total'        => (int) $p['amount'] + (int) $p['fee_customer'],
            'pay_code'     => $p['pay_code'],
            'checkout_url' => $p['checkout_url'],
            'qr_url'       => $p['qr_url'],
            'expired_at'   => $p['expired_at'],
            'paid_at'      => $p['paid_at'],
            'instructions' => $response['instructions'] ?? [],
            'is_sandbox'   => (bool) $p['is_sandbox'],
        ];
    }

    private function assertPayable(array $invoice): void
    {
        if ($invoice['status'] !== 'unpaid') {
            throw new DomainRuleException('Invoice ini tidak dapat dibayar (status: ' . $invoice['status'] . ').');
        }
    }

    private function assertGateway(): void
    {
        if (! config('Tripay')->isConfigured()) {
            throw new DomainRuleException('Payment gateway belum dikonfigurasi oleh pengelola platform.', 503);
        }
    }
}
