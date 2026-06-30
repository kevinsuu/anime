<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\Auth\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateJwt
{
    public function __construct(private readonly JwtService $jwt)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            throw new ApiException(401, 'missing_token', '請先登入');
        }

        $claims = $this->jwt->verify(substr($header, 7));
        $userId = (int) ($claims['sub'] ?? 0);
        if ($userId <= 0) {
            throw new ApiException(401, 'invalid_token', '登入憑證內容錯誤');
        }

        $request->attributes->set('auth_user_id', $userId);

        return $next($request);
    }
}
