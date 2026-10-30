<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * AI provider settings. Keys come only from .env (ai.*) and never reach the browser.
 */
class Ai extends BaseConfig
{
    public string $anthropicApiKey = '';

    /** Model used when an agent has none selected (must exist in ai_models). */
    public string $defaultModel = 'claude-opus-5-5';

    /** Per-request ceiling; agent tasks are short business deliverables. */
    public int $maxTokens = 16000;

    /** Seconds per API request (the SDK retries 429/5xx/connection errors itself). */
    public float $timeout = 300.0;

    public int $sdkMaxRetries = 2;

    /** Worker-level attempts per task for transient failures (after the SDK's own retries). */
    public int $taskMaxAttempts = 3;

    /** Server-side refusal fallback (beta) for models that support it. */
    public bool $refusalFallback = true;

    /** Max tool round-trips per task before it is stopped. */
    public int $maxToolRounds = 8;

    /** Development only: let HTTP tools reach http:// and private addresses (local mocks). Ignored in production. */
    public bool $toolsAllowPrivateNetwork = false;

    public function allowPrivateToolTargets(): bool
    {
        return $this->toolsAllowPrivateNetwork && ENVIRONMENT !== 'production';
    }

    /** Local mock of the Messages API for development/E2E tests. Ignored in production. */
    public string $baseUrlOverride = '';

    public function baseUrl(): ?string
    {
        return $this->baseUrlOverride !== '' && ENVIRONMENT !== 'production' ? $this->baseUrlOverride : null;
    }

    public function isConfigured(): bool
    {
        return $this->anthropicApiKey !== '';
    }
}
