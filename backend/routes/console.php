<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 每週日 05:00 爬近 2 年季度（預設行為）→ 透過 Bangumi API 補上 acgsecrets 未提供的集數 → import
// → 以 IndexNow 通知搜尋引擎本次 import 實際變動的頁面
Schedule::command('anime:scrape-acgsecrets')
    ->weeklyOn(0, '05:00')
    ->then(function (): void {
        $importStartedAt = now()->toIso8601String();
        Artisan::call('anime:enrich-episode-counts');
        Artisan::call('anime:import-acgsecrets');
        Artisan::call('anime:submit-indexnow', ['--since' => $importStartedAt]);
    })
    ->onFailure(function (): void {
        logger()->error('anime:scrape-acgsecrets scheduled run failed');
    });
