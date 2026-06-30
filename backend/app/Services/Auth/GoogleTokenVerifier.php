<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;

final class GoogleTokenVerifier
{
    public function verify(string $idToken): array
    {
        if ($idToken === '') {
            throw new ApiException(422, 'validation_failed', '缺少 Google ID token', ['idToken' => 'required']);
        }

        if ((bool) config('services.dev_auth_bypass') && str_starts_with($idToken, 'dev:')) {
            $email = substr($idToken, 4) ?: 'dev@example.com';

            return [
                'sub' => 'dev-'.sha1($email),
                'email' => $email,
                'name' => '開發測試使用者',
                'picture' => '',
            ];
        }

        $clientId = (string) config('services.google.client_id');
        if ($clientId === '') {
            throw new ApiException(500, 'google_client_missing', '後端尚未設定 Google OAuth client ID');
        }

        $response = Http::timeout((int) config('services.http.timeout_seconds'))
            ->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken]);

        if (! $response->successful()) {
            throw new ApiException(401, 'google_token_invalid', 'Google 登入驗證失敗');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new ApiException(401, 'google_token_invalid', 'Google 登入回應格式錯誤');
        }

        if (($payload['aud'] ?? '') !== $clientId) {
            throw new ApiException(401, 'google_audience_invalid', 'Google 登入來源不符合設定');
        }

        if ((int) ($payload['exp'] ?? 0) < time()) {
            throw new ApiException(401, 'google_token_expired', 'Google 登入已過期');
        }

        if (($payload['sub'] ?? '') === '' || ($payload['email'] ?? '') === '') {
            throw new ApiException(401, 'google_token_invalid', 'Google 登入資料不完整');
        }

        return [
            'sub' => $payload['sub'],
            'email' => $payload['email'],
            'name' => $payload['name'] ?? '',
            'picture' => $payload['picture'] ?? '',
        ];
    }
}
