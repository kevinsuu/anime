<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class RefreshTokenService
{
    public function issue(User $user): string
    {
        $plain = Str::random(64);

        RefreshToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => Carbon::now()->addSeconds((int) config('services.jwt.refresh_ttl_seconds')),
        ]);

        return $plain;
    }

    /**
     * Validates the given refresh token, revokes it, and issues a new one
     * (rotation) so a leaked token can only be replayed once before the
     * next legitimate refresh invalidates it.
     */
    public function rotate(string $plain): array
    {
        $hash = hash('sha256', $plain);
        $record = RefreshToken::query()->where('token_hash', $hash)->first();

        if ($record === null || $record->revoked_at !== null) {
            throw new ApiException(401, 'refresh_token_invalid', '登入已失效，請重新登入');
        }

        if ($record->expires_at->isPast()) {
            throw new ApiException(401, 'refresh_token_expired', '登入已過期，請重新登入');
        }

        $record->update(['revoked_at' => Carbon::now()]);

        $user = $record->user;
        $newPlain = $this->issue($user);

        return [$user, $newPlain];
    }

    public function revokeAllForUser(User $user): void
    {
        RefreshToken::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);
    }
}
