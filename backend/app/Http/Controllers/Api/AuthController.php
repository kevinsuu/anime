<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\GoogleTokenVerifier;
use App\Services\Auth\JwtService;
use App\Services\Auth\RefreshTokenService;
use App\Services\Shared\SlugGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    public function google(
        Request $request,
        GoogleTokenVerifier $google,
        JwtService $jwt,
        RefreshTokenService $refreshTokens,
        SlugGenerator $slugs,
    ): JsonResponse {
        $googleUser = $google->verify((string) $request->input('idToken', ''));
        $user = User::query()->where('google_sub', $googleUser['sub'])->first();

        if ($user === null) {
            $user = User::query()->create([
                'google_sub' => $googleUser['sub'],
                'email' => $googleUser['email'],
                'display_name' => $googleUser['name'],
                'avatar_url' => $googleUser['picture'],
                'public_slug' => $slugs->uniqueUserSlug(),
            ]);
        } else {
            $user->update([
                'email' => $googleUser['email'],
                'display_name' => $googleUser['name'],
                'avatar_url' => $googleUser['picture'],
            ]);
        }

        return $this->tokenResponse($user, $jwt, $refreshTokens);
    }

    public function refresh(
        Request $request,
        JwtService $jwt,
        RefreshTokenService $refreshTokens,
    ): JsonResponse {
        $plain = (string) $request->input('refreshToken', '');
        if ($plain === '') {
            throw new ApiException(422, 'validation_failed', '缺少 refresh token', ['refreshToken' => 'required']);
        }

        [$user, $newPlain] = $refreshTokens->rotate($plain);

        return $this->tokenResponse($user, $jwt, $refreshTokens, $newPlain);
    }

    public function logout(Request $request, RefreshTokenService $refreshTokens): JsonResponse
    {
        $user = User::query()->findOrFail((int) $request->attributes->get('auth_user_id'));
        $refreshTokens->revokeAllForUser($user);

        return response()->json(['ok' => true]);
    }

    private function tokenResponse(
        User $user,
        JwtService $jwt,
        RefreshTokenService $refreshTokens,
        ?string $refreshToken = null,
    ): JsonResponse {
        $token = $jwt->issue(['sub' => (string) $user->id, 'email' => $user->email]);

        return response()->json([
            'token' => $token,
            'refreshToken' => $refreshToken ?? $refreshTokens->issue($user),
            'user' => $user->fresh(),
            'expiresIn' => (int) config('services.jwt.ttl_seconds'),
        ]);
    }
}
