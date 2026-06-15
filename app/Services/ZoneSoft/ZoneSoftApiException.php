<?php

namespace App\Services\ZoneSoft;

use RuntimeException;

class ZoneSoftApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
    ) {
        parent::__construct($message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
