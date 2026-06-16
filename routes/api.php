<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChampionshipController;
use App\Http\Controllers\CoachController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\StadiumController;
use App\Http\Controllers\TeamController;

Route::post('/login', [AuthController::class, 'login'])->name('auth.login');

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::prefix('/tournaments')->name('tournaments.')->group(function () {
        Route::get('/', [ChampionshipController::class, 'index'])->name('index');
        Route::get('/{id}', [ChampionshipController::class, 'show'])->name('show');
        Route::post('/', [ChampionshipController::class, 'store'])->name('store');
        Route::put('/{id}', [ChampionshipController::class, 'update'])->name('update');
        Route::delete('/{id}', [ChampionshipController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('/teams')->name('teams.')->group(function () {
        Route::get('/', [TeamController::class, 'index'])->name('index');
        Route::get('/{id}', [TeamController::class, 'show'])->name('show');
        Route::post('/', [TeamController::class, 'store'])->name('store');
        Route::put('/{id}', [TeamController::class, 'update'])->name('update');
        Route::delete('/{id}', [TeamController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('/players')->name('players.')->group(function () {
        Route::get('/', [PlayerController::class, 'index'])->name('index');
        Route::get('/{id}', [PlayerController::class, 'show'])->name('show');
        Route::post('/', [PlayerController::class, 'store'])->name('store');
        Route::put('/{id}', [PlayerController::class, 'update'])->name('update');
        Route::delete('/{id}', [PlayerController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('/stadiums')->name('stadiums.')->group(function () {
        Route::get('/', [StadiumController::class, 'index'])->name('index');
        Route::get('/{id}', [StadiumController::class, 'show'])->name('show');
        Route::post('/', [StadiumController::class, 'store'])->name('store');
        Route::put('/{id}', [StadiumController::class, 'update'])->name('update');
        Route::delete('/{id}', [StadiumController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('/coaches')->name('coaches.')->group(function () {
        Route::get('/', [CoachController::class, 'index'])->name('index');
        Route::get('/{id}', [CoachController::class, 'show'])->name('show');
        Route::post('/', [CoachController::class, 'store'])->name('store');
        Route::put('/{id}', [CoachController::class, 'update'])->name('update');
        Route::delete('/{id}', [CoachController::class, 'destroy'])->name('destroy');
    });
});