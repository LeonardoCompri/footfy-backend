<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ListingRules;
use Illuminate\Foundation\Http\FormRequest;

class PlayerIndexRequest extends FormRequest
{
    use ListingRules;

    public const SORTABLE_COLUMNS = ['id', 'name', 'number', 'position', 'birth_date', 'created_at'];

    public function rules(): array
    {
        return array_merge($this->listingRules(self::SORTABLE_COLUMNS), [
            'team_id' => ['nullable', 'integer', 'min:1', 'regex:/^[0-9]+$/'],
        ]);
    }
}
