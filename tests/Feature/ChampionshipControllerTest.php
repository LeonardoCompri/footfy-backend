<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\League;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChampionshipControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Role::create(['name' => 'master']);
        Role::create(['name' => 'admin']);
    }

    private function authenticate(): User
    {
        $user = User::factory()->create();
        $user->assignRole('master');
        Sanctum::actingAs($user);

        return $user;
    }

    private function authenticateAsAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        return $user;
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function test_guest_cannot_list_championships(): void
    {
        $response = $this->getJson('/api/championships');

        $response->assertStatus(401);
    }

    // ── Index/show: qualquer autenticado ───────────────────────────────────────

    public function test_master_index_returns_all_championships(): void
    {
        $this->authenticate();
        Championship::factory()->count(2)->create();

        $response = $this->getJson('/api/championships');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_admin_can_list_championships(): void
    {
        $this->authenticateAsAdmin();
        Championship::factory()->count(2)->create();

        $response = $this->getJson('/api/championships');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_admin_can_show_championship(): void
    {
        $this->authenticateAsAdmin();
        $championship = Championship::factory()->create();

        $response = $this->getJson("/api/championships/{$championship->id}");

        $response->assertOk()->assertJsonPath('data.id', $championship->id);
    }

    public function test_show_returns_error_when_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/championships/999');

        $response->assertStatus(422)->assertJson(['message' => 'Campeonato não encontrado!']);
    }

    // ── Master: CRUD completo ────────────────────────────────────────────────

    public function test_master_can_create_championship(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/championships', ['name' => 'Campeonato Brasileiro']);

        $response->assertCreated()->assertJsonPath('data.name', 'Campeonato Brasileiro');
        $this->assertDatabaseHas('championships', ['name' => 'Campeonato Brasileiro']);
    }

    public function test_master_can_update_championship(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create(['name' => 'Antigo']);

        $response = $this->putJson("/api/championships/{$championship->id}", ['name' => 'Novo']);

        $response->assertOk()->assertJsonPath('data.name', 'Novo');
        $this->assertDatabaseHas('championships', ['id' => $championship->id, 'name' => 'Novo']);
    }

    public function test_master_can_delete_championship(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();

        $response = $this->deleteJson("/api/championships/{$championship->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('championships', ['id' => $championship->id]);
    }

    // ── Upload de imagem ─────────────────────────────────────────────────────

    public function test_master_can_create_championship_with_image(): void
    {
        Storage::fake('public');
        $this->authenticate();

        $response = $this->postJson('/api/championships', [
            'name' => 'Campeonato Brasileiro',
            'image' => UploadedFile::fake()->image('logo.jpg'),
        ]);

        $response->assertCreated();
        $championship = Championship::first();
        $this->assertNotNull($championship->image);
        Storage::disk('public')->assertExists($championship->image);
        $response->assertJsonPath('data.image', Storage::disk('public')->url($championship->image));
    }

    public function test_master_can_update_championship_replacing_image(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $oldPath = UploadedFile::fake()->image('old.jpg')->store('championships', 'public');
        $championship = Championship::factory()->create(['image' => $oldPath]);

        $response = $this->putJson("/api/championships/{$championship->id}", [
            'image' => UploadedFile::fake()->image('new.jpg'),
        ]);

        $response->assertOk();
        $championship->refresh();
        $this->assertNotEquals($oldPath, $championship->image);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($championship->image);
    }

    public function test_create_championship_without_image_keeps_it_null(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/championships', ['name' => 'Campeonato Brasileiro']);

        $response->assertCreated()->assertJsonPath('data.image', null);
        $this->assertDatabaseHas('championships', ['name' => 'Campeonato Brasileiro', 'image' => null]);
    }

    public function test_update_championship_without_image_keeps_existing_image(): void
    {
        Storage::fake('public');
        $this->authenticate();
        $path = UploadedFile::fake()->image('logo.jpg')->store('championships', 'public');
        $championship = Championship::factory()->create(['image' => $path]);

        $response = $this->putJson("/api/championships/{$championship->id}", ['name' => 'Novo Nome']);

        $response->assertOk();
        $championship->refresh();
        $this->assertEquals($path, $championship->image);
        Storage::disk('public')->assertExists($path);
    }

    // ── Validações ────────────────────────────────────────────────────────────

    public function test_store_fails_validation_without_name(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/championships', []);

        $response->assertStatus(422);
    }

    // ── Admin: sem acesso a store/update/destroy ────────────────────────────────

    public function test_admin_cannot_create_championship(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/championships', ['name' => 'Novo']);

        $response->assertForbidden();
    }

    public function test_admin_cannot_update_championship(): void
    {
        $championship = Championship::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->putJson("/api/championships/{$championship->id}", ['name' => 'X']);

        $response->assertForbidden();
    }

    public function test_admin_cannot_delete_championship(): void
    {
        $championship = Championship::factory()->create();
        $this->authenticateAsAdmin();

        $response = $this->deleteJson("/api/championships/{$championship->id}");

        $response->assertForbidden();
    }

    // ── Rotas públicas ────────────────────────────────────────────────────────

    public function test_guest_can_list_championships_via_public_route(): void
    {
        Championship::factory()->count(2)->create();

        $response = $this->getJson('/api/public/championships');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_guest_can_show_championship_via_public_route_with_leagues(): void
    {
        $championship = Championship::factory()->create();
        $league = League::factory()->create(['championship_id' => $championship->id]);

        $response = $this->getJson("/api/public/championships/{$championship->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $championship->id)
            ->assertJsonPath('data.leagues.0.id', $league->id);
    }

    public function test_guest_show_via_public_route_returns_error_when_not_found(): void
    {
        $response = $this->getJson('/api/public/championships/999');

        $response->assertStatus(422)->assertJson(['message' => 'Campeonato não encontrado!']);
    }

    // ── Estrutura de resposta (ChampionshipResource) ────────────────────────────

    public function test_public_index_response_does_not_include_leagues(): void
    {
        Championship::factory()->create();

        $response = $this->getJson('/api/public/championships');

        $response->assertOk();
        $this->assertArrayNotHasKey('leagues', $response->json('data.0'));
    }

    public function test_regular_show_response_does_not_include_leagues(): void
    {
        $this->authenticate();
        $championship = Championship::factory()->create();
        League::factory()->create(['championship_id' => $championship->id]);

        $response = $this->getJson("/api/championships/{$championship->id}");

        $response->assertOk();
        $this->assertArrayNotHasKey('leagues', $response->json('data'));
    }
}
