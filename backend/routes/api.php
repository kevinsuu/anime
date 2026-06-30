<?php

use App\Http\Controllers\Api\AnimeController;
use App\Http\Controllers\Api\AnimeListController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/google', [AuthController::class, 'google']);

Route::get('/anime', [AnimeController::class, 'index']);
Route::post('/anime', [AnimeController::class, 'store'])->middleware('jwt');

Route::middleware('jwt')->group(function (): void {
    Route::get('/me', [AnimeListController::class, 'me']);
    Route::post('/me/share-slug/regenerate', [AnimeListController::class, 'regenerateSlug']);
    Route::get('/my/anime-list', [AnimeListController::class, 'index']);
    Route::post('/my/anime-list', [AnimeListController::class, 'store']);
    Route::patch('/my/anime-list/{item}', [AnimeListController::class, 'update']);
    Route::delete('/my/anime-list/{item}', [AnimeListController::class, 'destroy']);
});

Route::get('/public/lists/{slug}', [AnimeListController::class, 'publicList']);
