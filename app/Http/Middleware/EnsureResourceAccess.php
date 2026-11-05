<?php

namespace App\Http\Middleware;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Player;
use App\Models\Stadium;
use App\Models\Team;
use App\Models\TechnicalCommitteeMember;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureResourceAccess
{
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $user = $request->user();

        if ($user->isMaster()) {
            return $next($request);
        }

        $id = $request->route('id');
        $leagueIds = $user->leagueIds();

        $hasAccess = match ($type) {
            'league' => in_array((int) $id, $leagueIds),
            'team' => Team::whereKey($id)
                ->whereHas('leagues', fn ($q) => $q->whereIn('leagues.id', $leagueIds))
                ->exists(),
            'player' => Player::whereKey($id)
                ->whereHas('team.leagues', fn ($q) => $q->whereIn('leagues.id', $leagueIds))
                ->exists(),
            'technical_committee_member' => TechnicalCommitteeMember::whereKey($id)
                ->whereHas('team.leagues', fn ($q) => $q->whereIn('leagues.id', $leagueIds))
                ->exists(),
            'stadium' => Stadium::whereKey($id)
                ->whereIn('league_id', $leagueIds)
                ->exists(),
            'game' => Game::whereKey($id)
                ->whereIn('league_id', $leagueIds)
                ->exists(),
            'game_event' => GameEvent::whereKey($id)
                ->whereIn('league_id', $leagueIds)
                ->exists(),
            'player_write' => match (true) {
                $user->hasRole('admin') => Player::whereKey($id)
                    ->whereHas('team.leagues', fn ($q) => $q->whereIn('leagues.id', $leagueIds))
                    ->exists(),
                $user->isCoach() => $user->team_id !== null
                    && Player::whereKey($id)->where('team_id', $user->team_id)->exists(),
                default => false,
            },
            'user' => (int) $id === $user->id || User::whereKey($id)
                ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'master'))
                ->inLeagues($leagueIds)
                ->exists(),
            'user_write' => (int) $id === $user->id || User::whereKey($id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', User::TEAM_SCOPED_ROLES))
                ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', User::LEAGUE_SCOPED_ROLES))
                ->inLeagues($leagueIds)
                ->exists(),
            'user_create' => ! in_array($request->input('role'), User::LEAGUE_SCOPED_ROLES, true),
            default => false,
        };

        if (!$hasAccess) {
            return response()->json(['message' => 'Você não tem acesso a este recurso.'], 403);
        }

        return $next($request);
    }
}
