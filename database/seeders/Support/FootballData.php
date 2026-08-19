<?php

namespace Database\Seeders\Support;

/**
 * Massa de dados "âncora" (campeonatos, ligas, times, estádios e técnicos
 * reais/realistas) compartilhada entre os seeders. Mantida como arrays
 * estáticos, sem tocar no Eloquent, para que cada seeder decida como
 * persistir e relacionar esses dados.
 */
class FootballData
{
    /**
     * Championship (marca) => nome.
     */
    public static function championships(): array
    {
        return [
            'brasileirao' => 'Campeonato Brasileiro',
            'copa_brasil' => 'Copa do Brasil',
            'paulista' => 'Campeonato Paulista',
            'premier_league' => 'Premier League',
            'la_liga' => 'La Liga',
        ];
    }

    /**
     * League (edição/temporada) => dados, vinculada à championship pela key acima.
     */
    public static function leagues(): array
    {
        return [
            'brasileirao_2026' => [
                'championship' => 'brasileirao',
                'name' => 'Brasileirão Série A 2026',
                'start_date' => '2026-04-04',
                'end_date' => '2026-12-06',
                'organizer' => 'CBF',
                'season' => '2026',
            ],
            'copa_brasil_2026' => [
                'championship' => 'copa_brasil',
                'name' => 'Copa do Brasil 2026',
                'start_date' => '2026-02-18',
                'end_date' => '2026-09-24',
                'organizer' => 'CBF',
                'season' => '2026',
            ],
            'paulista_2026' => [
                'championship' => 'paulista',
                'name' => 'Campeonato Paulista 2026',
                'start_date' => '2026-01-14',
                'end_date' => '2026-04-05',
                'organizer' => 'FPF',
                'season' => '2026',
            ],
            'premier_league_2025_26' => [
                'championship' => 'premier_league',
                'name' => 'Premier League 2025/26',
                'start_date' => '2025-08-16',
                'end_date' => '2026-05-24',
                'organizer' => 'The Football Association',
                'season' => '2025/26',
            ],
            'la_liga_2025_26' => [
                'championship' => 'la_liga',
                'name' => 'La Liga 2025/26',
                'start_date' => '2025-08-15',
                'end_date' => '2026-05-24',
                'organizer' => 'RFEF',
                'season' => '2025/26',
            ],
        ];
    }

    /**
     * Team key => nome oficial, apelido e locale usado para gerar nomes de
     * jogadores/comissão técnica (Faker) coerentes com o país do time.
     */
    public static function teams(): array
    {
        return [
            // Brasileirão Série A
            'corinthians' => ['name' => 'Sport Club Corinthians Paulista', 'nickname' => 'Corinthians', 'locale' => 'pt_BR'],
            'palmeiras' => ['name' => 'Sociedade Esportiva Palmeiras', 'nickname' => 'Palmeiras', 'locale' => 'pt_BR'],
            'flamengo' => ['name' => 'Clube de Regatas do Flamengo', 'nickname' => 'Flamengo', 'locale' => 'pt_BR'],
            'sao_paulo' => ['name' => 'São Paulo Futebol Clube', 'nickname' => 'São Paulo', 'locale' => 'pt_BR'],
            'santos' => ['name' => 'Santos Futebol Clube', 'nickname' => 'Santos', 'locale' => 'pt_BR'],
            'gremio' => ['name' => 'Grêmio Foot-Ball Porto Alegrense', 'nickname' => 'Grêmio', 'locale' => 'pt_BR'],
            'internacional' => ['name' => 'Sport Club Internacional', 'nickname' => 'Internacional', 'locale' => 'pt_BR'],
            'fluminense' => ['name' => 'Fluminense Football Club', 'nickname' => 'Fluminense', 'locale' => 'pt_BR'],
            'botafogo' => ['name' => 'Botafogo de Futebol e Regatas', 'nickname' => 'Botafogo', 'locale' => 'pt_BR'],
            'atletico_mg' => ['name' => 'Clube Atlético Mineiro', 'nickname' => 'Atlético-MG', 'locale' => 'pt_BR'],

            // Copa do Brasil (além dos times acima que também disputam)
            'cruzeiro' => ['name' => 'Cruzeiro Esporte Clube', 'nickname' => 'Cruzeiro', 'locale' => 'pt_BR'],
            'vasco' => ['name' => 'Club de Regatas Vasco da Gama', 'nickname' => 'Vasco da Gama', 'locale' => 'pt_BR'],
            'bahia' => ['name' => 'Esporte Clube Bahia', 'nickname' => 'Bahia', 'locale' => 'pt_BR'],
            'fortaleza' => ['name' => 'Fortaleza Esporte Clube', 'nickname' => 'Fortaleza', 'locale' => 'pt_BR'],
            'athletico_pr' => ['name' => 'Club Athletico Paranaense', 'nickname' => 'Athletico-PR', 'locale' => 'pt_BR'],
            'sport' => ['name' => 'Sport Club do Recife', 'nickname' => 'Sport', 'locale' => 'pt_BR'],
            'criciuma' => ['name' => 'Criciúma Esporte Clube', 'nickname' => 'Criciúma', 'locale' => 'pt_BR'],
            'vitoria' => ['name' => 'Esporte Clube Vitória', 'nickname' => 'Vitória', 'locale' => 'pt_BR'],

            // Campeonato Paulista (além dos times acima que também disputam)
            'bragantino' => ['name' => 'Red Bull Bragantino', 'nickname' => 'Bragantino', 'locale' => 'pt_BR'],
            'ponte_preta' => ['name' => 'Associação Atlética Ponte Preta', 'nickname' => 'Ponte Preta', 'locale' => 'pt_BR'],
            'guarani' => ['name' => 'Guarani Futebol Clube', 'nickname' => 'Guarani', 'locale' => 'pt_BR'],
            'sao_bento' => ['name' => 'Associação Desportiva São Bento', 'nickname' => 'São Bento', 'locale' => 'pt_BR'],
            'portuguesa' => ['name' => 'Associação Portuguesa de Desportos', 'nickname' => 'Portuguesa', 'locale' => 'pt_BR'],
            'novorizontino' => ['name' => 'Grêmio Novorizontino', 'nickname' => 'Novorizontino', 'locale' => 'pt_BR'],

            // Premier League
            'man_city' => ['name' => 'Manchester City FC', 'nickname' => 'Manchester City', 'locale' => 'en_GB'],
            'man_united' => ['name' => 'Manchester United FC', 'nickname' => 'Manchester United', 'locale' => 'en_GB'],
            'liverpool' => ['name' => 'Liverpool FC', 'nickname' => 'Liverpool', 'locale' => 'en_GB'],
            'arsenal' => ['name' => 'Arsenal FC', 'nickname' => 'Arsenal', 'locale' => 'en_GB'],
            'chelsea' => ['name' => 'Chelsea FC', 'nickname' => 'Chelsea', 'locale' => 'en_GB'],
            'tottenham' => ['name' => 'Tottenham Hotspur FC', 'nickname' => 'Tottenham', 'locale' => 'en_GB'],
            'newcastle' => ['name' => 'Newcastle United FC', 'nickname' => 'Newcastle', 'locale' => 'en_GB'],
            'aston_villa' => ['name' => 'Aston Villa FC', 'nickname' => 'Aston Villa', 'locale' => 'en_GB'],
            'west_ham' => ['name' => 'West Ham United FC', 'nickname' => 'West Ham', 'locale' => 'en_GB'],
            'everton' => ['name' => 'Everton FC', 'nickname' => 'Everton', 'locale' => 'en_GB'],

            // La Liga
            'real_madrid' => ['name' => 'Real Madrid Club de Fútbol', 'nickname' => 'Real Madrid', 'locale' => 'es_ES'],
            'barcelona' => ['name' => 'Futbol Club Barcelona', 'nickname' => 'Barcelona', 'locale' => 'es_ES'],
            'atletico_madrid' => ['name' => 'Club Atlético de Madrid', 'nickname' => 'Atlético de Madrid', 'locale' => 'es_ES'],
            'sevilla' => ['name' => 'Sevilla Fútbol Club', 'nickname' => 'Sevilla', 'locale' => 'es_ES'],
            'real_betis' => ['name' => 'Real Betis Balompié', 'nickname' => 'Real Betis', 'locale' => 'es_ES'],
            'real_sociedad' => ['name' => 'Real Sociedad de Fútbol', 'nickname' => 'Real Sociedad', 'locale' => 'es_ES'],
            'villarreal' => ['name' => 'Villarreal Club de Fútbol', 'nickname' => 'Villarreal', 'locale' => 'es_ES'],
            'athletic_bilbao' => ['name' => 'Athletic Club', 'nickname' => 'Athletic Bilbao', 'locale' => 'es_ES'],
            'valencia' => ['name' => 'Valencia Club de Fútbol', 'nickname' => 'Valencia', 'locale' => 'es_ES'],
            'girona' => ['name' => 'Girona Fútbol Club', 'nickname' => 'Girona', 'locale' => 'es_ES'],
        ];
    }

    /**
     * League key => lista de team keys participantes (define também a
     * pivot league_team). Alguns times aparecem em mais de uma liga
     * nacional, como na realidade (ex: Corinthians disputa Brasileirão,
     * Copa do Brasil e Paulista).
     */
    public static function leagueTeams(): array
    {
        return [
            'brasileirao_2026' => [
                'corinthians', 'palmeiras', 'flamengo', 'sao_paulo', 'santos',
                'gremio', 'internacional', 'fluminense', 'botafogo', 'atletico_mg',
            ],
            'copa_brasil_2026' => [
                'corinthians', 'flamengo', 'cruzeiro', 'vasco', 'bahia',
                'fortaleza', 'athletico_pr', 'sport', 'criciuma', 'vitoria',
            ],
            'paulista_2026' => [
                'corinthians', 'palmeiras', 'sao_paulo', 'santos', 'bragantino',
                'ponte_preta', 'guarani', 'sao_bento', 'portuguesa', 'novorizontino',
            ],
            'premier_league_2025_26' => [
                'man_city', 'man_united', 'liverpool', 'arsenal', 'chelsea',
                'tottenham', 'newcastle', 'aston_villa', 'west_ham', 'everton',
            ],
            'la_liga_2025_26' => [
                'real_madrid', 'barcelona', 'atletico_madrid', 'sevilla', 'real_betis',
                'real_sociedad', 'villarreal', 'athletic_bilbao', 'valencia', 'girona',
            ],
        ];
    }

    /**
     * Team key => estádio "mandante" real (nome, cidade, capacidade). Times
     * que dividem o mesmo estádio na vida real (ex: Flamengo e Fluminense no
     * Maracanã) apontam para o mesmo nome — o StadiumSeeder deduplica por
     * liga, criando uma única linha para o par (liga, nome do estádio).
     */
    public static function stadiums(): array
    {
        return [
            'corinthians' => ['name' => 'Neo Química Arena', 'city' => 'São Paulo', 'capacity' => 49205],
            'palmeiras' => ['name' => 'Allianz Parque', 'city' => 'São Paulo', 'capacity' => 43713],
            'flamengo' => ['name' => 'Maracanã', 'city' => 'Rio de Janeiro', 'capacity' => 78838],
            'sao_paulo' => ['name' => 'MorumBIS', 'city' => 'São Paulo', 'capacity' => 66795],
            'santos' => ['name' => 'Vila Belmiro', 'city' => 'Santos', 'capacity' => 16068],
            'gremio' => ['name' => 'Arena do Grêmio', 'city' => 'Porto Alegre', 'capacity' => 55662],
            'internacional' => ['name' => 'Estádio Beira-Rio', 'city' => 'Porto Alegre', 'capacity' => 50128],
            'fluminense' => ['name' => 'Maracanã', 'city' => 'Rio de Janeiro', 'capacity' => 78838],
            'botafogo' => ['name' => 'Estádio Nilton Santos', 'city' => 'Rio de Janeiro', 'capacity' => 46831],
            'atletico_mg' => ['name' => 'Arena MRV', 'city' => 'Belo Horizonte', 'capacity' => 46000],

            'cruzeiro' => ['name' => 'Mineirão', 'city' => 'Belo Horizonte', 'capacity' => 61846],
            'vasco' => ['name' => 'São Januário', 'city' => 'Rio de Janeiro', 'capacity' => 21880],
            'bahia' => ['name' => 'Arena Fonte Nova', 'city' => 'Salvador', 'capacity' => 47907],
            'fortaleza' => ['name' => 'Arena Castelão', 'city' => 'Fortaleza', 'capacity' => 63903],
            'athletico_pr' => ['name' => 'Arena da Baixada', 'city' => 'Curitiba', 'capacity' => 42372],
            'sport' => ['name' => 'Ilha do Retiro', 'city' => 'Recife', 'capacity' => 30003],
            'criciuma' => ['name' => 'Estádio Heriberto Hülse', 'city' => 'Criciúma', 'capacity' => 19124],
            'vitoria' => ['name' => 'Estádio Manoel Barradas', 'city' => 'Salvador', 'capacity' => 30858],

            'bragantino' => ['name' => 'Estádio Nabi Abi Chedid', 'city' => 'Bragança Paulista', 'capacity' => 17724],
            'ponte_preta' => ['name' => 'Estádio Moisés Lucarelli', 'city' => 'Campinas', 'capacity' => 19722],
            'guarani' => ['name' => 'Estádio Brinco de Ouro da Princesa', 'city' => 'Campinas', 'capacity' => 29130],
            'sao_bento' => ['name' => 'Estádio Walter Ribeiro', 'city' => 'Sorocaba', 'capacity' => 12000],
            'portuguesa' => ['name' => 'Estádio do Canindé', 'city' => 'São Paulo', 'capacity' => 16768],
            'novorizontino' => ['name' => 'Estádio Jorge Ismael de Biasi', 'city' => 'Novo Horizonte', 'capacity' => 10500],

            'man_city' => ['name' => 'Etihad Stadium', 'city' => 'Manchester', 'capacity' => 53400],
            'man_united' => ['name' => 'Old Trafford', 'city' => 'Manchester', 'capacity' => 74310],
            'liverpool' => ['name' => 'Anfield', 'city' => 'Liverpool', 'capacity' => 61276],
            'arsenal' => ['name' => 'Emirates Stadium', 'city' => 'London', 'capacity' => 60704],
            'chelsea' => ['name' => 'Stamford Bridge', 'city' => 'London', 'capacity' => 40343],
            'tottenham' => ['name' => 'Tottenham Hotspur Stadium', 'city' => 'London', 'capacity' => 62850],
            'newcastle' => ['name' => "St James' Park", 'city' => 'Newcastle upon Tyne', 'capacity' => 52305],
            'aston_villa' => ['name' => 'Villa Park', 'city' => 'Birmingham', 'capacity' => 42918],
            'west_ham' => ['name' => 'London Stadium', 'city' => 'London', 'capacity' => 62500],
            'everton' => ['name' => 'Goodison Park', 'city' => 'Liverpool', 'capacity' => 39414],

            'real_madrid' => ['name' => 'Santiago Bernabéu', 'city' => 'Madrid', 'capacity' => 81044],
            'barcelona' => ['name' => 'Spotify Camp Nou', 'city' => 'Barcelona', 'capacity' => 99354],
            'atletico_madrid' => ['name' => 'Cívitas Metropolitano', 'city' => 'Madrid', 'capacity' => 68456],
            'sevilla' => ['name' => 'Ramón Sánchez-Pizjuán', 'city' => 'Sevilla', 'capacity' => 43883],
            'real_betis' => ['name' => 'Benito Villamarín', 'city' => 'Sevilla', 'capacity' => 60720],
            'real_sociedad' => ['name' => 'Reale Arena', 'city' => 'San Sebastián', 'capacity' => 39500],
            'villarreal' => ['name' => 'Estadio de la Cerámica', 'city' => 'Villarreal', 'capacity' => 23500],
            'athletic_bilbao' => ['name' => 'San Mamés', 'city' => 'Bilbao', 'capacity' => 53289],
            'valencia' => ['name' => 'Mestalla', 'city' => 'Valencia', 'capacity' => 49430],
            'girona' => ['name' => 'Estadi Montilivi', 'city' => 'Girona', 'capacity' => 14624],
        ];
    }

    /**
     * Team key => nome do técnico principal (role "Técnico" na comissão
     * técnica). Auxiliares são gerados via Faker no seeder.
     */
    public static function headCoaches(): array
    {
        return [
            'corinthians' => 'Dorival Júnior',
            'palmeiras' => 'Abel Ferreira',
            'flamengo' => 'Filipe Luís',
            'sao_paulo' => 'Hernán Crespo',
            'santos' => 'Cléber Xavier',
            'gremio' => 'Mano Menezes',
            'internacional' => 'Roger Machado',
            'fluminense' => 'Renato Gaúcho',
            'botafogo' => 'Davide Ancelotti',
            'atletico_mg' => 'Cuca',

            'cruzeiro' => 'Leonardo Jardim',
            'vasco' => 'Fábio Carille',
            'bahia' => 'Rogério Ceni',
            'fortaleza' => 'Renato Paiva',
            'athletico_pr' => 'Lucho González',
            'sport' => 'Daniel Paulista',
            'criciuma' => 'Cláudio Tencati',
            'vitoria' => 'Thiago Carpini',

            'bragantino' => 'Fernando Seabra',
            'ponte_preta' => 'Eduardo Baptista',
            'guarani' => 'Umberto Louzer',
            'sao_bento' => 'Wagner Lopes',
            'portuguesa' => 'Rodrigo Santana',
            'novorizontino' => 'Eduardo Barroca',

            'man_city' => 'Pep Guardiola',
            'man_united' => 'Ruben Amorim',
            'liverpool' => 'Arne Slot',
            'arsenal' => 'Mikel Arteta',
            'chelsea' => 'Enzo Maresca',
            'tottenham' => 'Thomas Frank',
            'newcastle' => 'Eddie Howe',
            'aston_villa' => 'Unai Emery',
            'west_ham' => 'Graham Potter',
            'everton' => 'David Moyes',

            'real_madrid' => 'Xabi Alonso',
            'barcelona' => 'Hansi Flick',
            'atletico_madrid' => 'Diego Simeone',
            'sevilla' => 'Matías Almeyda',
            'real_betis' => 'Manuel Pellegrini',
            'real_sociedad' => 'Sergio Francisco',
            'villarreal' => 'Marcelino',
            'athletic_bilbao' => 'Ernesto Valverde',
            'valencia' => 'Carlos Corberán',
            'girona' => 'Míchel',
        ];
    }

    /**
     * Locale do Faker (nomes de jogadores/comissão) a partir do nome oficial
     * do time já persistido no banco.
     */
    public static function localeForTeamName(string $teamName): string
    {
        static $map = null;

        if ($map === null) {
            $map = collect(self::teams())->pluck('locale', 'name')->all();
        }

        return $map[$teamName] ?? 'pt_BR';
    }

    /**
     * Team key a partir do nome oficial do time já persistido no banco.
     */
    public static function teamKeyForName(string $teamName): string
    {
        static $map = null;

        if ($map === null) {
            $map = collect(self::teams())->mapWithKeys(fn (array $team, string $key) => [$team['name'] => $key])->all();
        }

        return $map[$teamName];
    }
}
