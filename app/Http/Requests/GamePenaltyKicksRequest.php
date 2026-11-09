<?php

namespace App\Http\Requests;

use App\Models\Game;
use App\Models\Player;
use App\Services\Knockout\ShootoutResolver;
use App\Services\LeagueKnockoutService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class GamePenaltyKicksRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'kicks' => ['present', 'array', 'list', 'max:'.ShootoutResolver::MAX_KICKS],
            'kicks.*' => ['array'],
            'kicks.*.team_id' => ['required', 'integer'],
            'kicks.*.player_id' => ['required', 'integer'],
            'kicks.*.scored' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'kicks.max' => 'A disputa de pênaltis pode ter no máximo :max cobranças.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['kicks', 'kicks.*'])) {
                return;
            }

            $kicks = $this->input('kicks');
            $game = $this->route('id') ? Game::find($this->route('id')) : null;

            if (! $game) {
                return;
            }

            $knockoutService = app(LeagueKnockoutService::class);

            if ($knockoutService->knockoutResultsLocked($game)) {
                $validator->errors()->add('kicks', $knockoutService->knockoutResultsLockedMessage($game));

                return;
            }

            if ($kicks === []) {
                return;
            }

            $issue = $knockoutService->penaltiesIssue($game);

            if ($issue !== null) {
                $validator->errors()->add('kicks', $issue);

                return;
            }

            if (! $this->validateTakers($validator, $game, $kicks)) {
                return;
            }

            $this->validateSequence($validator, $game, $kicks);
        });
    }

    private function validateTakers(Validator $validator, Game $game, array $kicks): bool
    {
        $teamIds = [(int) $game->home_team_id, (int) $game->away_team_id];
        $players = Player::withTrashed()->whereIn('id', array_column($kicks, 'player_id'))->get()->keyBy('id');
        $recorded = $game->penaltyKicks()->get(['player_id', 'team_id'])
            ->map(fn ($kick) => $kick->player_id.'-'.$kick->team_id)
            ->all();
        $valid = true;

        foreach ($kicks as $index => $kick) {
            $teamId = (int) $kick['team_id'];

            if (! in_array($teamId, $teamIds, true)) {
                $validator->errors()->add("kicks.{$index}.team_id", 'O time informado não participa desta partida.');
                $valid = false;

                continue;
            }

            if (in_array((int) $kick['player_id'].'-'.$teamId, $recorded, true)) {
                continue;
            }

            $issue = $this->takerIssue($players->get((int) $kick['player_id']), $teamId);

            if ($issue !== null) {
                $validator->errors()->add("kicks.{$index}.player_id", $issue);
                $valid = false;
            }
        }

        return $valid;
    }

    private function takerIssue(?Player $player, int $teamId): ?string
    {
        if (! $player) {
            return 'O jogador informado não existe.';
        }

        if ((int) $player->team_id !== $teamId) {
            return 'O jogador informado não pertence ao time informado.';
        }

        return $player->trashed() ? 'O jogador informado foi removido do elenco.' : null;
    }

    private function validateSequence(Validator $validator, Game $game, array $kicks): void
    {
        $resolver = app(ShootoutResolver::class);
        $outOfTurn = $resolver->outOfTurn($kicks);

        if ($outOfTurn !== null) {
            $validator->errors()->add(
                'kicks.'.($outOfTurn - 1).'.team_id',
                "As cobranças devem alternar entre os times: a cobrança {$outOfTurn} é do mesmo time da anterior.",
            );

            return;
        }

        $result = $resolver->resolve($kicks, (int) $game->home_team_id, (int) $game->away_team_id);

        if (! $result['finished']) {
            $validator->errors()->add('kicks', 'A disputa de pênaltis ainda não está decidida; registre as cobranças até o fim.');

            return;
        }

        if ($result['decided_at'] < count($kicks)) {
            $validator->errors()->add('kicks', "A disputa de pênaltis foi decidida na cobrança {$result['decided_at']}; remova as cobranças seguintes.");
        }
    }
}
