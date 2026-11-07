<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChampionshipController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\GameEventController;
use App\Http\Controllers\GameEventTypeController;
use App\Http\Controllers\LeagueController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StadiumController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TechnicalCommitteeMemberController;
use App\Http\Controllers\UserController;

Route::post('/login', [AuthController::class, 'login'])->name('auth.login');

Route::middleware(['auth:sanctum'])->where(['id' => '[0-9]+', 'lid' => '[0-9]+'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::prefix('/me')->name('profile.')->group(function () {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        Route::put('/', [ProfileController::class, 'update'])->name('update');
        Route::put('/player', [ProfileController::class, 'updatePlayer'])->name('player.update')->middleware('permission:player:update');
    });

    Route::middleware(['role:master|admin'])->prefix('/users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::post('/', [UserController::class, 'store'])->name('store')->middleware('access:user_create');
        Route::get('/{id}', [UserController::class, 'show'])->name('show')->middleware('access:user');
        Route::put('/{id}', [UserController::class, 'update'])->name('update')->middleware('access:user_write');
    });

    Route::middleware(['role:master'])->prefix('/users')->name('users.')->group(function () {
        Route::delete('/{id}', [UserController::class, 'destroy'])->name('destroy');
        Route::post('/{id}/leagues', [UserController::class, 'syncLeagues'])->name('leagues.sync');
        Route::delete('/{id}/leagues/{lid}', [UserController::class, 'removeLeague'])->name('leagues.remove');
    });

    Route::prefix('/leagues')->name('leagues.')->group(function () {
        Route::get('/', [LeagueController::class, 'index'])->name('index');
        Route::get('/{id}', [LeagueController::class, 'show'])->name('show')->middleware('access:league');
        Route::get('/{id}/table', [LeagueController::class, 'table'])->name('table')->middleware('access:league');
        Route::get('/{id}/statistics', [LeagueController::class, 'statistics'])->name('statistics')->middleware('access:league');
        Route::get('/{id}/dashboard', [LeagueController::class, 'dashboard'])->name('dashboard')->middleware('access:league');
        Route::get('/{id}/groups', [LeagueController::class, 'groups'])->name('groups')->middleware('access:league');
        Route::put('/{id}/groups', [LeagueController::class, 'updateGroups'])->name('groups.update')->middleware(['permission:tournament:update', 'access:league']);
        Route::post('/{id}/groups/shuffle', [LeagueController::class, 'shuffleGroups'])->name('groups.shuffle')->middleware(['permission:tournament:update', 'access:league']);
        Route::get('/{id}/draw', [LeagueController::class, 'draw'])->name('draw')->middleware('access:league');
        Route::post('/{id}/draw/next-round', [LeagueController::class, 'drawNextRound'])->name('draw.next-round')->middleware(['permission:tournament:update', 'access:league']);
        Route::delete('/{id}/draw/last-round', [LeagueController::class, 'undoLastRound'])->name('draw.last-round')->middleware(['permission:tournament:update', 'access:league']);
        Route::delete('/{id}/draw', [LeagueController::class, 'resetDraw'])->name('draw.reset')->middleware(['permission:tournament:update', 'access:league']);
        Route::get('/{id}/knockout', [LeagueController::class, 'knockout'])->name('knockout')->middleware('access:league');
        Route::get('/{id}/knockout/preview', [LeagueController::class, 'knockoutPreview'])->name('knockout.preview')->middleware('access:league');
        Route::post('/{id}/knockout/next-stage', [LeagueController::class, 'knockoutNextStage'])->name('knockout.next-stage')->middleware(['permission:tournament:update', 'access:league']);
        Route::delete('/{id}/knockout/last-stage', [LeagueController::class, 'undoKnockoutStage'])->name('knockout.last-stage')->middleware(['permission:tournament:update', 'access:league']);
        Route::post('/', [LeagueController::class, 'store'])->name('store')->middleware('role:master');
        Route::put('/{id}', [LeagueController::class, 'update'])->name('update')->middleware(['permission:tournament:update', 'access:league']);
        Route::delete('/{id}', [LeagueController::class, 'destroy'])->name('destroy')->middleware(['role:master', 'throttle:5,1']);
    });

    Route::prefix('/championships')->name('championships.')->group(function () {
        Route::get('/', [ChampionshipController::class, 'index'])->name('index');
        Route::get('/{id}', [ChampionshipController::class, 'show'])->name('show');
        Route::post('/', [ChampionshipController::class, 'store'])->name('store')->middleware('role:master');
        Route::put('/{id}', [ChampionshipController::class, 'update'])->name('update')->middleware('role:master');
        Route::delete('/{id}', [ChampionshipController::class, 'destroy'])->name('destroy')->middleware(['role:master', 'throttle:5,1']);
    });

    Route::prefix('/teams')->name('teams.')->group(function () {
        Route::get('/', [TeamController::class, 'index'])->name('index');
        Route::get('/{id}', [TeamController::class, 'show'])->name('show')->middleware('access:team');
        Route::post('/', [TeamController::class, 'store'])->name('store')->middleware('permission:team:create');
        Route::put('/{id}', [TeamController::class, 'update'])->name('update')->middleware(['permission:team:update', 'access:team']);
        Route::delete('/{id}', [TeamController::class, 'destroy'])->name('destroy')->middleware(['permission:team:delete', 'access:team']);
        Route::post('/{id}/leagues', [TeamController::class, 'syncLeagues'])->name('leagues.sync')->middleware('permission:team:update');
        Route::delete('/{id}/leagues/{lid}', [TeamController::class, 'removeLeague'])->name('leagues.remove')->middleware('permission:team:update');
    });

    Route::prefix('/players')->name('players.')->group(function () {
        Route::get('/', [PlayerController::class, 'index'])->name('index');
        Route::get('/positions', [PlayerController::class, 'positions'])->name('positions');
        Route::get('/{id}', [PlayerController::class, 'show'])->name('show')->middleware('access:player');
        Route::post('/', [PlayerController::class, 'store'])->name('store')->middleware('permission:player:create');
        Route::put('/{id}', [PlayerController::class, 'update'])->name('update')->middleware(['permission:player:update', 'access:player_write']);
        Route::delete('/{id}', [PlayerController::class, 'destroy'])->name('destroy')->middleware(['permission:player:delete', 'access:player_write']);
    });

    Route::prefix('/stadiums')->name('stadiums.')->group(function () {
        Route::get('/', [StadiumController::class, 'index'])->name('index');
        Route::get('/{id}', [StadiumController::class, 'show'])->name('show')->middleware('access:stadium');
        Route::post('/', [StadiumController::class, 'store'])->name('store')->middleware('permission:stadium:create');
        Route::put('/{id}', [StadiumController::class, 'update'])->name('update')->middleware(['permission:stadium:update', 'access:stadium']);
        Route::delete('/{id}', [StadiumController::class, 'destroy'])->name('destroy')->middleware(['permission:stadium:delete', 'access:stadium']);
    });

    Route::prefix('/technical-committee-members')->name('technical-committee-members.')->group(function () {
        Route::get('/', [TechnicalCommitteeMemberController::class, 'index'])->name('index');
        Route::get('/roles', [TechnicalCommitteeMemberController::class, 'roles'])->name('roles');
        Route::get('/{id}', [TechnicalCommitteeMemberController::class, 'show'])->name('show')->middleware('access:technical_committee_member');
        Route::post('/', [TechnicalCommitteeMemberController::class, 'store'])->name('store')->middleware('permission:technical_committee_member:create');
        Route::put('/{id}', [TechnicalCommitteeMemberController::class, 'update'])->name('update')->middleware(['permission:technical_committee_member:update', 'access:technical_committee_member']);
        Route::delete('/{id}', [TechnicalCommitteeMemberController::class, 'destroy'])->name('destroy')->middleware(['permission:technical_committee_member:delete', 'access:technical_committee_member']);
    });

    Route::prefix('/games')->name('games.')->group(function () {
        Route::get('/', [GameController::class, 'index'])->name('index');
        Route::get('/{id}', [GameController::class, 'show'])->name('show')->middleware('access:game');
        Route::post('/', [GameController::class, 'store'])->name('store')->middleware('permission:game:create');
        Route::put('/{id}', [GameController::class, 'update'])->name('update')->middleware(['permission:game:update', 'access:game']);
        Route::delete('/{id}', [GameController::class, 'destroy'])->name('destroy')->middleware(['permission:game:delete', 'access:game']);
    });

    Route::prefix('/game-events')->name('game-events.')->group(function () {
        Route::get('/', [GameEventController::class, 'index'])->name('index');
        Route::get('/{id}', [GameEventController::class, 'show'])->name('show')->middleware('access:game_event');
        Route::post('/', [GameEventController::class, 'store'])->name('store')->middleware('permission:game_event:create');
        Route::put('/{id}', [GameEventController::class, 'update'])->name('update')->middleware(['permission:game_event:update', 'access:game_event']);
        Route::delete('/{id}', [GameEventController::class, 'destroy'])->name('destroy')->middleware(['permission:game_event:delete', 'access:game_event']);
    });

    Route::prefix('/event-types')->name('event-types.')->group(function () {
        Route::get('/', [GameEventTypeController::class, 'index'])->name('index');
        Route::post('/', [GameEventTypeController::class, 'store'])->name('store')->middleware('role:master');
        Route::put('/{id}', [GameEventTypeController::class, 'update'])->name('update')->middleware('role:master');
        Route::delete('/{id}', [GameEventTypeController::class, 'destroy'])->name('destroy')->middleware('role:master');
    });
});
