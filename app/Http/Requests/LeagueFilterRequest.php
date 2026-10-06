<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Valida o filtro opcional ?league_id= dos index() que listam recursos por liga
// (users, teams, stadiums, games). Rejeita com 422 array, texto ou sufixo não numérico,
// em vez de deixar o valor chegar à Service (array virava TypeError/500 e "1abc" era
// aceito via cast). O regex barra também o que `integer` aceita via filter_var (ex: "+1").
// Espaços nas pontas (" 1") já chegam aqui removidos pelo TrimStrings global.
// Não usa exists:leagues,id de propósito: o acesso à liga (inclusive liga inexistente
// para não-master) é decidido na Service, com a mensagem de sempre.
class LeagueFilterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'league_id' => ['nullable', 'integer', 'min:1', 'regex:/^[0-9]+$/'],
        ];
    }
}
