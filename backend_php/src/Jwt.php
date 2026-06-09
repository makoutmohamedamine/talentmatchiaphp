<?php

declare(strict_types=1);

final class Jwt
{
    public function __construct(private readonly Config $config)
    {
    }

    public function issue(array $user, string $type = 'access'): string
    {
        $now = time();
        $ttl = $type === 'refresh' ? 86400 : 28800;

        return $this->encode([
            'token_type' => $type,
            'exp' => $now + $ttl,
            'iat' => $now,
            'jti' => bin2hex(random_bytes(16)),
            'user_id' => (int) $user['id'],
        ]);
    }

    public function encode(array $payload): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $segments = [
            self::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)),
            self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
        $segments[] = self::base64UrlEncode(hash_hmac('sha256', implode('.', $segments), $this->secret(), true));
        return implode('.', $segments);
    }

    public function decode(string $token, ?string $expectedType = null): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new HttpException(401, 'Token invalide.');
        }

        [$header64, $payload64, $signature64] = $parts;
        $expected = self::base64UrlEncode(hash_hmac('sha256', $header64 . '.' . $payload64, $this->secret(), true));
        if (!hash_equals($expected, $signature64)) {
            throw new HttpException(401, 'Signature token invalide.');
        }

        $payload = json_decode(self::base64UrlDecode($payload64), true);
        if (!is_array($payload)) {
            throw new HttpException(401, 'Payload token invalide.');
        }
        if (($payload['exp'] ?? 0) < time()) {
            throw new HttpException(401, 'Token expire.');
        }
        if ($expectedType !== null && ($payload['token_type'] ?? null) !== $expectedType) {
            throw new HttpException(401, 'Type token invalide.');
        }

        return $payload;
    }

    private function secret(): string
    {
        return $this->config->get('APP_KEY')
            ?? $this->config->get('SECRET_KEY')
            ?? 'change-me';
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'), true) ?: '';
    }
}

