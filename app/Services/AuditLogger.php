<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Championship;
use App\Models\League;
use App\Repositories\AuditLogRepository;
use App\Repositories\LeagueRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

class AuditLogger
{
    public function __construct(
        private readonly AuditLogRepository $repository,
        private readonly LeagueRepository $leagueRepository,
    ) {}

    public function record(
        string $action,
        Model $subject,
        array $old = [],
        array $new = [],
        array $meta = [],
        ?League $league = null,
    ): AuditLog {
        $request = request();

        return $this->repository->store([
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $subject->getMorphClass(),
            'auditable_id' => $subject->getKey(),
            ...$this->leagueContext($subject, $league),
            'old_values' => $this->sanitize($old),
            'new_values' => $this->sanitize($new),
            'meta' => $this->sanitize($meta),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => Context::get('request_id'),
        ]);
    }

    private function leagueContext(Model $subject, ?League $league): array
    {
        $league ??= $subject instanceof League ? $subject : null;

        if ($league !== null) {
            return ['championship_id' => $league->championship_id, 'league_id' => $league->getKey()];
        }

        if ($subject instanceof Championship) {
            return ['championship_id' => $subject->getKey(), 'league_id' => null];
        }

        $leagueId = $subject->getAttribute('league_id');

        return [
            'championship_id' => $leagueId !== null ? $this->leagueRepository->championshipIdOf($leagueId) : null,
            'league_id' => $leagueId,
        ];
    }

    private function sanitize(array $values): ?array
    {
        if ($values === []) {
            return null;
        }

        return mask_sensitive_data(json_decode(json_encode($values, JSON_THROW_ON_ERROR), true));
    }
}
