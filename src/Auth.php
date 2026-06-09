<?php

declare(strict_types=1);

final class Auth
{
    public function __construct(
        private readonly Database $db,
        private readonly Jwt $jwt
    ) {
    }

    public function currentUser(bool $required = false): ?array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
            if ($required) {
                throw new HttpException(401, 'Authentification requise.');
            }
            return null;
        }

        $payload = $this->jwt->decode(trim($matches[1]), 'access');
        $user = $this->db->one('SELECT * FROM users WHERE id = :id', ['id' => (int) ($payload['user_id'] ?? 0)]);
        if (!$user || !$this->toBool($user['is_active'] ?? false)) {
            throw new HttpException(401, 'Utilisateur introuvable ou inactif.');
        }
        return $user;
    }

    public function requireAdmin(): array
    {
        $user = $this->currentUser(true);
        if (!$this->isAdmin($user)) {
            throw new HttpException(403, 'Acces refuse.');
        }
        return $user;
    }

    public function isAdmin(?array $user): bool
    {
        if (!$user) {
            return false;
        }
        return ($user['role'] ?? '') === 'admin'
            || $this->toBool($user['is_staff'] ?? false)
            || $this->toBool($user['is_superuser'] ?? false);
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        if (str_starts_with($hash, 'pbkdf2_sha256$')) {
            $parts = explode('$', $hash);
            if (count($parts) !== 4) {
                return false;
            }
            [, $iterations, $salt, $expected] = $parts;
            $derived = base64_encode(hash_pbkdf2('sha256', $password, $salt, (int) $iterations, 0, true));
            return hash_equals($expected, $derived);
        }

        return password_verify($password, $hash);
    }

    public function makeDjangoPassword(string $password): string
    {
        $iterations = 870000;
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $salt = '';
        for ($i = 0; $i < 12; $i++) {
            $salt .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $hash = base64_encode(hash_pbkdf2('sha256', $password, $salt, $iterations, 0, true));
        return sprintf('pbkdf2_sha256$%d$%s$%s', $iterations, $salt, $hash);
    }

    public function tokensFor(array $user): array
    {
        return [
            'access' => $this->jwt->issue($user, 'access'),
            'refresh' => $this->jwt->issue($user, 'refresh'),
        ];
    }

    private function toBool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true', 'yes', 'on'], true);
    }
}

