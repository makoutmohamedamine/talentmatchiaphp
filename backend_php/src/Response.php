<?php

declare(strict_types=1);

final class Response
{
    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        if ($status === 204) {
            return;
        }

        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function noContent(): void
    {
        self::json(null, 204);
    }
}

