<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LeagueFilterRules;
use App\Http\Requests\Concerns\ListingRules;
use Illuminate\Foundation\Http\FormRequest;

class TeamIndexRequest extends FormRequest
{
    use LeagueFilterRules, ListingRules;

    public const SORTABLE_COLUMNS = ['id', 'name', 'nickname', 'created_at'];

    public function rules(): array
    {
        return array_merge(
            $this->optionalLeagueFilterRules(),
            $this->listingRules(self::SORTABLE_COLUMNS),
        );
    }
}
