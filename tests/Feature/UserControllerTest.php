<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Role::create(['name' => 'master']);
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'coach']);
        Role::create(['name' => 'player']);
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

    private function validUserPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Novo Usuário',
            'email' => 'novo@footfy.com',
            'document' => '98765432100',
            'password' => '123456',
            'role' => 'admin',
        ], $overrides);
    }

    // ── Auth e controle de acesso ─────────────────────────────────────────────

    public function test_guest_cannot_list_users(): void
    {
        $response = $this->getJson('/api/users');

        $response->assertStatus(401);
    }

    public function test_admin_cannot_list_users(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->getJson('/api/users');

        $response->assertForbidden();
    }

    public function test_admin_cannot_create_user(): void
    {
        $this->authenticateAsAdmin();

        $response = $this->postJson('/api/users', $this->validUserPayload());

        $response->assertForbidden();
    }

    // ── Master: CRUD de usuários ──────────────────────────────────────────────

    public function test_master_can_list_users(): void
    {
        $this->authenticate();
        User::factory()->count(2)->create();

        $response = $this->getJson('/api/users');

        // +1 porque o próprio master também é listado
        $response->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_master_can_create_user_with_admin_role(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/users', $this->validUserPayload(['role' => 'admin']));

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Novo Usuário')
            ->assertJsonPath('data.roles.0', 'admin');

        $this->assertDatabaseHas('users', ['document' => '98765432100']);
    }

    public function test_master_can_create_user_with_master_role(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/users', $this->validUserPayload(['role' => 'master']));

        $response->assertCreated()->assertJsonPath('data.roles.0', 'master');
    }

    public function test_master_can_show_user(): void
    {
        $this->authenticate();
        $target = User::factory()->create();

        $response = $this->getJson("/api/users/{$target->id}");

        $response->assertOk()->assertJsonPath('data.id', $target->id);
    }

    public function test_master_can_update_user_name(): void
    {
        $this->authenticate();
        $target = User::factory()->create();

        $response = $this->putJson("/api/users/{$target->id}", ['name' => 'Nome Atualizado']);

        $response->assertOk()->assertJsonPath('data.name', 'Nome Atualizado');
        $this->assertDatabaseHas('users', ['id' => $target->id, 'name' => 'Nome Atualizado']);
    }

    public function test_master_can_update_user_role(): void
    {
        $this->authenticate();
        $target = User::factory()->create();
        $target->assignRole('admin');

        $response = $this->putJson("/api/users/{$target->id}", ['role' => 'coach']);

        $response->assertOk();
        $this->assertTrue($target->fresh()->hasRole('coach'));
        $this->assertFalse($target->fresh()->hasRole('admin'));
    }

    public function test_master_can_delete_user(): void
    {
        $this->authenticate();
        $target = User::factory()->create();

        $response = $this->deleteJson("/api/users/{$target->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    // ── Validações de criação ─────────────────────────────────────────────────

    public function test_store_fails_without_name(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/users', $this->validUserPayload(['name' => '']));

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_store_fails_without_password(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/users', $this->validUserPayload(['password' => '']));

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_store_fails_with_invalid_role(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/users', $this->validUserPayload(['role' => 'superuser']));

        $response->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_store_fails_with_document_shorter_than_11_digits(): void
    {
        $this->authenticate();

        $response = $this->postJson('/api/users', $this->validUserPayload(['document' => '12345']));

        $response->assertStatus(422)->assertJsonValidationErrors('document');
    }

    public function test_store_fails_with_duplicate_document(): void
    {
        $this->authenticate();
        User::factory()->create(['document' => '11122233344']);

        $response = $this->postJson('/api/users', $this->validUserPayload(['document' => '11122233344']));

        $response->assertStatus(422)->assertJsonValidationErrors('document');
    }

    public function test_store_fails_with_duplicate_email(): void
    {
        $this->authenticate();
        User::factory()->create(['email' => 'existing@footfy.com']);

        $response = $this->postJson('/api/users', $this->validUserPayload(['email' => 'existing@footfy.com']));

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_show_returns_error_when_user_not_found(): void
    {
        $this->authenticate();

        $response = $this->getJson('/api/users/999');

        $response->assertStatus(422)->assertJson(['message' => 'Usuário não encontrado!']);
    }

    // ── Associação de ligas ────────────────────────────────────────────────────

    public function test_master_can_sync_leagues_to_admin(): void
    {
        $this->authenticate();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $league1 = League::factory()->create();
        $league2 = League::factory()->create();

        $response = $this->postJson("/api/users/{$admin->id}/leagues", [
            'league_ids' => [$league1->id, $league2->id],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('user_leagues', ['user_id' => $admin->id, 'league_id' => $league1->id]);
        $this->assertDatabaseHas('user_leagues', ['user_id' => $admin->id, 'league_id' => $league2->id]);
    }

    public function test_master_can_remove_league_from_admin(): void
    {
        $this->authenticate();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $league = League::factory()->create();
        $admin->leagues()->attach($league->id);

        $response = $this->deleteJson("/api/users/{$admin->id}/leagues/{$league->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('user_leagues', [
            'user_id' => $admin->id,
            'league_id' => $league->id,
        ]);
    }

    public function test_sync_leagues_fails_with_invalid_league_id(): void
    {
        $this->authenticate();
        $admin = User::factory()->create();

        $response = $this->postJson("/api/users/{$admin->id}/leagues", [
            'league_ids' => [9999],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('league_ids.0');
    }

    public function test_sync_leagues_fails_without_league_ids(): void
    {
        $this->authenticate();
        $admin = User::factory()->create();

        $response = $this->postJson("/api/users/{$admin->id}/leagues", []);

        $response->assertStatus(422)->assertJsonValidationErrors('league_ids');
    }

    public function test_admin_cannot_sync_leagues(): void
    {
        $this->authenticateAsAdmin();
        $target = User::factory()->create();
        $league = League::factory()->create();

        $response = $this->postJson("/api/users/{$target->id}/leagues", [
            'league_ids' => [$league->id],
        ]);

        $response->assertForbidden();
    }
}
