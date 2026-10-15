<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LeagueFilterRules;
use Illuminate\Foundation\Http\FormRequest;

class LeagueFilterRequest extends FormRequest
{
    use LeagueFilterRules;

    public function rules(): array
    {
        return $this->optionalLeagueFilterRules();
    }
}
