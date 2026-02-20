<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChampionshipController;

Route::post('/login', [AuthController::class, 'login'])->name('auth.login');

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::prefix('/tournaments')->name('tournaments.')->group(function () {
        Route::get('/', [ChampionshipController::class, 'index'])->name('index');
        Route::get('/{id}', [ChampionshipController::class, 'show'])->name('show');
        Route::post('/', [ChampionshipController::class, 'store'])->name('store');
        Route::patch('/{id}', [ChampionshipController::class, 'update'])->name('update');
        Route::delete('/{id}', [ChampionshipController::class, 'destroy'])->name('destroy');
    });
});