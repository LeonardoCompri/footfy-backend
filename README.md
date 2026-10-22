# ⚽ Footfy — Football Championship Manager API

API RESTful para gerenciamento de campeonatos de futebol, desenvolvida com
Laravel 12, autenticação via Bearer Token (Sanctum) e controle de
papéis/permissões com Spatie Permission. Arquitetura 100% API, organizada em
camadas **Controller → Service → Repository → Model**.

------------------------------------------------------------------------

## 📑 Sumário

- [Tecnologias](#-tecnologias-utilizadas)
- [Arquitetura](#-arquitetura)
- [Modelo de Dados](#-modelo-de-dados)
- [Sistema de Permissões e Roles](#-sistema-de-permissões-e-roles)
- [Instalação](#️-instalação)
- [Autenticação](#-autenticação)
- [Endpoints da API](#-endpoints-da-api)
  - [Campeonatos](#-campeonatos-tournaments)
  - [Times](#-times-teams)
  - [Jogadores](#-jogadores-players)
  - [Estádios](#-estádios-stadiums)
- [Padrão de Respostas e Erros](#-padrão-de-respostas-e-erros)
- [Testes Automatizados](#-testes-automatizados)
- [Próximos Passos](#-próximos-passos)
- [Licença](#-licença)

------------------------------------------------------------------------

## 🚀 Tecnologias Utilizadas

- PHP 8.2+
- Laravel 12
- Laravel Sail (Docker)
- Laravel Sanctum (autenticação via token)
- Spatie Laravel Permission ^7 (roles & permissions)
- MySQL 8.4 (produção/dev via Sail) / SQLite (testes)
- Redis, Meilisearch, Mailpit e Selenium (serviços auxiliares do Sail)
- Vite + Tailwind CSS (assets, opcional/frontend)

------------------------------------------------------------------------

## 🧠 Arquitetura

- API Stateless, prefixo base `/api`
- Autenticação via Bearer Token (Sanctum)
- Fluxo de cada recurso: `Controller` → `Service` → `Repository` → `Model`
- Validação de entrada via `FormRequest` (`Store`/`Update` por recurso)
- Formatação de saída via `JsonResource`
- Tratamento de erros centralizado em cada controller (`try/catch`)

Diretórios relevantes:

```
app/
├── Http/
│   ├── Controllers/   # AuthController, ChampionshipController, TeamController,
│   │                   PlayerController, StadiumController
│   ├── Requests/      # FormRequests de validação (Store/Update)
│   └── Resources/     # JsonResources de saída
├── Models/             # Championship, Team, Player, Stadium, Coach, Game, Goal, User
├── Repositories/       # Acesso a dados (Eloquent)
└── Services/           # Regras de negócio
```

------------------------------------------------------------------------

## 🗄 Modelo de Dados

### `users`

| Campo               | Tipo                | Observações                |
| ------------------- | ------------------- | -------------------------- |
| id                  | bigint (PK)          |                             |
| name                | string               |                             |
| email               | string, único, nullable |                          |
| document            | string, único        | CPF (somente números)      |
| email_verified_at   | timestamp, nullable  |                             |
| password            | string (hash bcrypt) |                             |
| remember_token      | string, nullable     |                             |
| created_at / updated_at | timestamp        |                             |

Usa `HasApiTokens` (Sanctum) e `HasRoles` (Spatie Permission).

### `championships`

| Campo       | Tipo                 | Observações |
| ----------- | -------------------- | ----------- |
| id          | bigint (PK)           |             |
| name        | string                | obrigatório |
| start_date  | date, nullable        |             |
| end_date    | date, nullable        | deve ser posterior a `start_date` |
| organizer   | string, nullable      |             |
| created_at / updated_at | timestamp |             |

Relacionamentos: `belongsToMany Team` (via `championship_team`), `hasMany Game`.

### `teams`

| Campo       | Tipo                 | Observações |
| ----------- | -------------------- | ----------- |
| id          | bigint (PK)           |             |
| name        | string                | obrigatório |
| nickname    | string, nullable      |             |
| logo        | string, nullable      | URL/caminho do logo |
| created_at / updated_at | timestamp |             |

Relacionamentos: `hasMany Player`, `hasOne Coach`, `belongsToMany Championship`,
`hasMany Game` (como `home_team_id` e `away_team_id`).

### `players`

| Campo       | Tipo                 | Observações |
| ----------- | -------------------- | ----------- |
| id          | bigint (PK)           |             |
| team_id     | bigint (FK teams), nullable | `null` ao excluir o time |
| name        | string                | obrigatório |
| position    | string, nullable      | ex: ATA, ZAG, LAT |
| number      | integer, nullable     | camisa |
| birth_date  | date, nullable        |             |
| document    | string(11), nullable, único | CPF do jogador (único inclusive entre removidos) |
| created_at / updated_at | timestamp |             |
| deleted_at  | timestamp, nullable   | soft delete: jogador removido sai do elenco, de `GET /players` e das rotas públicas, mas continua na súmula e nas estatísticas |

Relacionamentos: `belongsTo Team`, `hasMany Goal`.

> ℹ️ `DELETE /api/players/{id}` faz soft delete. Recadastrar (`POST /api/players`) o CPF de um
> jogador removido restaura o mesmo registro (mesmo `id`) com os dados novos.
> `GET /api/public/players/{id}` responde `404` para jogador inexistente ou removido.

### `stadiums`

| Campo       | Tipo                 | Observações |
| ----------- | -------------------- | ----------- |
| id          | bigint (PK)           |             |
| name        | string                | obrigatório |
| city        | string                | obrigatório |
| capacity    | integer               | obrigatório, mínimo 0 |
| created_at / updated_at | timestamp |             |

### `coaches`

| Campo       | Tipo                 | Observações |
| ----------- | -------------------- | ----------- |
| id          | bigint (PK)           |             |
| team_id     | bigint (FK teams), nullable | `null` ao excluir o time |
| name        | string                |             |
| birth_date  | date, nullable        |             |
| created_at / updated_at | timestamp |             |

Relacionamento: `belongsTo Team` (via `team_id`).

### `games`

| Campo            | Tipo                          | Observações |
| ---------------- | ------------------------------ | ----------- |
| id               | bigint (PK)                     |             |
| championship_id  | bigint (FK championships)       | cascade on delete |
| home_team_id     | bigint (FK teams)                | cascade on delete |
| away_team_id     | bigint (FK teams)                | cascade on delete |
| stadium_id       | bigint (FK stadiums), nullable   | `null` ao excluir o estádio |
| match_date       | datetime, nullable               |             |
| home_score       | integer, nullable                |             |
| away_score       | integer, nullable                |             |
| round            | integer, nullable                |             |
| status           | string                            |             |
| created_at / updated_at | timestamp                  |             |

Relacionamentos: `belongsTo Championship`, `belongsTo Team` (home/away),
`hasMany Goal`.

### `goals`

| Campo       | Tipo                 | Observações |
| ----------- | -------------------- | ----------- |
| id          | bigint (PK)           |             |
| game_id     | bigint (FK games)      | cascade on delete |
| player_id   | bigint (FK players)    | restrict on delete (em `game_events`; o jogador usa soft delete) |
| team_id     | bigint (FK teams)       | cascade on delete |
| minute      | integer, nullable      |             |
| created_at / updated_at | timestamp |             |

Relacionamentos: `belongsTo Game`, `belongsTo Player`.

> ℹ️ Na resposta dos eventos da súmula (`/api/game-events` e `events` de `GET /api/games/{id}`
> e `GET /api/public/games/{id}`), além de `player_id`/`related_player_id`, vêm `player` e
> `related_player` no formato `{ "id": 1, "name": "...", "removed": false }` (`related_player`
> é `null` sem assistência). `removed: true` indica jogador removido (soft delete), que continua
> na súmula. Novos eventos não aceitam jogador removido (`422`).

### `championship_team` (pivot)

| Campo            | Tipo                       | Observações |
| ---------------- | --------------------------- | ----------- |
| id               | bigint (PK)                  |             |
| championship_id | bigint (FK championships)     | cascade on delete |
| team_id          | bigint (FK teams)             | cascade on delete |
| created_at / updated_at | timestamp              |             |

### Tabelas auxiliares

- `personal_access_tokens` — tokens do Sanctum
- `permissions`, `roles`, `model_has_permissions`, `model_has_roles`,
  `role_has_permissions` — geradas pelo Spatie Permission

> ⚠️ Os models `Coach`, `Game` e `Goal` e suas tabelas já existem no banco
> (com permissões correspondentes seedadas), mas **ainda não possuem
> Controllers/rotas de API** — veja [Próximos Passos](#-próximos-passos).

------------------------------------------------------------------------

## 🛡 Sistema de Permissões e Roles

Formato das permissões: `modulo:ação`

**Módulos**: `tournament`, `team`, `player`, `stadium`, `coach`, `game`, `goal`
**Ações**: `all`, `create`, `read`, `update`, `delete`

Total de **35 permissões** (7 módulos × 5 ações), criadas pelo
`PermissionSeeder`.

### Roles (`RoleSeeder`)

| Role     | Permissões concedidas |
| -------- | ----------------------------------------------------------------- |
| `master` | Todas as 35 permissões (`*:all`, `*:create`, `*:read`, `*:update`, `*:delete`) |
| `admin`  | `tournament:read`, `tournament:update`, CRUD completo (`read/create/update/delete`) de `team`, `player`, `stadium`, `coach`, `game`, `goal` |
| `coach`  | `team:read`, `team:update`, `stadium:read/create/update`, `player:read/create/update` |
| `player` | `player:read`, `player:update` |

> ℹ️ As roles e permissões do usuário autenticado são retornadas no login
> (`user.roles` e `user.permissions`), prontas para uso no frontend. As rotas
> da API atualmente exigem apenas autenticação (`auth:sanctum`); a
> aplicação de middlewares de autorização por permissão está prevista nos
> [Próximos Passos](#-próximos-passos).

------------------------------------------------------------------------

## 🛠️ Instalação

### 1️⃣ Clonar o repositório

```bash
git clone <url-do-repositorio>
cd footfy
```

### 2️⃣ Copiar o `.env`

```bash
cp .env.example .env
```

### 3️⃣ Subir os containers (Sail)

```bash
./vendor/bin/sail up -d
```

### 4️⃣ Instalar dependências

```bash
./vendor/bin/sail composer install
```

### 5️⃣ Gerar a chave da aplicação

```bash
./vendor/bin/sail artisan key:generate
```

### 6️⃣ Rodar migrations + seeders

```bash
./vendor/bin/sail artisan migrate:fresh --seed
```

Após o seed, um usuário `master` é criado (ver [Autenticação](#-autenticação)).

------------------------------------------------------------------------

## 🔐 Autenticação

Toda a API (exceto `/api/login`) exige o header:

```
Authorization: Bearer {token}
Accept: application/json
```

### Usuário padrão (seed)

| Campo     | Valor                 |
| --------- | --------------------- |
| document  | `45047364895`         |
| password  | `123456`               |
| role      | `master`               |

### `POST /api/login`

Autentica via **document (CPF)** + senha e retorna um Bearer Token.

**Body:**

```json
{
  "document": "450.473.648-95",
  "password": "123456"
}
```

Validação:

- `document`: obrigatório, formato CPF (`000.000.000-00` ou `00000000000`)
- `password`: obrigatório

**Resposta 200:**

```json
{
  "token": "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "user": {
    "id": 1,
    "name": "Leonardo Compri",
    "email": "leonardocompri46@gmail.com",
    "document": "45047364895",
    "roles": [{ "id": 1, "name": "master", "guard_name": "web", "...": "..." }],
    "permissions": [{ "id": 1, "name": "tournament:all", "...": "..." }]
  }
}
```

**Resposta 401** (CPF ou senha inválidos):

```json
{ "message": "CPF ou senha inválidos" }
```

### `POST /api/logout` 🔒

Revoga todos os tokens do usuário autenticado.

**Resposta 200:**

```json
{ "message": "Logout realizado" }
```

------------------------------------------------------------------------

## 📚 Endpoints da API

> 🔒 = requer header `Authorization: Bearer {token}` (todas as rotas abaixo)

Base URL: `http://localhost/api`

### 🏆 Campeonatos (`tournaments`)

| Método | Rota                    | Descrição              |
| ------ | ------------------------ | ----------------------- |
| GET    | `/api/tournaments`        | Lista todos os campeonatos |
| POST   | `/api/tournaments`         | Cria um campeonato |
| GET    | `/api/tournaments/{id}`     | Exibe um campeonato |
| PUT    | `/api/tournaments/{id}`     | Atualiza um campeonato |
| DELETE | `/api/tournaments/{id}`     | Remove um campeonato |

**Campos (Store / Update):**

| Campo       | Regras (Store)                              | Regras (Update) |
| ----------- | --------------------------------------------- | ----------------- |
| `name`      | obrigatório, string, max 255                  | opcional (`sometimes`), string, max 255 |
| `start_date`| opcional, data                                | opcional, data |
| `end_date`  | opcional, data, posterior a `start_date`       | opcional, data, posterior a `start_date` |
| `organizer` | opcional, string, max 255                      | opcional, string, max 255 |

**Resposta (item):**

```json
{
  "data": {
    "id": 1,
    "name": "Brasileirão Série A",
    "start_date": "2026-04-01",
    "end_date": "2026-12-01",
    "organizer": "CBF",
    "created_at": "2026-06-13T12:00:00.000000Z",
    "updated_at": "2026-06-13T12:00:00.000000Z"
  }
}
```

`GET /api/tournaments` retorna `{"data": [ ... ]}` com uma lista de itens no
mesmo formato.

Erro 422 ao não encontrar:

```json
{ "message": "Campeonato não encontrado!" }
```

------------------------------------------------------------------------

### 🏟 Times (`teams`)

| Método | Rota              | Descrição          |
| ------ | ------------------ | -------------------- |
| GET    | `/api/teams`        | Lista todos os times |
| POST   | `/api/teams`         | Cria um time |
| GET    | `/api/teams/{id}`     | Exibe um time |
| PUT    | `/api/teams/{id}`     | Atualiza um time |
| DELETE | `/api/teams/{id}`     | Remove um time |

**Campos (Store / Update):**

| Campo      | Regras (Store)              | Regras (Update) |
| ---------- | ----------------------------- | ----------------- |
| `name`     | obrigatório, string, max 255   | opcional (`sometimes`), string, max 255 |
| `nickname` | opcional, string, max 255      | opcional, string, max 255 |
| `logo`     | opcional, string, max 255       | opcional, string, max 255 |

**Resposta (item):**

```json
{
  "data": {
    "id": 1,
    "name": "Sport Club X",
    "nickname": "SCX",
    "logo": null,
    "created_at": "2026-06-13T12:00:00.000000Z",
    "updated_at": "2026-06-13T12:00:00.000000Z"
  }
}
```

Erro 422 ao não encontrar:

```json
{ "message": "Time não encontrado!" }
```

> ℹ️ Ao excluir um time, os jogadores vinculados **não são removidos**: o
> campo `team_id` desses jogadores é definido como `null` (`nullOnDelete`).

------------------------------------------------------------------------

### 👤 Jogadores (`players`)

| Método | Rota                | Descrição              |
| ------ | -------------------- | ------------------------ |
| GET    | `/api/players`        | Lista todos os jogadores |
| POST   | `/api/players`         | Cria um jogador |
| GET    | `/api/players/{id}`     | Exibe um jogador |
| PUT    | `/api/players/{id}`     | Atualiza um jogador |
| DELETE | `/api/players/{id}`     | Remove um jogador |

**Campos (Store / Update):**

| Campo       | Regras (Store)                                   | Regras (Update) |
| ----------- | --------------------------------------------------- | ----------------- |
| `team_id`   | opcional, inteiro, deve existir em `teams.id`        | opcional, inteiro, deve existir em `teams.id` |
| `name`      | obrigatório, string, max 255                         | opcional (`sometimes`), string, max 255 |
| `position`  | opcional, string, max 255                            | opcional, string, max 255 |
| `number`    | opcional, inteiro                                    | opcional, inteiro |
| `birth_date`| opcional, data                                       | opcional, data |
| `document`  | opcional, string, exatamente 11 caracteres, único     | opcional, string, exatamente 11 caracteres, único (ignorando o próprio registro) |

**Resposta (item):**

```json
{
  "data": {
    "id": 1,
    "team_id": 1,
    "name": "Jogador Teste",
    "position": "ATA",
    "number": 9,
    "birth_date": "2000-01-01",
    "document": "12345678901",
    "created_at": "2026-06-13T12:00:00.000000Z",
    "updated_at": "2026-06-13T12:00:00.000000Z"
  }
}
```

Erro 422 ao não encontrar:

```json
{ "message": "Jogador não encontrado!" }
```

------------------------------------------------------------------------

### 🏟 Estádios (`stadiums`)

| Método | Rota                 | Descrição             |
| ------ | --------------------- | ----------------------- |
| GET    | `/api/stadiums`         | Lista todos os estádios |
| POST   | `/api/stadiums`          | Cria um estádio |
| GET    | `/api/stadiums/{id}`      | Exibe um estádio |
| PUT    | `/api/stadiums/{id}`      | Atualiza um estádio |
| DELETE | `/api/stadiums/{id}`      | Remove um estádio |

**Campos (Store / Update):**

| Campo      | Regras (Store)                       | Regras (Update) |
| ---------- | --------------------------------------- | ----------------- |
| `name`     | obrigatório, string, max 255             | opcional (`sometimes`), string, max 255 |
| `city`     | obrigatório, string, max 255             | opcional (`sometimes`), string, max 255 |
| `capacity` | obrigatório, inteiro, mínimo 0           | opcional (`sometimes`), inteiro, mínimo 0 |

**Resposta (item):**

```json
{
  "data": {
    "id": 1,
    "name": "Arena Y",
    "city": "Rio de Janeiro",
    "capacity": 60000,
    "created_at": "2026-06-13T12:00:00.000000Z",
    "updated_at": "2026-06-13T12:00:00.000000Z"
  }
}
```

Erro 422 ao não encontrar:

```json
{ "message": "Estádio não encontrado!" }
```

------------------------------------------------------------------------

### 📊 Estatísticas e Dashboard da Liga (`leagues`)

| Método | Rota                                   | Acesso | Descrição |
| ------ | --------------------------------------- | ------ | --------- |
| GET    | `/api/leagues/{id}/statistics`          | 🔒 `access:league` | Estatísticas da liga (admin) |
| GET    | `/api/public/leagues/{id}/statistics`   | público | Mesmas estatísticas, sem autenticação |
| GET    | `/api/leagues/{id}/dashboard`           | 🔒 `access:league` | Dashboard do admin para a liga |

Admin sem vínculo com a liga recebe `403` (`"Você não tem acesso a este recurso."`).
As rotas admin e pública de `statistics` usam o mesmo cálculo e retornam o mesmo corpo.

**Resposta de `statistics`:**

```json
{
  "data": {
    "top_scorers":  [ { "player_id": 1, "player_name": "...", "player_removed": false, "team_id": 1, "team_name": "...", "team_nickname": "...", "goals": 7 } ],
    "top_assists":  [ { "player_id": 2, "player_name": "...", "player_removed": false, "team_id": 1, "team_name": "...", "team_nickname": "...", "assists": 4 } ],
    "top_cards":    [ { "player_id": 3, "player_name": "...", "player_removed": false, "team_id": 2, "team_name": "...", "team_nickname": "...", "yellow_cards": 2, "red_cards": 1, "total_cards": 3 } ],
    "goals_by_bucket": [ { "type": "round", "round": 1, "phase": null, "phase_order": null, "goals": 5 } ],
    "results_split": { "home_wins": 4, "draws": 2, "away_wins": 3 },
    "totals": { "goals": 23, "finished_games": 9, "goals_average": 2.56 },
    "cards_split": { "yellow": 27, "red": 3 }
  }
}
```

- `goals_by_bucket` / `totals`: gols calculados pelo **placar** (`home_score + away_score`)
  das partidas `finished` com placar preenchido — não dependem da súmula (eventos `goal`).
  Partidas não finalizadas nunca entram, mesmo com placar preenchido.
- `totals.goals_average`: `round(goals / finished_games, 2)`, sempre float (ex: `2.0`);
  `null` quando não há partidas finalizadas.
- `top_scorers` / `top_assists` / `top_cards`: rankings limitados aos **10** primeiros jogadores,
  calculados pelos eventos da súmula. Jogador removido (soft delete) continua no ranking com
  `player_removed: true`.
- `cards_split`: total de cartões amarelos (`yellow`) e vermelhos (`red`) de **todos** os
  eventos da liga (grupos + mata-mata), **sem** o limite de 10 do `top_cards`. Sempre
  inteiros — liga sem eventos retorna `{ "yellow": 0, "red": 0 }`. Cartões de outras
  ligas nunca entram na soma.

**Resposta de `dashboard`:**

```json
{
  "data": {
    "counters": { "teams": 8, "games": 28, "stadiums": 4, "season": "2026" },
    "last_results": [ ... ],
    "upcoming_games": [ ... ],
    "goals_by_bucket": [ ... ],
    "results_split": { "home_wins": 4, "draws": 2, "away_wins": 3 },
    "totals": { "goals": 23, "finished_games": 9, "goals_average": 2.56 },
    "cards_split": { "yellow": 27, "red": 3 }
  }
}
```

`goals_by_bucket`, `results_split`, `totals` e `cards_split` do dashboard são os mesmos valores
retornados por `statistics` para a liga.

------------------------------------------------------------------------

## 📦 Padrão de Respostas e Erros

| Situação                          | Status | Corpo |
| ---------------------------------- | ------ | ----- |
| Sucesso (listagem)                  | 200    | `{ "data": [ ... ] }` |
| Sucesso (item único)                | 200    | `{ "data": { ... } }` |
| Criado com sucesso                  | 201    | `{ "data": { ... } }` |
| Removido com sucesso                | 204    | sem corpo |
| Não autenticado                     | 401    | `{ "message": "Unauthenticated." }` |
| Falha de validação (FormRequest)    | 422    | `{ "message": "...", "errors": { "campo": ["..."] } }` |
| Recurso não encontrado (`show`/`update`/`destroy`) | 422 | `{ "message": "<Recurso> não encontrado!" }` |
| Erro interno                        | 500    | `{ "message": "Houve um erro no servidor, tente novamente!" }` |

------------------------------------------------------------------------

## 🧪 Testes Automatizados

Testes de feature cobrem autenticação, CRUD e validações de cada recurso
(`tests/Feature/*ControllerTest.php`), usando SQLite em memória.

```bash
./vendor/bin/sail artisan test
# ou
./vendor/bin/sail composer test
```

------------------------------------------------------------------------

## 📈 Próximos Passos

- Endpoints de API para `coaches`, `games` e `goals`
- Middleware de autorização aplicando as permissões já seedadas (`*:create`,
  `*:read`, `*:update`, `*:delete`) às rotas
- Documentação Swagger / OpenAPI
- Versionamento de API
- Logs estruturados
- Sistema de estatísticas do campeonato (artilharia, classificação, etc.)

------------------------------------------------------------------------

## 📄 Licença

Projeto para fins educacionais e desenvolvimento pessoal.
