<?php

declare(strict_types=1);

final class JwtService
{
    public function __construct(private readonly Config $config)
    {
    }

    public function issue(array $claims): string
    {
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + $this->config->jwtTtlSeconds,
        ]);

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $segments = [
            $this->base64Url(json_encode($header, JSON_THROW_ON_ERROR)),
            $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
        $signature = hash_hmac('sha256', implode('.', $segments), $this->config->jwtSecret, true);
        $segments[] = $this->base64Url($signature);

        return implode('.', $segments);
    }

    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new HttpException(401, 'invalid_token', '登入憑證格式錯誤');
        }

        [$header, $payload, $signature] = $parts;
        $expected = $this->base64Url(hash_hmac('sha256', "{$header}.{$payload}", $this->config->jwtSecret, true));
        if (!hash_equals($expected, $signature)) {
            throw new HttpException(401, 'invalid_token', '登入憑證無效');
        }

        $claims = json_decode($this->base64UrlDecode($payload), true);
        if (!is_array($claims) || !isset($claims['sub'], $claims['exp'])) {
            throw new HttpException(401, 'invalid_token', '登入憑證內容錯誤');
        }

        if ((int) $claims['exp'] < time()) {
            throw new HttpException(401, 'token_expired', '登入已過期，請重新登入');
        }

        return $claims;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padded = str_pad($value, strlen($value) + (4 - strlen($value) % 4) % 4, '=');
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false) {
            throw new HttpException(401, 'invalid_token', '登入憑證編碼錯誤');
        }

        return $decoded;
    }
}
