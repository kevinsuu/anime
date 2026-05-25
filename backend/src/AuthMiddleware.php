<?php

declare(strict_types=1);

final class AuthMiddleware
{
    public function __construct(private readonly JwtService $jwt)
    {
    }

    public function userId(): int
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            throw new HttpException(401, 'missing_token', '缺少登入憑證');
        }

        $claims = $this->jwt->verify(substr($header, 7));
        return (int) $claims['sub'];
    }
}
