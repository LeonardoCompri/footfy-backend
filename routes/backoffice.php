<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChampionshipController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\GameEventController;
use App\Http\Controllers\LeagueController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\StadiumController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TechnicalCommitteeMemberController;
use App\Http\Controllers\UserController;

Route::post('/login', [AuthController::class, 'login'])->name('auth.login');

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::middleware(['role:master'])->prefix('/users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('/{id}', [UserController::class, 'show'])->name('show');
        Route::put('/{id}', [UserController::class, 'update'])->name('update');
        Route::delete('/{id}', [UserController::class, 'destroy'])->name('destroy');
        Route::post('/{id}/leagues', [UserController::class, 'syncLeagues'])->name('leagues.sync');
        Route::delete('/{id}/leagues/{lid}', [UserController::class, 'removeLeague'])->name('leagues.remove');
    });

    Route::prefix('/leagues')->name('leagues.')->group(function () {
        Route::get('/', [LeagueController::class, 'index'])->name('index');
        Route::get('/{id}', [LeagueController::class, 'show'])->name('show')->middleware('access:league');
        Route::post('/', [LeagueController::class, 'store'])->name('store')->middleware('role:master');
        Route::put('/{id}', [LeagueController::class, 'update'])->name('update')->middleware('access:league');
        Route::delete('/{id}', [LeagueController::class, 'destroy'])->name('destroy')->middleware('role:master');
    });

    Route::prefix('/championships')->name('championships.')->group(function () {
        Route::get('/', [ChampionshipController::class, 'index'])->name('index');
        Route::get('/{id}', [ChampionshipController::class, 'show'])->name('show');
        Route::post('/', [ChampionshipController::class, 'store'])->name('store')->middleware('role:master');
        Route::put('/{id}', [ChampionshipController::class, 'update'])->name('update')->middleware('role:master');
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
        Route::get('/{id}', [StadiumController::class, 'show'])->name('show')->middleware('access:stadium');
        Route::post('/', [StadiumController::class, 'store'])->name('store');
        Route::put('/{id}', [StadiumController::class, 'update'])->name('update')->middleware('access:stadium');
        Route::delete('/{id}', [StadiumController::class, 'destroy'])->name('destroy')->middleware(['role:master', 'access:stadium']);
    });

    Route::prefix('/technical-committee-members')->name('technical-committee-members.')->group(function () {
        Route::get('/', [TechnicalCommitteeMemberController::class, 'index'])->name('index');
        Route::get('/{id}', [TechnicalCommitteeMemberController::class, 'show'])->name('show')->middleware('access:technical_committee_member');
        Route::post('/', [TechnicalCommitteeMemberController::class, 'store'])->name('store');
        Route::put('/{id}', [TechnicalCommitteeMemberController::class, 'update'])->name('update')->middleware('access:technical_committee_member');
        Route::delete('/{id}', [TechnicalCommitteeMemberController::class, 'destroy'])->name('destroy')->middleware('access:technical_committee_member');
    });

    Route::prefix('/games')->name('games.')->group(function () {
        Route::get('/', [GameController::class, 'index'])->name('index');
        Route::get('/{id}', [GameController::class, 'show'])->name('show')->middleware('access:game');
        Route::post('/', [GameController::class, 'store'])->name('store');
        Route::put('/{id}', [GameController::class, 'update'])->name('update')->middleware('access:game');
        Route::delete('/{id}', [GameController::class, 'destroy'])->name('destroy')->middleware('access:game');
    });

    Route::prefix('/game-events')->name('game-events.')->group(function () {
        Route::get('/', [GameEventController::class, 'index'])->name('index');
        Route::get('/{id}', [GameEventController::class, 'show'])->name('show')->middleware('access:game_event');
        Route::post('/', [GameEventController::class, 'store'])->name('store');
        Route::put('/{id}', [GameEventController::class, 'update'])->name('update')->middleware('access:game_event');
        Route::delete('/{id}', [GameEventController::class, 'destroy'])->name('destroy')->middleware('access:game_event');
    });
});
