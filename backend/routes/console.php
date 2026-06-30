<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('anime:scrape-acgsecrets')
    ->weeklyOn(1, '05:00')
    ->then(function (): void {
        Artisan::call('anime:import-acgsecrets');
    });
