<?php

namespace App\Http\Middleware;

use App\Models\Coach;
use App\Models\Game;
use App\Models\Player;
use App\Models\Team;
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
        $championshipIds = $user->championshipIds();

        $hasAccess = match ($type) {
            'championship' => in_array((int) $id, $championshipIds),
            'team'         => Team::whereKey($id)
                ->whereHas('championships', fn ($q) => $q->whereIn('championships.id', $championshipIds))
                ->exists(),
            'player'       => Player::whereKey($id)
                ->whereHas('team.championships', fn ($q) => $q->whereIn('championships.id', $championshipIds))
                ->exists(),
            'coach'        => Coach::whereKey($id)
                ->whereHas('team.championships', fn ($q) => $q->whereIn('championships.id', $championshipIds))
                ->exists(),
            'game'         => Game::whereKey($id)
                ->whereIn('championship_id', $championshipIds)
                ->exists(),
            default        => false,
        };

        if (!$hasAccess) {
            return response()->json(['message' => 'Você não tem acesso a este recurso.'], 403);
        }

        return $next($request);
    }
}
