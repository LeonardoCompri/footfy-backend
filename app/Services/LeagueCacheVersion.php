<?php

namespace App\Services;

use App\Repositories\LeagueRepository;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LeagueCacheVersion
{
    public const GLOBAL_COUNTER = 'league_cache:global';

    public const CHAMPIONSHIPS_COUNTER = 'league_cache:championships';

    public function __construct(private readonly LeagueRepository $leagueRepository) {}

    public function key(int $leagueId, string $name): string
    {
        return sprintf('league:%d:v%d:g%d:%s', $leagueId, $this->version($this->leagueCounter($leagueId)), $this->globalVersion(), $name);
    }

    public function championshipKey(int $championshipId, string $name): string
    {
        return sprintf('championship:%d:v%d:g%d:%s', $championshipId, $this->version($this->championshipCounter($championshipId)), $this->globalVersion(), $name);
    }

    public function championshipsKey(string $name): string
    {
        return sprintf('championships:v%d:g%d:%s', $this->version(self::CHAMPIONSHIPS_COUNTER), $this->globalVersion(), $name);
    }

    public function remember(string $key, Closure $callback): mixed
    {
        return Cache::remember($key, (int) config('footfy.league_cache_ttl'), $callback);
    }

    public function bump(int ...$leagueIds): void
    {
        $counters = array_map(fn (int $leagueId) => $this->leagueCounter($leagueId), array_unique($leagueIds));

        $this->incrementAfterCommit($counters);
    }

    public function bumpForTeam(int $teamId): void
    {
        $this->bump(...$this->leagueRepository->idsRelatedToTeam($teamId));
    }

    public function bumpForPlayer(int $playerId): void
    {
        $this->bump(...$this->leagueRepository->idsWithPlayerEvents($playerId));
    }

    public function bumpChampionship(int ...$championshipIds): void
    {
        $counters = array_map(fn (int $championshipId) => $this->championshipCounter($championshipId), array_unique($championshipIds));

        $this->incrementAfterCommit([...$counters, self::CHAMPIONSHIPS_COUNTER]);
    }

    public function bumpAll(): void
    {
        DB::afterCommit(function () {
            $this->globalVersion();
            $this->increment(self::GLOBAL_COUNTER);
        });
    }

    public function globalVersion(): int
    {
        $version = Cache::get(self::GLOBAL_COUNTER);

        if ($version !== null) {
            return (int) $version;
        }

        $epoch = $this->epoch();
        Cache::add(self::GLOBAL_COUNTER, $epoch);

        return (int) (Cache::get(self::GLOBAL_COUNTER) ?? $epoch);
    }

    public function leagueCounter(int $leagueId): string
    {
        return 'league_cache:league:'.$leagueId;
    }

    public function championshipCounter(int $championshipId): string
    {
        return 'league_cache:championship:'.$championshipId;
    }

    private function version(string $counter): int
    {
        return (int) Cache::get($counter, 0);
    }

    private function incrementAfterCommit(array $counters): void
    {
        if ($counters === []) {
            return;
        }

        DB::afterCommit(function () use ($counters) {
            foreach ($counters as $counter) {
                $this->increment($counter);
            }
        });
    }

    private function increment(string $counter): void
    {
        if (Cache::increment($counter) === false) {
            Cache::forever($counter, $this->epoch());
        }
    }

    private function epoch(): int
    {
        return now()->getTimestampMs();
    }
}
