<?php

namespace App\Services\Payment;

use App\Libraries\Payment\Tripay\TripayException;
use Config\Database;
use Config\Services;
use Throwable;

/**
 * Tripay callback (webhook) processing.
 *
 *  1. Every distinct hit is stored raw in payment_callbacks. event_hash = sha256(signature + body)
 *     is unique: Tripay's own retransmissions collapse into one row, forged attempts get their own.
 *  2. Signature: X-Callback-Signature must equal HMAC-SHA256(raw body, private key).
 *  3. The payment is matched by merchant_ref AND Tripay reference; the amount must match
 *     what we requested (total_amount - fee_customer == payments.amount).
 *  4. Optionally the transaction is re-read from Tripay's API and must agree.
 *  5. PAID is applied in one DB transaction with a conditional status update, so a
 *     repeated or concurrent callback can never activate/extend a subscription twice.
 *
 * Returns [httpStatus, responseBody]. Tripay expects {"success": true} on acceptance.
 */
class CallbackService
{
    public function handle(string $rawBody, string $signature, string $event, string $ip): array
    {
        $db   = Database::connect();
        $now  = date('Y-m-d H:i:s');
        $data = json_decode($rawBody, true);
        $hash = hash('sha256', $signature . "\n" . $rawBody);

        $log = $db->table('payment_callbacks')->where('event_hash', $hash)->get()->getRowArray();
        if ($log !== null && in_array($log['process_status'], ['processed', 'duplicate'], true)) {
            return [200, ['success' => true]]; // retransmission of an already handled callback; original log kept as is
        }
        if ($log !== null && $log['process_status'] === 'rejected') {
            return [400, ['success' => false, 'message' => $log['process_message']]];
        }
        if ($log === null) {
            $db->table('payment_callbacks')->insert([
                'provider' => 'tripay', 'event' => mb_substr($event, 0, 50), 'event_hash' => $hash,
                'merchant_ref' => is_array($data) ? mb_substr((string) ($data['merchant_ref'] ?? ''), 0, 64) : null,
                'provider_reference' => is_array($data) ? mb_substr((string) ($data['reference'] ?? ''), 0, 64) : null,
                'ip_address' => $ip, 'headers' => json_encode(['X-Callback-Event' => $event]),
                'raw_body' => $rawBody, 'process_status' => 'received', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $logId = (int) $db->insertID();
        } else {
            $logId = (int) $log['id']; // earlier attempt failed transiently: process again
        }

        $config  = config('Tripay');
        $allowed = array_filter(array_map('trim', explode(',', $config->callbackAllowedIps)));
        if ($allowed !== [] && ! in_array($ip, $allowed, true)) {
            return $this->reject($logId, 403, 'IP pengirim tidak diizinkan.');
        }
        if (! Services::tripay()->isValidCallbackSignature($rawBody, $signature)) {
            return $this->reject($logId, 400, 'Signature tidak valid.');
        }
        $db->table('payment_callbacks')->where('id', $logId)->update(['signature_valid' => 1]);

        if ($event !== 'payment_status') {
            return $this->reject($logId, 400, 'Event tidak didukung: ' . $event);
        }
        if (! is_array($data) || ! isset($data['merchant_ref'], $data['reference'], $data['status'])) {
            return $this->reject($logId, 400, 'Payload tidak lengkap.');
        }

        $payment = $db->table('payments')->where('provider', 'tripay')->where('merchant_ref', (string) $data['merchant_ref'])->get()->getRowArray();
        if ($payment === null || $payment['provider_reference'] !== (string) $data['reference']) {
            return $this->reject($logId, 404, 'Transaksi tidak dikenal.');
        }
        $db->table('payment_callbacks')->where('id', $logId)->update(['payment_id' => $payment['id']]);

        $status = strtoupper((string) $data['status']);
        if ($status === 'PAID') {
            $paidNet = (int) ($data['total_amount'] ?? 0) - (int) ($data['fee_customer'] ?? 0);
            if ($paidNet !== (int) $payment['amount']) {
                Services::auditLogger()->record('payment.amount_mismatch', ['tenant_id' => (int) $payment['tenant_id'], 'entity_type' => 'payment', 'entity_id' => (int) $payment['id'], 'new' => $data]);

                return $this->reject($logId, 400, "Nominal tidak cocok ({$paidNet} ≠ {$payment['amount']}).");
            }
            if ($config->verifyWithApi) {
                try {
                    $remote = Services::tripay()->transactionDetail((string) $data['reference']);
                } catch (TripayException $e) {
                    $this->updateLog($logId, 'error', 'Verifikasi API gagal: ' . $e->getMessage());

                    return [503, ['success' => false, 'message' => 'Verification unavailable, retry later']];
                }
                if (strtoupper((string) ($remote['status'] ?? '')) !== 'PAID' || ($remote['merchant_ref'] ?? null) !== $payment['merchant_ref']) {
                    return $this->reject($logId, 400, 'Status di API Tripay tidak mengonfirmasi PAID.');
                }
            }

            return $this->applyPaid($logId, $payment, $data);
        }

        if (in_array($status, ['EXPIRED', 'FAILED'], true)) {
            $db->table('payments')->where('id', $payment['id'])->where('status', 'UNPAID')->update(['status' => $status, 'updated_at' => $now]);
            $this->updateLog($logId, 'processed', "Pembayaran {$status}.");
            Services::auditLogger()->record('payment.' . strtolower($status), ['tenant_id' => (int) $payment['tenant_id'], 'entity_type' => 'payment', 'entity_id' => (int) $payment['id']]);

            return [200, ['success' => true]];
        }

        if ($status === 'REFUND') {
            $db->transException(true)->transStart();
            $db->table('payments')->where('id', $payment['id'])->update(['status' => 'REFUND', 'refund_status' => 'refunded', 'updated_at' => $now]);
            $db->table('invoices')->where('id', $payment['invoice_id'])->where('status', 'paid')->update(['status' => 'refunded', 'updated_at' => $now]);
            $db->transComplete();
            // Access is not revoked automatically: Super Admin decides (partial refunds, disputes...).
            $this->updateLog($logId, 'processed', 'Refund dicatat; tinjau langganan secara manual.');
            Services::auditLogger()->record('payment.refunded', ['tenant_id' => (int) $payment['tenant_id'], 'entity_type' => 'payment', 'entity_id' => (int) $payment['id']]);

            return [200, ['success' => true]];
        }

        $this->updateLog($logId, 'processed', "Status {$status} dicatat tanpa perubahan.");

        return [200, ['success' => true]];
    }

    /**
     * Reconciliation for a missed callback: asks Tripay for the transaction and applies a
     * final status through the same idempotent path. Returns the resulting log status.
     */
    public function reconcile(array $payment): string
    {
        $db     = Database::connect();
        $remote = Services::tripay()->transactionDetail((string) $payment['provider_reference']);
        $status = strtoupper((string) ($remote['status'] ?? ''));
        if (($remote['merchant_ref'] ?? null) !== $payment['merchant_ref'] || $status === 'UNPAID' || $status === '') {
            return 'unchanged';
        }

        $hash = hash('sha256', "reconcile\n{$payment['merchant_ref']}\n{$status}");
        if ($db->table('payment_callbacks')->where('event_hash', $hash)->whereIn('process_status', ['processed', 'duplicate', 'rejected'])->countAllResults() > 0) {
            return 'already_reconciled';
        }
        $db->table('payment_callbacks')->ignore(true)->insert([
            'provider' => 'tripay', 'event' => 'reconcile', 'event_hash' => $hash, 'merchant_ref' => $payment['merchant_ref'],
            'provider_reference' => $payment['provider_reference'], 'payment_id' => $payment['id'], 'signature_valid' => 1,
            'process_status' => 'received', 'raw_body' => json_encode($remote), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $logId = (int) $db->table('payment_callbacks')->where('event_hash', $hash)->get()->getRow()->id;

        if ($status === 'PAID') {
            // The transaction "amount" from Tripay includes the customer fee, like transaction/create.
            $net = (int) ($remote['amount'] ?? $remote['total_amount'] ?? 0) - (int) ($remote['fee_customer'] ?? 0);
            if ($net !== (int) $payment['amount']) {
                $this->reject($logId, 400, "Rekonsiliasi: nominal tidak cocok ({$net} ≠ {$payment['amount']}); tinjau manual.");

                return 'rejected';
            }
            [$code] = $this->applyPaid($logId, $payment, $remote + ['total_amount' => $net + (int) ($remote['fee_customer'] ?? 0)]);

            return $code === 200 ? 'paid' : 'error';
        }
        if (in_array($status, ['EXPIRED', 'FAILED'], true)) {
            $db->table('payments')->where('id', $payment['id'])->where('status', 'UNPAID')->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
            $this->updateLog($logId, 'processed', "Rekonsiliasi: {$status}.");

            return strtolower($status);
        }
        $this->updateLog($logId, 'processed', "Rekonsiliasi: status {$status} dicatat.");

        return 'unchanged';
    }

    private function applyPaid(int $logId, array $payment, array $data): array
    {
        $db     = Database::connect();
        $paidAt = isset($data['paid_at']) && is_numeric($data['paid_at']) ? date('Y-m-d H:i:s', (int) $data['paid_at']) : date('Y-m-d H:i:s');

        try {
            $db->transException(true)->transStart();
            // Conditional update = idempotency guard: only the first PAID callback gets past this line.
            $db->table('payments')->where('id', $payment['id'])->where('status !=', 'PAID')->update([
                'status'          => 'PAID',
                'paid_at'         => $paidAt,
                'amount_received' => (int) ($data['amount_received'] ?? 0),
                'fee_merchant'    => (int) ($data['fee_merchant'] ?? $payment['fee_merchant']),
                'fee_customer'    => (int) ($data['fee_customer'] ?? $payment['fee_customer']),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);
            if ($db->affectedRows() !== 1) {
                $db->transRollback();
                $this->updateLog($logId, 'duplicate', 'Pembayaran sudah PAID sebelumnya.');

                return [200, ['success' => true]];
            }

            $invoice = $db->table('invoices')->where('id', $payment['invoice_id'])->get()->getRowArray();
            $db->table('invoices')->where('id', $invoice['id'])->update([
                'status' => 'paid', 'paid_at' => $paidAt, 'fee' => (int) ($data['fee_customer'] ?? 0),
                'total'  => (int) $invoice['subtotal'] + (int) ($data['fee_customer'] ?? 0), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            // Other open attempts for this invoice are no longer needed.
            $db->table('payments')->where('invoice_id', $invoice['id'])->where('id !=', $payment['id'])->where('status', 'UNPAID')
                ->update(['status' => 'EXPIRED', 'updated_at' => date('Y-m-d H:i:s')]);

            $outcome = (new SubscriptionActivator($db))->apply($invoice, $paidAt);
            $db->transComplete();
        } catch (Throwable $e) {
            $this->updateLog($logId, 'error', mb_substr($e->getMessage(), 0, 255));
            log_message('critical', 'Tripay PAID callback failed for {ref}: {msg}', ['ref' => $payment['merchant_ref'], 'msg' => $e->getMessage()]);

            return [500, ['success' => false]];
        }

        $this->updateLog($logId, 'processed', "PAID → {$outcome}");
        (new \App\Services\NotificationService())->notify((int) $payment['tenant_id'], 'payment.paid', 'Pembayaran diterima',
            "Pembayaran {$payment['merchant_ref']} terkonfirmasi. " . ($outcome === 'activated'
                ? 'Langganan Anda aktif.'
                : 'Kantor yang dipesan sudah tidak tersedia; tim kami akan menghubungi Anda.'),
            ['payment_id' => (int) $payment['id'], 'outcome' => $outcome]);
        Services::auditLogger()->record('payment.paid', [
            'tenant_id' => (int) $payment['tenant_id'], 'entity_type' => 'payment', 'entity_id' => (int) $payment['id'],
            'new' => ['merchant_ref' => $payment['merchant_ref'], 'outcome' => $outcome],
        ]);

        return [200, ['success' => true]];
    }

    private function reject(int $logId, int $status, string $message): array
    {
        $this->updateLog($logId, 'rejected', $message);
        log_message('warning', 'Tripay callback rejected: {msg}', ['msg' => $message]);

        return [$status, ['success' => false, 'message' => $message]];
    }

    private function updateLog(int $id, string $status, string $message): void
    {
        Database::connect()->table('payment_callbacks')->where('id', $id)->update([
            'process_status' => $status, 'process_message' => mb_substr($message, 0, 255),
            'processed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
