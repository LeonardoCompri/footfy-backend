<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class League extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'organizer',
        'season',
        'championship_id',
        'format',
        'fixture_mode',
        'groups_count',
        'group_legs',
        'qualifiers_per_group',
        'knockout_size',
        'knockout_legs',
        'final_legs',
        'third_place',
        'tiebreakers',
        'knockout_tiebreakers',
    ];

    public const FORMATS = ['round_robin', 'knockout', 'hybrid'];

    public const FIXTURE_MODES = ['manual', 'draw'];

    public const KNOCKOUT_SIZES = [2, 4, 8, 16];

    public const MAX_GROUPS = 16;

    public const TIEBREAKERS = [
        'points',
        'wins',
        'goal_difference',
        'goals_for',
        'goals_against',
        'head_to_head',
        'fewer_red_cards',
        'fewer_yellow_cards',
        'fewer_cards',
    ];

    public const DEFAULT_TIEBREAKERS = [
        'points',
        'wins',
        'goal_difference',
        'goals_for',
        'head_to_head',
        'fewer_cards',
    ];

    public const KNOCKOUT_TIEBREAKER_SEQUENCES = [
        ['penalties'],
        ['better_campaign'],
        ['away_goals', 'penalties'],
        ['away_goals', 'better_campaign'],
    ];

    public const STRUCTURE_FIELDS = ['format', 'fixture_mode', 'groups_count', 'group_legs'];

    public const KNOCKOUT_FIELDS = [
        'qualifiers_per_group',
        'knockout_size',
        'knockout_legs',
        'final_legs',
        'third_place',
        'knockout_tiebreakers',
    ];

    public const CRITERIA_FIELDS = ['tiebreakers', 'knockout_tiebreakers'];

    protected $attributes = [
        'format' => 'round_robin',
        'fixture_mode' => 'manual',
        'groups_count' => 1,
        'group_legs' => 1,
        'third_place' => false,
    ];

    protected function casts(): array
    {
        return [
            'groups_count' => 'integer',
            'group_legs' => 'integer',
            'qualifiers_per_group' => 'integer',
            'knockout_size' => 'integer',
            'knockout_legs' => 'integer',
            'final_legs' => 'integer',
            'third_place' => 'boolean',
            'tiebreakers' => 'array',
            'knockout_tiebreakers' => 'array',
        ];
    }

    public static function knockoutTiebreakerValues(): array
    {
        return array_values(array_unique(array_merge(...self::KNOCKOUT_TIEBREAKER_SEQUENCES)));
    }

    public function effectiveTiebreakers(): array
    {
        return $this->tiebreakers ?? self::DEFAULT_TIEBREAKERS;
    }

    public function resolvedGroupNumber(mixed $pivot): ?int
    {
        $groupsCount = max(1, (int) $this->groups_count);

        if ($groupsCount === 1) {
            return 1;
        }

        if ($pivot === null || ! is_numeric($pivot)) {
            return null;
        }

        $groupNumber = (int) $pivot;

        return $groupNumber >= 1 && $groupNumber <= $groupsCount ? $groupNumber : null;
    }

    public function usesDraw(): bool
    {
        return $this->fixture_mode === 'draw';
    }

    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'league_team')->withPivot('group_number');
    }

    public function games()
    {
        return $this->hasMany(Game::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_leagues');
    }

    public function stadiums(): HasMany
    {
        return $this->hasMany(Stadium::class);
    }
}
