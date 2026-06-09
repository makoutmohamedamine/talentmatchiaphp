<?php

declare(strict_types=1);

final class Config
{
    private array $values = [];

    public function __construct(private readonly string $rootPath)
    {
        $projectRoot = dirname($rootPath);
        $this->loadEnv($projectRoot . '/backend/.env', false);
        $this->loadEnv($rootPath . '/.env', true);
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    public function projectRoot(): string
    {
        return dirname($this->rootPath);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $this->values[$key] ?? $default;
        }
        return (string) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function loadEnv(string $path, bool $override): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($key === '') {
                continue;
            }

            $value = trim($value, "\"'");
            if (!$override && (isset($this->values[$key]) || getenv($key) !== false)) {
                continue;
            }

            $this->values[$key] = $value;
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}
