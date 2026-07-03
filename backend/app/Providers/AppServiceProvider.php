<?php

namespace App\Providers;

use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
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
}
