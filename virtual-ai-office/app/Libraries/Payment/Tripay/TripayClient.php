<?php

namespace App\Libraries\Payment\Tripay;

use Config\Tripay as TripayConfig;

/**
 * Tripay API client (closed payment).
 *
 * - GET  merchant/payment-channel
 * - POST transaction/create   signature = HMAC-SHA256(merchantCode . merchantRef . amount, privateKey)
 * - GET  transaction/detail?reference=...
 * - Callback: header X-Callback-Signature = HMAC-SHA256(raw JSON body, privateKey)
 */
class TripayClient
{
    private readonly HttpTransport $http;

    public function __construct(private readonly TripayConfig $config, ?HttpTransport $http = null)
    {
        $this->http = $http ?? new CurlTransport($config->timeout);
    }

    public function config(): TripayConfig
    {
        return $this->config;
    }

    public function paymentChannels(): array
    {
        return $this->call('GET', 'merchant/payment-channel');
    }

    /**
     * @param list<array{sku: string, name: string, price: int, quantity: int}> $items
     *        amount is derived from the items, exactly as Tripay validates it.
     */
    public function createTransaction(string $method, string $merchantRef, array $items, array $customer, int $expiresAt, string $returnUrl): array
    {
        $amount = array_sum(array_map(static fn ($i) => (int) $i['price'] * (int) $i['quantity'], $items));

        return $this->call('POST', 'transaction/create', form: [
            'method'         => $method,
            'merchant_ref'   => $merchantRef,
            'amount'         => $amount,
            'customer_name'  => $customer['name'],
            'customer_email' => $customer['email'],
            'customer_phone' => $customer['phone'] ?? '',
            'order_items'    => $items,
            'return_url'     => $returnUrl,
            'expired_time'   => $expiresAt,
            'signature'      => $this->transactionSignature($merchantRef, $amount),
        ]);
    }

    public function transactionDetail(string $reference): array
    {
        return $this->call('GET', 'transaction/detail', query: ['reference' => $reference]);
    }

    public function transactionSignature(string $merchantRef, int $amount): string
    {
        return hash_hmac('sha256', $this->config->merchantCode . $merchantRef . $amount, $this->config->privateKey);
    }

    public function isValidCallbackSignature(string $rawBody, string $signature): bool
    {
        return $signature !== '' && hash_equals(hash_hmac('sha256', $rawBody, $this->config->privateKey), $signature);
    }

    private function call(string $method, string $path, array $query = [], array $form = []): array
    {
        if (! $this->config->isConfigured()) {
            throw new TripayException('Payment gateway Tripay belum dikonfigurasi.');
        }

        $res  = $this->http->send($method, $this->config->baseUrl() . $path, [
            'Authorization' => 'Bearer ' . $this->config->apiKey,
            'Accept'        => 'application/json',
        ], $query, $form);
        $json = json_decode($res['body'], true);

        if (! is_array($json) || empty($json['success'])) {
            $message = is_array($json) && isset($json['message']) ? (string) $json['message'] : 'HTTP ' . $res['status'];

            throw new TripayException('Tripay menolak permintaan: ' . $message);
        }

        return $json['data'] ?? [];
    }
}
