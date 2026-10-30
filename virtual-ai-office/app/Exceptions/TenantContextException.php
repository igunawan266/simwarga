<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when tenant-owned data is touched without a verified tenant context. */
class TenantContextException extends RuntimeException
{
}
