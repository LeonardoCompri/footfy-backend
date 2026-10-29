<?php

namespace App\Services\Concerns;

use App\Models\Team;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Confere, na escrita, se a liga/time de destino informado no payload está entre as ligas
 * do usuário logado. Master é sempre liberado. Lança ValidationException (422 no Controller).
 */
trait EnsuresLeagueAccess
{
    protected function ensureUserHasAccessToLeagues(array $leagueIds): void
    {
        $user = Auth::user();

        if ($user->isMaster()) {
            return;
        }

        $allowedLeagueIds = $user->leagueIds();
        $unauthorizedLeagueIds = array_diff(array_map('intval', $leagueIds), $allowedLeagueIds);

        if (! empty($unauthorizedLeagueIds)) {
            throw ValidationException::withMessages(['message' => 'Você não tem acesso a uma ou mais ligas informadas.']);
        }
    }

    // O time precisa estar vinculado (pivot league_team) a pelo menos uma liga do usuário.
    // Sem time (null) só master passa.
    protected function ensureUserHasAccessToTeam(int|string|null $teamId): void
    {
        $user = Auth::user();

        if ($user->isMaster()) {
            return;
        }

        $leagueIds = $user->leagueIds();
        $hasAccess = $teamId !== null && Team::whereKey($teamId)
            ->whereHas('leagues', fn ($q) => $q->whereIn('leagues.id', $leagueIds))
            ->exists();

        if (! $hasAccess) {
            throw ValidationException::withMessages(['message' => 'Você não tem acesso a uma ou mais ligas informadas.']);
        }
    }
}
