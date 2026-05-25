<?php

declare(strict_types=1);

final class AuthController
{
    public function __construct(
        private readonly Config $config,
        private readonly UserRepository $users,
        private readonly GoogleTokenVerifier $google,
        private readonly JwtService $jwt,
    ) {
    }

    public function google(): array
    {
        $data = request_json();
        $googleUser = $this->google->verify((string) ($data['idToken'] ?? ''));
        $user = $this->users->upsertGoogleUser($googleUser);
        $token = $this->jwt->issue(['sub' => (string) $user['id'], 'email' => $user['email']]);

        return ['status' => 200, 'body' => ['token' => $token, 'user' => $user, 'expiresIn' => $this->config->jwtTtlSeconds]];
    }
}
