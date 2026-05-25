<?php

declare(strict_types=1);

final class GoogleTokenVerifier
{
    public function __construct(private readonly Config $config)
    {
    }

    public function verify(string $idToken): array
    {
        if ($idToken === '') {
            throw new HttpException(422, 'validation_failed', '缺少 Google ID token', ['idToken' => 'required']);
        }

        if ($this->config->devAuthBypass && str_starts_with($idToken, 'dev:')) {
            $email = substr($idToken, 4) ?: 'dev@example.com';
            return [
                'sub' => 'dev-' . sha1($email),
                'email' => $email,
                'name' => '開發測試使用者',
                'picture' => '',
            ];
        }

        if ($this->config->googleClientId === '') {
            throw new HttpException(500, 'google_client_missing', '後端尚未設定 Google OAuth client ID');
        }

        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);
        $raw = @file_get_contents($url);
        if ($raw === false) {
            throw new HttpException(401, 'google_token_invalid', 'Google 登入驗證失敗');
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new HttpException(401, 'google_token_invalid', 'Google 登入回應格式錯誤');
        }

        if (($payload['aud'] ?? '') !== $this->config->googleClientId) {
            throw new HttpException(401, 'google_audience_invalid', 'Google 登入來源不符合設定');
        }

        if ((int) ($payload['exp'] ?? 0) < time()) {
            throw new HttpException(401, 'google_token_expired', 'Google 登入已過期');
        }

        if (($payload['sub'] ?? '') === '' || ($payload['email'] ?? '') === '') {
            throw new HttpException(401, 'google_token_invalid', 'Google 登入資料不完整');
        }

        return [
            'sub' => $payload['sub'],
            'email' => $payload['email'],
            'name' => $payload['name'] ?? '',
            'picture' => $payload['picture'] ?? '',
        ];
    }
}
