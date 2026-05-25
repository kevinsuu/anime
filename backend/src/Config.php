<?php

declare(strict_types=1);

final class Config
{
    public function __construct(
        public readonly string $dbDsn,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        public readonly string $jwtSecret,
        public readonly int $jwtTtlSeconds,
        public readonly string $googleClientId,
        public readonly array $allowedOrigins,
        public readonly bool $devAuthBypass,
    ) {
    }

    public static function fromEnv(): self
    {
        $host = getenv('DB_HOST') ?: 'mysql';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'anime_tracker';
        $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

        return new self(
            getenv('DB_DSN') ?: "mysql:host={$host};port={$port};dbname={$name};charset={$charset}",
            getenv('DB_USER') ?: 'anime',
            getenv('DB_PASSWORD') ?: 'anime_password',
            getenv('JWT_SECRET') ?: 'dev-only-change-me',
            (int) (getenv('JWT_TTL_SECONDS') ?: 3600),
            getenv('GOOGLE_CLIENT_ID') ?: '',
            array_values(array_filter(array_map('trim', explode(',', getenv('ALLOWED_ORIGINS') ?: 'http://localhost:5173')))),
            filter_var(getenv('DEV_AUTH_BYPASS') ?: 'false', FILTER_VALIDATE_BOOL),
        );
    }
}
