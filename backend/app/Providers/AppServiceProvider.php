<?php

namespace App\Providers;

use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    private const JWT_SECRET_MIN_LENGTH = 32;

    /** @var list<string> */
    private const JWT_SECRET_PLACEHOLDER_MARKERS = [
        'change-me',
        'changeme',
        'dev-only',
        'dummy',
        'example',
        'local-only',
        'placeholder',
        'replace-with',
        'test-only',
        'your-jwt-secret',
        'your-secret',
    ];

    /**
     * Artisan commands that wipe the whole database (all tables dropped and
     * recreated, or dropped outright). A stray/muscle-memory invocation of
     * one of these has wiped production-equivalent local data more than
     * once, so they require typing a confirmation phrase — every time,
     * regardless of environment — before Artisan will even resolve them.
     *
     * @var list<string>
     */
    private const DESTRUCTIVE_COMMANDS = ['migrate:fresh', 'migrate:refresh', 'db:wipe'];

    private const CONFIRMATION_PHRASE = 'yes, wipe the database';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->assertProductionJwtSecretIsSecure();

        $this->app['events']->listen(ArtisanStarting::class, function (): void {
            $command = $_SERVER['argv'][1] ?? null;

            if (! in_array($command, self::DESTRUCTIVE_COMMANDS, true)) {
                return;
            }

            $output = new \Symfony\Component\Console\Output\ConsoleOutput();
            $output->writeln("<error>{$command} drops every table and destroys all data (catalog, users, watch lists).</error>");
            $output->write('Type "'.self::CONFIRMATION_PHRASE.'" to proceed, or anything else to abort: ');

            $answer = trim((string) fgets(STDIN));

            if ($answer !== self::CONFIRMATION_PHRASE) {
                throw new RuntimeException("{$command} aborted: confirmation phrase did not match.");
            }
        });
    }

    private function assertProductionJwtSecretIsSecure(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        $secret = trim((string) config('services.jwt.secret'));

        if ($secret === '') {
            throw new RuntimeException('JWT_SECRET must be configured in production.');
        }

        if (strlen($secret) < self::JWT_SECRET_MIN_LENGTH) {
            throw new RuntimeException('JWT_SECRET must be at least 32 characters in production.');
        }

        $normalizedSecret = strtolower($secret);
        foreach (self::JWT_SECRET_PLACEHOLDER_MARKERS as $marker) {
            if (str_contains($normalizedSecret, $marker)) {
                throw new RuntimeException('JWT_SECRET must not use a placeholder value in production.');
            }
        }
    }
}
