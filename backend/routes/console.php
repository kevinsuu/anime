<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 每週日 05:00 爬近 2 年季度（預設行為），爬完立即 import
Schedule::command('anime:scrape-acgsecrets')
    ->weeklyOn(0, '05:00')
    ->then(function (): void {
        Artisan::call('anime:import-acgsecrets');
    })
    ->onFailure(function (): void {
        logger()->error('anime:scrape-acgsecrets scheduled run failed');
    });
