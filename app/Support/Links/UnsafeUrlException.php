<?php

namespace App\Support\Links;

use RuntimeException;

final class UnsafeUrlException extends RuntimeException
{
    /** One of: scheme, credentials, port, blocked, dns. */
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Unsafe URL: {$reason}");
    }
}