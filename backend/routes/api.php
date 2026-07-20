<?php

use App\Http\Controllers\Api\AnimeController;
use App\Http\Controllers\Api\AnimeListController;
use App\Http\Controllers\Api\AnimeSummaryController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CollectionController;
use App\Http\Controllers\Api\MeBootstrapController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/google', [AuthController::class, 'google']);
Route::post('/auth/refresh', [AuthController::class, 'refresh']);
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('jwt');

Route::get('/anime', [AnimeController::class, 'index']);
Route::get('/anime/tags', [AnimeController::class, 'tags']);
Route::get('/anime/summaries', [AnimeSummaryController::class, 'index']);
Route::get('/anime/{id}', [AnimeController::class, 'show']);

Route::middleware('jwt')->group(function (): void {
    Route::get('/me', [AnimeListController::class, 'me']);
    Route::get('/me/bootstrap', MeBootstrapController::class);
    Route::post('/me/share-slug/regenerate', [AnimeListController::class, 'regenerateSlug']);
    Route::get('/my/anime-list', [AnimeListController::class, 'index']);
    Route::get('/my/anime-list/tags', [AnimeListController::class, 'tags']);
    Route::post('/my/anime-list', [AnimeListController::class, 'store']);
    Route::patch('/my/anime-list/{item}', [AnimeListController::class, 'update']);
    Route::delete('/my/anime-list/{item}', [AnimeListController::class, 'destroy']);

    Route::get('/my/collections', [CollectionController::class, 'index']);
    Route::post('/my/collections', [CollectionController::class, 'store']);
    Route::patch('/my/collections/{id}', [CollectionController::class, 'update']);
    Route::delete('/my/collections/{id}', [CollectionController::class, 'destroy']);
    Route::post('/my/collections/{id}/items', [CollectionController::class, 'addItem']);
    Route::delete('/my/collections/{id}/items/{listItemId}', [CollectionController::class, 'removeItem']);
});

Route::get('/public/lists/{slug}', [AnimeListController::class, 'publicList']);
Route::get('/public/collections/{slug}', [CollectionController::class, 'publicShow']);
