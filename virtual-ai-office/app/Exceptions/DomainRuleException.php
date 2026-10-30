<?php

namespace App\Exceptions;

use RuntimeException;

/** A business rule was violated; the message is safe to show to the user. */
class DomainRuleException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
