<?php

namespace App\Http\Requests;

/**
 * Excluir o campeonato apaga em cascata as ligas e, com elas, partidas, eventos, estádios
 * e vínculos: exige a senha do master, igual à exclusão de liga.
 */
class ChampionshipDestroyRequest extends LeagueDestroyRequest {}
