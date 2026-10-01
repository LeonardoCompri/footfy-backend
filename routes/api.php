<?php

use App\Http\Controllers\ChampionshipController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\LeagueController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\TeamController;

Route::prefix('/public')->name('public.')->middleware(['public_route'])->group(function () {
    Route::get('/championships', [ChampionshipController::class, 'publicIndex'])->name('championships.index');
    Route::get('/championships/{id}', [ChampionshipController::class, 'publicShow'])->name('championships.show');
    Route::get('/leagues/{id}', [LeagueController::class, 'publicShow'])->name('leagues.show');
    Route::get('/leagues/{id}/table', [LeagueController::class, 'publicTable'])->name('leagues.table');
    Route::get('/teams/{id}', [TeamController::class, 'publicShow'])->name('teams.show');
    Route::get('/players/{id}', [PlayerController::class, 'publicShow'])->name('players.show');
    Route::get('/games/{id}', [GameController::class, 'publicShow'])->name('games.show');
});
