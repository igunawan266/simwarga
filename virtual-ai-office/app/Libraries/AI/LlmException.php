<?php

namespace App\Libraries\AI;

use RuntimeException;

/** $retryable = worth trying again later (rate limit, overload, network). */
class LlmException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
