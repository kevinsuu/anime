<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\GoogleTokenVerifier;
use App\Services\Auth\JwtService;
use App\Services\Shared\SlugGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    public function google(
        Request $request,
        GoogleTokenVerifier $google,
        JwtService $jwt,
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

        $token = $jwt->issue(['sub' => (string) $user->id, 'email' => $user->email]);

        return response()->json([
            'token' => $token,
            'user' => $user->fresh(),
            'expiresIn' => (int) config('services.jwt.ttl_seconds'),
        ]);
    }
}
