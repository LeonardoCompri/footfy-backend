<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\Stadium;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\LinksUsersToLeagues;
use Tests\TestCase;

class ValidationTranslationTest extends TestCase
{
    use LinksUsersToLeagues, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    private function authenticateAs(string $role, array $leagueIds = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        if (! empty($leagueIds)) {
            $this->linkUserToLeagues($user, $leagueIds);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_login_with_invalid_document_returns_portuguese_message(): void
    {
        $this->postJson('/api/login', ['document' => '123', 'password' => 'x'])
            ->assertStatus(422)
            ->assertExactJson([
                'message' => 'O formato do campo CPF é inválido.',
                'errors' => ['document' => ['O formato do campo CPF é inválido.']],
            ]);
    }

    public function test_login_without_fields_translates_the_summary_of_extra_errors(): void
    {
        $this->postJson('/api/login', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'O campo CPF é obrigatório. (e mais 1 erro)')
            ->assertJsonPath('errors.document.0', 'O campo CPF é obrigatório.')
            ->assertJsonPath('errors.password.0', 'O campo senha é obrigatório.');
    }

    public function test_store_player_interpolates_rule_parameters_and_keeps_custom_messages(): void
    {
        $this->authenticateAs('master');

        $this->postJson('/api/players', [
            'name' => str_repeat('a', 256),
            'document' => '123',
            'position' => 'artilheiro',
            'birth_date' => 'ontem',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'O campo time é obrigatório. (e mais 4 erros)')
            ->assertJsonPath('errors.team_id.0', 'O campo time é obrigatório.')
            ->assertJsonPath('errors.name.0', 'O campo nome não pode ter mais de 255 caracteres.')
            ->assertJsonPath('errors.document.0', 'O campo CPF deve ter 11 caracteres.')
            ->assertJsonPath('errors.birth_date.0', 'O campo data de nascimento deve ser uma data válida.')
            ->assertJsonPath('errors.position.0', 'A posição informada é inválida.');
    }

    public function test_nested_league_ids_attribute_is_readable(): void
    {
        $this->authenticateAs('master');
        $team = Team::factory()->create();

        $this->postJson("/api/teams/{$team->id}/leagues", ['league_ids' => [999999]])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['league_ids.0' => ['O valor selecionado para o campo liga é inválido.']]);

        $this->postJson("/api/teams/{$team->id}/leagues", [])
            ->assertStatus(422)
            ->assertJsonPath('errors.league_ids.0', 'O campo ligas é obrigatório.');
    }

    public function test_upload_that_is_not_an_image_returns_portuguese_message(): void
    {
        Storage::fake('public');
        $this->authenticateAs('master');

        $this->post('/api/teams', [
            'name' => 'Time do PDF',
            'logo' => UploadedFile::fake()->create('escudo.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.logo', [
                'O campo escudo deve ser uma imagem.',
                'O campo escudo deve ser um arquivo do tipo: jpeg, png, jpg, webp.',
            ]);
    }

    public function test_dates_and_numbers_use_readable_attribute_names(): void
    {
        $this->authenticateAs('master');

        $this->postJson('/api/leagues', [
            'name' => 'Série A',
            'start_date' => '2026-05-01',
            'end_date' => '2026-01-01',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.end_date.0', 'O campo data de término deve ser uma data posterior a data de início.');
    }

    public function test_committee_member_role_is_called_funcao(): void
    {
        $this->authenticateAs('master');
        $team = Team::factory()->create();

        $this->postJson('/api/technical-committee-members', ['team_id' => $team->id, 'name' => 'Fulano'])
            ->assertStatus(422)
            ->assertJsonPath('errors.role.0', 'O campo função é obrigatório.');
    }

    public function test_user_role_is_called_papel(): void
    {
        $this->authenticateAs('master');

        $this->postJson('/api/users', [
            'name' => 'Fulano',
            'document' => '12345678901',
            'password' => 'secret123',
            'role' => 'goleiro',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.role.0', 'O valor selecionado para o campo papel é inválido.');
    }

    public function test_spatie_permission_denied_returns_portuguese_message_with_403(): void
    {
        $league = League::factory()->create();
        $stadium = Stadium::factory()->create(['league_id' => $league->id]);
        $this->authenticateAs('coach', [$league->id]);

        $this->deleteJson("/api/stadiums/{$stadium->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Você não tem permissão para realizar esta ação.');
        $this->assertDatabaseHas('stadiums', ['id' => $stadium->id]);
    }

    public function test_spatie_role_denied_returns_portuguese_message_with_403(): void
    {
        $this->authenticateAs('admin');

        $this->postJson('/api/championships', ['name' => 'Copa'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Você não tem o papel necessário para realizar esta ação.');
    }

    public function test_locale_defaults_to_pt_br_without_app_locale_in_env(): void
    {
        $this->assertSame('pt_BR', app()->getLocale());
        $this->assertSame('en', config('app.fallback_locale'));

        $original = [getenv('APP_LOCALE'), $_ENV['APP_LOCALE'] ?? null, $_SERVER['APP_LOCALE'] ?? null];
        putenv('APP_LOCALE');
        unset($_ENV['APP_LOCALE'], $_SERVER['APP_LOCALE']);

        try {
            $config = require config_path('app.php');
        } finally {
            if ($original[0] !== false) {
                putenv('APP_LOCALE='.$original[0]);
            }
            if ($original[1] !== null) {
                $_ENV['APP_LOCALE'] = $original[1];
            }
            if ($original[2] !== null) {
                $_SERVER['APP_LOCALE'] = $original[2];
            }
        }

        $this->assertSame('pt_BR', $config['locale']);
        $this->assertSame('en', $config['fallback_locale']);
    }

    public function test_every_form_request_field_has_a_readable_attribute(): void
    {
        $user = $this->authenticateAs('master');
        $attributes = trans('validation.attributes');
        $missing = [];

        foreach (glob(app_path('Http/Requests/*.php')) as $file) {
            $class = 'App\\Http\\Requests\\'.basename($file, '.php');
            $request = new $class;
            $request->setUserResolver(fn () => $user);

            $own = $request->attributes();

            foreach (array_keys($request->rules()) as $field) {
                if (! isset($attributes[$field]) && ! isset($own[$field])) {
                    $missing[] = class_basename($class).'.'.$field;
                }
            }
        }

        $this->assertSame([], $missing, 'Campos sem nome em lang/pt_BR/validation.php > attributes.');
    }
}
