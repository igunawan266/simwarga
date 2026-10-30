<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Tripay payment gateway. Every value is overridden from .env (tripay.*) — never commit keys.
 * Endpoints/signatures follow Tripay's official PHP library (trijayadigital/tripay-php);
 * re-check https://tripay.co.id/developer when upgrading.
 */
class Tripay extends BaseConfig
{
    /** sandbox | production */
    public string $mode = 'sandbox';

    public string $merchantCode = '';
    public string $apiKey       = '';
    public string $privateKey   = '';

    /** Payment window offered to the customer (minutes), capped by the invoice due date. */
    public int $expiryMinutes = 1440;

    /**
     * Before activating a subscription, re-read the transaction from Tripay's API
     * (server-to-server) and require it to agree with the callback.
     */
    public bool $verifyWithApi = true;

    /** Optional comma-separated callback source IPs. Empty = do not filter by IP. */
    public string $callbackAllowedIps = '';

    public int $timeout = 20;

    /** Local mock server for development/E2E tests. Ignored in production. */
    public string $baseUrlOverride = '';

    public function baseUrl(): string
    {
        if ($this->baseUrlOverride !== '' && ENVIRONMENT !== 'production') {
            return rtrim($this->baseUrlOverride, '/') . '/';
        }

        return $this->mode === 'production' ? 'https://tripay.co.id/api/' : 'https://tripay.co.id/api-sandbox/';
    }

    public function isConfigured(): bool
    {
        return $this->merchantCode !== '' && $this->apiKey !== '' && $this->privateKey !== '';
    }

    public function isSandbox(): bool
    {
        return $this->mode !== 'production';
    }
}
