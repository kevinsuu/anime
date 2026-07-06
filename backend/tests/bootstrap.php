<?php

// Laravel's dotenv bootstrap step probes for a .env file even though this repo
// intentionally has none (see CLAUDE.md) — all vars come from docker-compose's
// environment: block, and phpunit.xml's <php><env> block covers the rest. The
// probe failing surfaces as a spurious file_get_contents warning on every
// Feature test.
//
// Touching an empty .env gives the probe a file to find without changing any
// config value — but backend/ is bind-mounted into the container, so leaving
// that file behind would persist on the host and silently break `artisan
// serve` on its next restart (its dotenv bootstrap would then load this empty
// file *instead of* the container's real environment variables). Only create
// it if absent, and always remove it again when the PHPUnit process ends.
$envPath = dirname(__DIR__).'/.env';
if (! is_file($envPath)) {
    touch($envPath);
    register_shutdown_function(static function () use ($envPath): void {
        @unlink($envPath);
    });
}

// phpunit.xml's <env ... force="true"> only updates putenv()/$_ENV, not
// $_SERVER — but Laravel's env() helper (vlucas/phpdotenv Repository) reads
// $_SERVER first. Without this, force="true" silently does nothing whenever
// docker-compose's environment: block has already set the same variable at
// the container/process level (e.g. DB_CONNECTION=mysql, CACHE_STORE=file):
// $_SERVER keeps the container's real value and RefreshDatabase/Cache tests
// end up running against the actual dev database/cache instead of the
// isolated sqlite:memory / array overrides declared in phpunit.xml.
foreach ($_ENV as $key => $value) {
    $_SERVER[$key] = $value;
}

require dirname(__DIR__).'/vendor/autoload.php';
