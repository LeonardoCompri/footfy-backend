<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChampionshipController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('/tournaments')->group(function () {
        Route::get('/', [ChampionshipController::class, 'index']);
        Route::post('/', [ChampionshipController::class, 'store']);
    });
});