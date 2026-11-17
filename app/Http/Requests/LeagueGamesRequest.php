<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\PaginationRules;
use Illuminate\Foundation\Http\FormRequest;

class LeagueGamesRequest extends FormRequest
{
    use PaginationRules;

    public function rules(): array
    {
        return $this->paginationRules();
    }
}
