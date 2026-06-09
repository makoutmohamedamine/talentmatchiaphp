<?php

declare(strict_types=1);

final class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $payload = []
    ) {
        parent::__construct($message, $status);
    }
}

