<?php

namespace App\Services\ZoneSoft;

use RuntimeException;
use Throwable;

class ZoneSoftApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function isRateLimited(): bool
    {
        if ($this->statusCode === 429) {
            return true;
        }

        $message = mb_strtolower($this->getMessage());

        return str_contains($message, 'rate limit')
            || str_contains($message, 'too many requests')
            || str_contains($message, 'exceeded the rate limit');
    }

    public function isTransient(): bool
    {
        return $this->statusCode === 0
            || in_array($this->statusCode, [408, 425, 429, 500, 502, 503, 504], true);
    }
}
