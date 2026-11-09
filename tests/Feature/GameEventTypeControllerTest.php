<?php

namespace Tests\Feature;

use App\Models\GameEvent;
use App\Models\GameEventType;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GameEventTypeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    private function authenticateAs(string $role = 'master'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'penalty_saved',
            'name' => 'Pênalti defendido',
            'icon' => 'mdi-hand-back-right',
            'color' => 'info',
        ], $overrides);
    }

    public function test_guest_cannot_list_event_types(): void
    {
        $this->getJson('/api/event-types')->assertStatus(401);
    }

    public static function roles(): array
    {
        return [['master'], ['admin'], ['coach'], ['player']];
    }

    #[DataProvider('roles')]
    public function test_every_role_lists_all_types_ordered_including_inactive(string $role): void
    {
        $this->authenticateAs($role);
        $inactive = GameEventType::factory()->inactive()->create(['slug' => 'antigo', 'sort_order' => 0]);
        $sameOrder = GameEventType::factory()->create(['slug' => 'empate_ordem', 'sort_order' => 1]);

        $response = $this->getJson('/api/event-types')->assertOk();

        $this->assertSame(
            ['antigo', 'goal', 'empate_ordem', 'own_goal', 'yellow_card', 'red_card'],
            array_column($response->json('data'), 'slug')
        );
        $response->assertJsonPath('data.0', [
            'id' => $inactive->id,
            'slug' => 'antigo',
            'name' => $inactive->name,
            'abbreviation' => null,
            'icon' => 'mdi-star',
            'color' => 'grey',
            'counts_for' => null,
            'allows_assist' => false,
            'card' => null,
            'sort_order' => 0,
            'active' => false,
        ]);
        $this->assertSame(
            [null, 'G', null, 'GC', 'CA', 'CV'],
            array_column($response->json('data'), 'abbreviation')
        );
        $response->assertJsonPath('data.1.counts_for', 'team')
            ->assertJsonPath('data.1.allows_assist', true)
            ->assertJsonPath('data.2.id', $sameOrder->id);
    }

    public static function nonMasterWrites(): array
    {
        $cases = [];
        foreach (['admin', 'coach', 'player'] as $role) {
            $cases["{$role} store"] = [$role, 'POST', '/api/event-types'];
            $cases["{$role} update"] = [$role, 'PUT', '/api/event-types/{id}'];
            $cases["{$role} destroy"] = [$role, 'DELETE', '/api/event-types/{id}'];
        }

        return $cases;
    }

    #[DataProvider('nonMasterWrites')]
    public function test_non_master_cannot_write_event_types(string $role, string $method, string $url): void
    {
        $this->authenticateAs($role);
        $type = GameEventType::factory()->create();

        $this->json($method, str_replace('{id}', (string) $type->id, $url), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseHas('game_event_types', ['id' => $type->id, 'name' => $type->name]);
        $this->assertDatabaseMissing('game_event_types', ['slug' => 'penalty_saved']);
    }

    public function test_master_creates_type_with_defaults_at_the_end_of_the_list(): void
    {
        $this->authenticateAs();

        $response = $this->postJson('/api/event-types', $this->payload())->assertCreated();

        $response->assertJsonPath('data.slug', 'penalty_saved')
            ->assertJsonPath('data.counts_for', null)
            ->assertJsonPath('data.allows_assist', false)
            ->assertJsonPath('data.card', null)
            ->assertJsonPath('data.sort_order', 5)
            ->assertJsonPath('data.active', true);
        $this->assertDatabaseHas('game_event_types', ['slug' => 'penalty_saved', 'sort_order' => 5, 'active' => true]);
    }

    public function test_master_creates_scoring_type_with_assist(): void
    {
        $this->authenticateAs();

        $this->postJson('/api/event-types', $this->payload([
            'slug' => 'gol_olimpico',
            'counts_for' => 'team',
            'allows_assist' => true,
            'sort_order' => 2,
            'active' => false,
        ]))->assertCreated()
            ->assertJsonPath('data.counts_for', 'team')
            ->assertJsonPath('data.allows_assist', true)
            ->assertJsonPath('data.sort_order', 2)
            ->assertJsonPath('data.active', false);
    }

    public static function invalidStorePayloads(): array
    {
        return [
            'slug com maiúscula' => [['slug' => 'Penalty'], 'slug'],
            'slug com hífen' => [['slug' => 'penalty-saved'], 'slug'],
            'slug com espaço' => [['slug' => 'penalty saved'], 'slug'],
            'slug longo' => [['slug' => str_repeat('a', 51)], 'slug'],
            'slug repetido' => [['slug' => 'goal'], 'slug'],
            'sem slug' => [['slug' => null], 'slug'],
            'nome longo' => [['name' => str_repeat('a', 61)], 'name'],
            'ícone sem mdi' => [['icon' => 'soccer'], 'icon'],
            'ícone com maiúscula' => [['icon' => 'mdi-Soccer'], 'icon'],
            'ícone com script' => [['icon' => 'mdi-x"><script>'], 'icon'],
            'cor fora do tema' => [['color' => '#ff0000'], 'color'],
            'cor inexistente' => [['color' => 'purple'], 'color'],
            'counts_for inválido' => [['counts_for' => 'both'], 'counts_for'],
            'card inválido' => [['card' => 'blue'], 'card'],
            'cartão que conta no placar' => [['card' => 'red', 'counts_for' => 'team'], 'card'],
            'cartão que conta para o adversário' => [['card' => 'yellow', 'counts_for' => 'opponent'], 'card'],
            'assistência sem placar' => [['allows_assist' => true], 'allows_assist'],
            'assistência em gol contra' => [['allows_assist' => true, 'counts_for' => 'opponent'], 'allows_assist'],
            'assistência em cartão' => [['allows_assist' => true, 'card' => 'red'], 'allows_assist'],
            'ordem negativa' => [['sort_order' => -1], 'sort_order'],
            'ativo não booleano' => [['active' => 'sim'], 'active'],
            'sigla longa' => [['abbreviation' => 'ABCD'], 'abbreviation'],
            'sigla repetida' => [['abbreviation' => 'GC'], 'abbreviation'],
            'sigla repetida em minúscula' => [['abbreviation' => 'gc'], 'abbreviation'],
            'sigla com símbolo' => [['abbreviation' => 'G!'], 'abbreviation'],
            'sigla com espaço' => [['abbreviation' => 'P D'], 'abbreviation'],
            'sigla não texto' => [['abbreviation' => ['PD']], 'abbreviation'],
        ];
    }

    #[DataProvider('invalidStorePayloads')]
    public function test_store_validates_fields(array $overrides, string $field): void
    {
        $this->authenticateAs();

        $this->postJson('/api/event-types', $this->payload($overrides))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        $this->assertSame(4, GameEventType::count());
    }

    public function test_master_creates_type_with_abbreviation_in_uppercase(): void
    {
        $this->authenticateAs();

        $this->postJson('/api/event-types', $this->payload(['abbreviation' => 'pdé']))
            ->assertCreated()
            ->assertJsonPath('data.abbreviation', 'PDÉ');

        $this->assertDatabaseHas('game_event_types', ['slug' => 'penalty_saved', 'abbreviation' => 'PDÉ']);
    }

    public function test_abbreviation_is_optional_on_create(): void
    {
        $this->authenticateAs();

        $this->postJson('/api/event-types', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.abbreviation', null);
        $this->postJson('/api/event-types', $this->payload(['slug' => 'outro', 'abbreviation' => null]))
            ->assertCreated()
            ->assertJsonPath('data.abbreviation', null);
    }

    public function test_master_updates_abbreviation_of_type_in_use(): void
    {
        $this->authenticateAs();
        GameEvent::factory()->create(['type' => 'goal']);
        $goal = GameEventType::where('slug', 'goal')->firstOrFail();

        $this->putJson("/api/event-types/{$goal->id}", ['abbreviation' => 'gl'])
            ->assertOk()
            ->assertJsonPath('data.abbreviation', 'GL');
        $this->putJson("/api/event-types/{$goal->id}", ['abbreviation' => 'GL', 'name' => 'Gol'])
            ->assertOk()
            ->assertJsonPath('data.abbreviation', 'GL');
        $this->putJson("/api/event-types/{$goal->id}", ['name' => 'Gol de placa'])
            ->assertOk()
            ->assertJsonPath('data.abbreviation', 'GL');
        $this->putJson("/api/event-types/{$goal->id}", ['abbreviation' => null])
            ->assertOk()
            ->assertJsonPath('data.abbreviation', null);
    }

    public function test_update_rejects_abbreviation_of_another_type(): void
    {
        $this->authenticateAs();
        $goal = GameEventType::where('slug', 'goal')->firstOrFail();

        $this->putJson("/api/event-types/{$goal->id}", ['abbreviation' => 'cv'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('abbreviation');
        $this->putJson("/api/event-types/{$goal->id}", ['abbreviation' => 'GOLS'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('abbreviation');

        $this->assertDatabaseHas('game_event_types', ['id' => $goal->id, 'abbreviation' => 'G']);
    }

    public function test_slug_cannot_be_changed(): void
    {
        $this->authenticateAs();
        $type = GameEventType::factory()->create(['slug' => 'antes']);

        $this->putJson("/api/event-types/{$type->id}", ['slug' => 'depois'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        $this->assertDatabaseHas('game_event_types', ['id' => $type->id, 'slug' => 'antes']);
    }

    public function test_master_updates_display_fields_of_type_in_use(): void
    {
        $this->authenticateAs();
        GameEvent::factory()->create(['type' => 'goal']);
        $goal = GameEventType::where('slug', 'goal')->firstOrFail();

        $this->putJson("/api/event-types/{$goal->id}", [
            'name' => 'Golaço',
            'icon' => 'mdi-soccer-field',
            'color' => 'success',
            'sort_order' => 9,
            'active' => false,
            'counts_for' => 'team',
            'allows_assist' => true,
            'card' => null,
        ])->assertOk()
            ->assertJsonPath('data.slug', 'goal')
            ->assertJsonPath('data.name', 'Golaço')
            ->assertJsonPath('data.color', 'success')
            ->assertJsonPath('data.sort_order', 9)
            ->assertJsonPath('data.active', false);
    }

    public static function behaviorChanges(): array
    {
        return [
            'counts_for' => [['counts_for' => null, 'allows_assist' => false]],
            'allows_assist' => [['allows_assist' => false]],
            'card' => [['card' => 'yellow', 'counts_for' => null, 'allows_assist' => false]],
        ];
    }

    #[DataProvider('behaviorChanges')]
    public function test_behavior_flags_are_immutable_when_type_is_in_use(array $payload): void
    {
        $this->authenticateAs();
        GameEvent::factory()->create(['type' => 'goal']);
        $goal = GameEventType::where('slug', 'goal')->firstOrFail();

        $this->putJson("/api/event-types/{$goal->id}", $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tipo em uso: não é possível alterar se ele conta no placar, se permite assistência nem o cartão.');

        $this->assertDatabaseHas('game_event_types', ['id' => $goal->id, 'counts_for' => 'team', 'allows_assist' => true, 'card' => null]);
    }

    public function test_behavior_flags_change_when_type_is_not_in_use(): void
    {
        $this->authenticateAs();
        $type = GameEventType::factory()->create();

        $this->putJson("/api/event-types/{$type->id}", ['counts_for' => 'team', 'allows_assist' => true])
            ->assertOk()
            ->assertJsonPath('data.counts_for', 'team')
            ->assertJsonPath('data.allows_assist', true);

        $this->putJson("/api/event-types/{$type->id}", ['counts_for' => null, 'allows_assist' => false, 'card' => 'red'])
            ->assertOk()
            ->assertJsonPath('data.card', 'red');
    }

    public function test_update_checks_flags_against_stored_values(): void
    {
        $this->authenticateAs();
        $card = GameEventType::factory()->card('red')->create();
        $scoring = GameEventType::factory()->scoring()->create();

        $this->putJson("/api/event-types/{$card->id}", ['counts_for' => 'team'])
            ->assertStatus(422)->assertJsonValidationErrors('card');
        $this->putJson("/api/event-types/{$scoring->id}", ['counts_for' => null])
            ->assertStatus(422)->assertJsonValidationErrors('allows_assist');
    }

    public function test_update_validates_display_fields(): void
    {
        $this->authenticateAs();
        $type = GameEventType::factory()->create();

        $this->putJson("/api/event-types/{$type->id}", ['name' => '', 'icon' => 'x', 'color' => 'pink', 'sort_order' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'icon', 'color', 'sort_order']);
    }

    public function test_update_returns_422_when_not_found(): void
    {
        $this->authenticateAs();

        $this->putJson('/api/event-types/999', ['name' => 'X'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tipo de evento não encontrado!');
    }

    public function test_master_deletes_type_without_events(): void
    {
        $this->authenticateAs();
        $type = GameEventType::factory()->create();

        $this->deleteJson("/api/event-types/{$type->id}")->assertNoContent();

        $this->assertDatabaseMissing('game_event_types', ['id' => $type->id]);
    }

    public function test_type_in_use_cannot_be_deleted(): void
    {
        $this->authenticateAs();
        GameEvent::factory()->yellowCard()->create();
        $yellow = GameEventType::where('slug', 'yellow_card')->firstOrFail();

        $this->deleteJson("/api/event-types/{$yellow->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tipo em uso; inative-o.');

        $this->assertDatabaseHas('game_event_types', ['id' => $yellow->id]);
    }

    public function test_delete_returns_422_when_not_found(): void
    {
        $this->authenticateAs();

        $this->deleteJson('/api/event-types/999')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tipo de evento não encontrado!');
    }
}
