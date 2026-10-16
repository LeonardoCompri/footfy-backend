<?php

namespace App\Services;

use App\Models\Game;
use App\Models\League;
use App\Models\Team;
use App\Repositories\StandingsRepository;
use Illuminate\Support\Collection;

class StandingsService
{
    public function __construct(private readonly StandingsRepository $repository) {}

    /**
     * Monta a Tabela da Liga conforme `league->format`: `standings` (round_robin/hybrid),
     * `phases` (knockout/hybrid) ou os dois juntos (hybrid). Reaproveitado tanto pela rota
     * admin quanto pela pública — as duas chamam este mesmo método, diferindo apenas na
     * autorização aplicada antes de chegar aqui.
     */
    public function build(League $league): array
    {
        $result = [];

        if (in_array($league->format, ['round_robin', 'hybrid'], true)) {
            $result['standings'] = $this->standings((int) $league->id);
        }

        if (in_array($league->format, ['knockout', 'hybrid'], true)) {
            $result['phases'] = $this->phases((int) $league->id);
        }

        return $result;
    }

    // Classificação da fase de pontos corridos (jogos com `round` preenchido). Em liga
    // híbrida, jogos com `phase` preenchida (mata-mata) nunca entram aqui.
    private function standings(int $leagueId): array
    {
        $teams = $this->repository->leagueTeams($leagueId);
        $aggregates = $this->repository->groupStageAggregates($leagueId)->keyBy('team_id');

        $rows = $teams->map(function ($team) use ($aggregates) {
            $stats = $aggregates->get($team->id);

            $played = (int) ($stats->played ?? 0);
            $wins = (int) ($stats->wins ?? 0);
            $draws = (int) ($stats->draws ?? 0);
            $losses = (int) ($stats->losses ?? 0);
            $goalsFor = (int) ($stats->goals_for ?? 0);
            $goalsAgainst = (int) ($stats->goals_against ?? 0);

            return [
                'team_id' => $team->id,
                'team' => ['id' => $team->id, 'name' => $team->name, 'nickname' => $team->nickname, 'logo' => $team->logoUrl()],
                'played' => $played,
                'wins' => $wins,
                'draws' => $draws,
                'losses' => $losses,
                'goals_for' => $goalsFor,
                'goals_against' => $goalsAgainst,
                'goal_difference' => $goalsFor - $goalsAgainst,
                'points' => (int) ($stats->points ?? 0),
            ];
        })->all();

        return $this->sortStandings($rows, $leagueId);
    }

    /**
     * Critério de desempate estilo CBF, nesta ordem: pontos, vitórias, saldo de gols,
     * gols pró, confronto direto (só quando restam exatamente 2 times empatados nos
     * critérios anteriores), cartões (menor nº) e, por fim, sorteio — aqui representado
     * de forma determinística pelo id do time, para a resposta ser estável entre chamadas.
     */
    private function sortStandings(array $rows, int $leagueId): array
    {
        usort($rows, fn ($a, $b) => $this->comparePrimaryCriteria($b, $a));

        $cardCounts = $this->repository->groupStageCardCounts($leagueId);

        $sorted = [];
        $count = count($rows);
        $i = 0;

        while ($i < $count) {
            $j = $i;
            while ($j + 1 < $count && $this->comparePrimaryCriteria($rows[$i], $rows[$j + 1]) === 0) {
                $j++;
            }

            $bucket = array_slice($rows, $i, $j - $i + 1);

            if (count($bucket) === 2) {
                $bucket = $this->breakTieByHeadToHead($bucket, $leagueId);
            }

            if (count($bucket) > 1) {
                $bucket = $this->breakTieByCardsAndId($bucket, $cardCounts);
            }

            array_push($sorted, ...$bucket);
            $i = $j + 1;
        }

        return $sorted;
    }

    private function comparePrimaryCriteria(array $a, array $b): int
    {
        return [$a['points'], $a['wins'], $a['goal_difference'], $a['goals_for']]
            <=> [$b['points'], $b['wins'], $b['goal_difference'], $b['goals_for']];
    }

    // Confronto direto: só decide entre exatamente 2 times, pelos pontos que fizeram um
    // contra o outro (regulamento CBF). Se não houver jogo direto ou o saldo entre eles
    // também empatar, devolve o bucket sem alterar a ordem (cartões decide em seguida).
    private function breakTieByHeadToHead(array $bucket, int $leagueId): array
    {
        [$teamA, $teamB] = $bucket;
        $games = $this->repository->headToHeadGames($leagueId, $teamA['team_id'], $teamB['team_id']);

        if ($games->isEmpty()) {
            return $bucket;
        }

        $points = [$teamA['team_id'] => 0, $teamB['team_id'] => 0];

        /** @var Game $game */
        foreach ($games as $game) {
            if ($game->home_score === $game->away_score) {
                $points[$game->home_team_id] += 1;
                $points[$game->away_team_id] += 1;

                continue;
            }

            $winnerId = $game->home_score > $game->away_score ? $game->home_team_id : $game->away_team_id;
            $points[$winnerId] += 3;
        }

        if ($points[$teamA['team_id']] === $points[$teamB['team_id']]) {
            return $bucket;
        }

        return $points[$teamA['team_id']] > $points[$teamB['team_id']] ? [$teamA, $teamB] : [$teamB, $teamA];
    }

    private function breakTieByCardsAndId(array $bucket, array $cardCounts): array
    {
        usort($bucket, function ($a, $b) use ($cardCounts) {
            $cardsA = $cardCounts[$a['team_id']] ?? 0;
            $cardsB = $cardCounts[$b['team_id']] ?? 0;

            if ($cardsA !== $cardsB) {
                return $cardsA <=> $cardsB; // menos cartões = melhor posição
            }

            return $a['team_id'] <=> $b['team_id']; // sorteio: ordem determinística
        });

        return $bucket;
    }

    // Fases do mata-mata (jogos com `phase` preenchida), ordenadas por `phase_order`, cada
    // uma com seus confrontos agrupados por `tie_key`.
    private function phases(int $leagueId): array
    {
        $games = $this->repository->knockoutGames($leagueId);

        $phases = $games
            ->groupBy(fn (Game $game) => ($game->phase_order ?? 'null').'|'.$game->phase)
            ->map(function (Collection $phaseGames) {
                $first = $phaseGames->first();

                return [
                    'phase' => $first->phase,
                    'phase_order' => $first->phase_order,
                    'ties' => $phaseGames
                        ->groupBy(fn (Game $game) => $game->tie_key ?? ('game-'.$game->id))
                        ->map(fn (Collection $tieGames) => $this->buildTie($tieGames))
                        ->values()
                        ->all(),
                ];
            })
            ->values();

        return $phases
            ->sortBy(fn (array $phase) => $phase['phase_order'] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }

    // Um confronto (ida/volta ou jogo único): placar agregado por time somando as pernas já
    // finalizadas, e vencedor calculado a partir do agregado. Ida e volta (alguma perna com
    // `leg`) só fica `decided = true` quando a Ida (leg=1) e a Volta (leg=2) existem e ambas
    // foram finalizadas — com só uma perna cadastrada o confronto segue em aberto, exibindo o
    // agregado parcial. Isso também cobre jogos legados com `leg` e sem `tie_key` (grupo
    // `game-{id}` isolado, que nunca terá as duas pernas). Jogo único (nenhuma perna com `leg`)
    // é decidido assim que finalizado.
    private function buildTie(Collection $tieGames): array
    {
        $legs = $tieGames->sortBy('leg')->values();

        $teamIds = $legs
            ->flatMap(fn (Game $game) => [$game->home_team_id, $game->away_team_id])
            ->unique()
            ->values();

        $isTwoLegged = $legs->contains(fn (Game $game) => $game->leg !== null);
        $decided = $isTwoLegged
            ? collect([1, 2])->every(fn (int $leg) => $legs->contains(
                fn (Game $game) => (int) $game->leg === $leg && $this->isLegFinished($game)
            ))
            : $legs->every(fn (Game $game) => $this->isLegFinished($game));

        $aggregate = $teamIds->mapWithKeys(fn ($teamId) => [$teamId => 0])->all();
        $teamSummaries = [];

        foreach ($legs as $game) {
            $teamSummaries[$game->home_team_id] ??= $this->teamSummary($game->homeTeam, $game->home_team_id);
            $teamSummaries[$game->away_team_id] ??= $this->teamSummary($game->awayTeam, $game->away_team_id);

            if (! $this->isLegFinished($game)) {
                continue;
            }

            $aggregate[$game->home_team_id] += $game->home_score;
            $aggregate[$game->away_team_id] += $game->away_score;
        }

        $winnerId = null;
        if ($decided && $teamIds->count() === 2) {
            [$teamAId, $teamBId] = $teamIds->all();
            // Agregado empatado: o domínio atual não modela critério de desempate de
            // mata-mata (gol fora de casa, pênaltis etc.), então o confronto fica decidido
            // mas sem vencedor definido — limitação documentada no PR desta task.
            if ($aggregate[$teamAId] !== $aggregate[$teamBId]) {
                $winnerId = $aggregate[$teamAId] > $aggregate[$teamBId] ? $teamAId : $teamBId;
            }
        }

        return [
            'tie_key' => $legs->first()->tie_key,
            'decided' => $decided,
            'winner_team_id' => $winnerId,
            'teams' => $teamIds->map(fn ($teamId) => array_merge(
                $teamSummaries[$teamId],
                ['aggregate_score' => $aggregate[$teamId]]
            ))->values()->all(),
            'legs' => $legs->map(fn (Game $game) => [
                'id' => $game->id,
                'leg' => $game->leg,
                'home_team_id' => $game->home_team_id,
                'away_team_id' => $game->away_team_id,
                'home_score' => $game->home_score,
                'away_score' => $game->away_score,
                'status' => $game->status,
                'match_date' => $game->match_date,
            ])->values()->all(),
        ];
    }

    private function isLegFinished(Game $game): bool
    {
        return $game->status === 'finished' && $game->home_score !== null && $game->away_score !== null;
    }

    private function teamSummary(?Team $team, int $teamId): array
    {
        return $team
            ? ['team_id' => $team->id, 'name' => $team->name, 'nickname' => $team->nickname]
            : ['team_id' => $teamId, 'name' => null, 'nickname' => null];
    }
}
