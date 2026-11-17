<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ListingRules;
use Illuminate\Foundation\Http\FormRequest;

class ChampionshipIndexRequest extends FormRequest
{
    use ListingRules;

    public const SORTABLE_COLUMNS = ['id', 'name', 'created_at'];

    public function rules(): array
    {
        return $this->listingRules(self::SORTABLE_COLUMNS);
    }
}
