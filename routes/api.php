<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChampionshipController;
use App\Http\Controllers\CoachController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\StadiumController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\UserController;

Route::post('/login', [AuthController::class, 'login'])->name('auth.login');

Route::prefix('/public')->name('public.')->group(function () {
    Route::get('/championships', [ChampionshipController::class, 'publicIndex'])->name('championships.index');
    Route::get('/championships/{id}', [ChampionshipController::class, 'publicShow'])->name('championships.show');
    Route::get('/teams/{id}', [TeamController::class, 'publicShow'])->name('teams.show');
    Route::get('/players/{id}', [PlayerController::class, 'publicShow'])->name('players.show');
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::middleware(['role:master'])->prefix('/users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('/{id}', [UserController::class, 'show'])->name('show');
        Route::put('/{id}', [UserController::class, 'update'])->name('update');
        Route::delete('/{id}', [UserController::class, 'destroy'])->name('destroy');
        Route::post('/{id}/championships', [UserController::class, 'syncChampionships'])->name('championships.sync');
        Route::delete('/{id}/championships/{cid}', [UserController::class, 'removeChampionship'])->name('championships.remove');
    });

    Route::prefix('/tournaments')->name('tournaments.')->group(function () {
        Route::get('/', [ChampionshipController::class, 'index'])->name('index');
        Route::get('/{id}', [ChampionshipController::class, 'show'])->name('show')->middleware('access:championship');
        Route::post('/', [ChampionshipController::class, 'store'])->name('store')->middleware('role:master');
        Route::put('/{id}', [ChampionshipController::class, 'update'])->name('update')->middleware('access:championship');
        Route::delete('/{id}', [ChampionshipController::class, 'destroy'])->name('destroy')->middleware('role:master');
    });

    Route::prefix('/teams')->name('teams.')->group(function () {
        Route::get('/', [TeamController::class, 'index'])->name('index');
        Route::get('/{id}', [TeamController::class, 'show'])->name('show')->middleware('access:team');
        Route::post('/', [TeamController::class, 'store'])->name('store');
        Route::put('/{id}', [TeamController::class, 'update'])->name('update')->middleware('access:team');
        Route::delete('/{id}', [TeamController::class, 'destroy'])->name('destroy')->middleware('access:team');
    });

    Route::prefix('/players')->name('players.')->group(function () {
        Route::get('/', [PlayerController::class, 'index'])->name('index');
        Route::get('/{id}', [PlayerController::class, 'show'])->name('show')->middleware('access:player');
        Route::post('/', [PlayerController::class, 'store'])->name('store');
        Route::put('/{id}', [PlayerController::class, 'update'])->name('update')->middleware('access:player');
        Route::delete('/{id}', [PlayerController::class, 'destroy'])->name('destroy')->middleware('access:player');
    });

    Route::prefix('/stadiums')->name('stadiums.')->group(function () {
        Route::get('/', [StadiumController::class, 'index'])->name('index');
        Route::get('/{id}', [StadiumController::class, 'show'])->name('show');
        Route::post('/', [StadiumController::class, 'store'])->name('store');
        Route::put('/{id}', [StadiumController::class, 'update'])->name('update');
        Route::delete('/{id}', [StadiumController::class, 'destroy'])->name('destroy')->middleware('role:master');
    });

    Route::prefix('/coaches')->name('coaches.')->group(function () {
        Route::get('/', [CoachController::class, 'index'])->name('index');
        Route::get('/{id}', [CoachController::class, 'show'])->name('show')->middleware('access:coach');
        Route::post('/', [CoachController::class, 'store'])->name('store');
        Route::put('/{id}', [CoachController::class, 'update'])->name('update')->middleware('access:coach');
        Route::delete('/{id}', [CoachController::class, 'destroy'])->name('destroy')->middleware('access:coach');
    });

    Route::prefix('/games')->name('games.')->group(function () {
        Route::get('/', [GameController::class, 'index'])->name('index');
        Route::get('/{id}', [GameController::class, 'show'])->name('show')->middleware('access:game');
        Route::post('/', [GameController::class, 'store'])->name('store');
        Route::put('/{id}', [GameController::class, 'update'])->name('update')->middleware('access:game');
        Route::delete('/{id}', [GameController::class, 'destroy'])->name('destroy')->middleware('access:game');
    });

    Route::prefix('/goals')->name('goals.')->group(function () {
        Route::get('/', [GoalController::class, 'index'])->name('index');
        Route::get('/{id}', [GoalController::class, 'show'])->name('show')->middleware('access:goal');
        Route::post('/', [GoalController::class, 'store'])->name('store');
        Route::put('/{id}', [GoalController::class, 'update'])->name('update')->middleware('access:goal');
        Route::delete('/{id}', [GoalController::class, 'destroy'])->name('destroy')->middleware('access:goal');
    });
});
