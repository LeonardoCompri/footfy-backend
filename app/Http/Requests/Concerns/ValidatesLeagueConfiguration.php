<?php

namespace App\Http\Requests\Concerns;

use App\Models\League;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

trait ValidatesLeagueConfiguration
{
    private const CONFIGURATION_DEFAULTS = [
        'format' => 'round_robin',
        'fixture_mode' => 'manual',
        'groups_count' => 1,
        'group_legs' => 1,
        'qualifiers_per_group' => null,
        'knockout_size' => null,
        'knockout_legs' => null,
        'final_legs' => null,
        'third_place' => false,
        'tiebreakers' => null,
        'knockout_tiebreakers' => null,
    ];

    private const NON_NULLABLE_FIELDS = ['format', 'fixture_mode', 'groups_count', 'group_legs', 'third_place'];

    private const TEAM_COUNT_FIELDS = ['format', 'fixture_mode', 'groups_count', 'qualifiers_per_group'];

    private const FORMAT_ADJUSTMENTS = [
        'round_robin' => [
            'groups_count' => 1,
            'qualifiers_per_group' => null,
            'knockout_size' => null,
            'knockout_legs' => null,
            'final_legs' => null,
            'third_place' => false,
            'knockout_tiebreakers' => null,
        ],
        'knockout' => [
            'fixture_mode' => 'manual',
            'groups_count' => 1,
            'qualifiers_per_group' => null,
        ],
    ];

    protected function leagueConfigurationRules(string $prefix = ''): array
    {
        return [
            "{$prefix}fixture_mode" => ['sometimes', 'string', Rule::in(League::FIXTURE_MODES)],
            "{$prefix}groups_count" => ['sometimes', 'integer', 'min:1', 'max:'.League::MAX_GROUPS],
            "{$prefix}group_legs" => ['sometimes', 'integer', 'in:1,2'],
            "{$prefix}qualifiers_per_group" => ['nullable', 'integer', 'min:1'],
            "{$prefix}knockout_size" => ['nullable', 'integer', Rule::in(League::KNOCKOUT_SIZES)],
            "{$prefix}knockout_legs" => ['nullable', 'integer', 'in:1,2'],
            "{$prefix}final_legs" => ['nullable', 'integer', 'in:1,2'],
            "{$prefix}third_place" => ['sometimes', 'boolean'],
            "{$prefix}tiebreakers" => ['nullable', 'list'],
            "{$prefix}tiebreakers.*" => ['string', Rule::in(League::TIEBREAKERS)],
            "{$prefix}knockout_tiebreakers" => ['nullable', 'list'],
            "{$prefix}knockout_tiebreakers.*" => ['string', Rule::in(League::knockoutTiebreakerValues())],
        ];
    }

    protected function adjustConfigurationToFormat(array $input, ?League $league): array
    {
        if (! $league) {
            return $input;
        }

        $format = $input['format'] ?? null;
        $adjustments = [];

        if (is_string($format) && $format !== $league->format && array_key_exists($format, self::FORMAT_ADJUSTMENTS)) {
            $adjustments = self::FORMAT_ADJUSTMENTS[$format];
        }

        if (($input['fixture_mode'] ?? null) === 'manual' && $league->fixture_mode !== 'manual') {
            $knockoutSize = array_key_exists('knockout_size', $input) ? $input['knockout_size'] : $league->knockout_size;
            $adjustments += [
                'groups_count' => 1,
                'qualifiers_per_group' => is_numeric($knockoutSize) ? (int) $knockoutSize : null,
            ];
        }

        if (empty($adjustments) || $this->leagueHasDrawnRounds($league)) {
            return $input;
        }

        return $input + $adjustments;
    }

    protected function validateLeagueConfiguration(Validator $validator, array $input, ?League $league, string $prefix = ''): void
    {
        foreach (array_keys(self::CONFIGURATION_DEFAULTS) as $field) {
            if ($validator->errors()->has("{$prefix}{$field}") || $validator->errors()->has("{$prefix}{$field}.*")) {
                return;
            }
        }

        $config = $this->effectiveLeagueConfiguration($input, $league);
        $errors = [];

        match ($config['format']) {
            'knockout' => $this->validateKnockoutFormat($config, $errors),
            'round_robin' => $this->validateRoundRobinFormat($config, $errors),
            'hybrid' => $this->validateHybridFormat($config, $errors),
            default => null,
        };

        if ($config['fixture_mode'] === 'manual' && $config['groups_count'] !== 1 && ! $this->leagueHasDrawnRounds($league)) {
            $errors['groups_count'] ??= 'Ligas com tabela manual têm um único grupo.';
        }

        $this->validateTiebreakers($config, $errors);
        $this->validateKnockoutTiebreakers($config, $errors);

        if (empty($errors) && $league && $this->teamCountFieldsChanged($config, $league)) {
            $this->validateTeamCount($config, $league, $errors);
        }

        foreach ($errors as $field => $message) {
            $key = "{$prefix}{$field}";
            $validator->errors()->add($key, $message ?? __('validation.required', [
                'attribute' => $validator->getDisplayableAttribute($key),
            ]));
        }
    }

    private function leagueHasDrawnRounds(?League $league): bool
    {
        return $league !== null && $league->games()->whereNotNull('group_number')->exists();
    }

    private function effectiveLeagueConfiguration(array $input, ?League $league): array
    {
        $config = [];

        foreach (self::CONFIGURATION_DEFAULTS as $field => $default) {
            $stored = $league ? $league->getAttribute($field) : $default;
            $sent = array_key_exists($field, $input)
                && ($input[$field] !== null || ! in_array($field, self::NON_NULLABLE_FIELDS, true));

            $config[$field] = $sent ? $input[$field] : $stored;
        }

        foreach (['groups_count', 'group_legs', 'qualifiers_per_group', 'knockout_size', 'knockout_legs', 'final_legs'] as $field) {
            $config[$field] = $config[$field] === null ? null : (int) $config[$field];
        }

        $config['third_place'] = filter_var($config['third_place'], FILTER_VALIDATE_BOOLEAN);

        return $config;
    }

    private function validateKnockoutFormat(array $config, array &$errors): void
    {
        if ($config['fixture_mode'] !== 'manual') {
            $errors['fixture_mode'] = 'Ligas mata-mata só aceitam tabela manual.';
        }

        if ($config['groups_count'] !== 1) {
            $errors['groups_count'] = 'Ligas mata-mata não usam grupos.';
        }

        if ($config['qualifiers_per_group'] !== null) {
            $errors['qualifiers_per_group'] = 'Ligas mata-mata não usam classificados por grupo.';
        }
    }

    private function validateRoundRobinFormat(array $config, array &$errors): void
    {
        if ($config['groups_count'] !== 1) {
            $errors['groups_count'] = 'Ligas de pontos corridos têm um único grupo.';
        }

        foreach (League::KNOCKOUT_FIELDS as $field) {
            $filled = $field === 'third_place' ? $config[$field] : $config[$field] !== null;

            if ($filled) {
                $errors[$field] = 'Ligas de pontos corridos não têm mata-mata.';
            }
        }
    }

    private function validateHybridFormat(array $config, array &$errors): void
    {
        if ($config['fixture_mode'] === 'draw') {
            foreach (array_diff(League::KNOCKOUT_FIELDS, ['third_place']) as $field) {
                if ($config[$field] === null) {
                    $errors[$field] = null;
                }
            }
        }

        if (
            $config['qualifiers_per_group'] !== null
            && $config['knockout_size'] !== null
            && $config['knockout_size'] !== $config['groups_count'] * $config['qualifiers_per_group']
        ) {
            $errors['knockout_size'] ??= sprintf(
                'O tamanho do mata-mata deve ser igual a grupos × classificados por grupo (%d).',
                $config['groups_count'] * $config['qualifiers_per_group'],
            );
        }
    }

    private function validateTiebreakers(array $config, array &$errors): void
    {
        $tiebreakers = $config['tiebreakers'];

        if (! is_array($tiebreakers)) {
            return;
        }

        if (($tiebreakers[0] ?? null) !== 'points') {
            $errors['tiebreakers'] = 'O primeiro critério de desempate deve ser points.';
        } elseif (count($tiebreakers) !== count(array_unique($tiebreakers))) {
            $errors['tiebreakers'] = 'Os critérios de desempate não podem se repetir.';
        }
    }

    private function validateKnockoutTiebreakers(array $config, array &$errors): void
    {
        $sequence = $config['knockout_tiebreakers'];

        if (! is_array($sequence) || isset($errors['knockout_tiebreakers'])) {
            return;
        }

        if (! in_array($sequence, League::KNOCKOUT_TIEBREAKER_SEQUENCES, true)) {
            $errors['knockout_tiebreakers'] = 'A sequência de desempate do mata-mata é inválida.';

            return;
        }

        if ($sequence[0] === 'away_goals' && $config['knockout_legs'] !== 2 && $config['final_legs'] !== 2) {
            $errors['knockout_tiebreakers'] = 'O gol fora de casa só vale com confrontos de ida e volta.';
        }
    }

    private function teamCountFieldsChanged(array $config, League $league): bool
    {
        foreach (self::TEAM_COUNT_FIELDS as $field) {
            if ((string) $config[$field] !== (string) $league->getAttribute($field)) {
                return true;
            }
        }

        return false;
    }

    private function validateTeamCount(array $config, League $league, array &$errors): void
    {
        if ($config['fixture_mode'] !== 'draw') {
            return;
        }

        $teamsCount = $league->teams()->count();

        if ($teamsCount === 0) {
            return;
        }

        if ($teamsCount % $config['groups_count'] !== 0) {
            $errors['groups_count'] = sprintf(
                'A liga tem %d times, que não podem ser divididos igualmente em %d grupos.',
                $teamsCount,
                $config['groups_count'],
            );

            return;
        }

        $groupSize = intdiv($teamsCount, $config['groups_count']);

        if ($groupSize < 2) {
            $errors['groups_count'] = 'Cada grupo precisa ter pelo menos 2 times.';

            return;
        }

        if ($config['qualifiers_per_group'] !== null && $config['qualifiers_per_group'] >= $groupSize) {
            $errors['qualifiers_per_group'] = sprintf(
                'Os classificados por grupo devem ser menos que os %d times de cada grupo.',
                $groupSize,
            );
        }
    }
}
