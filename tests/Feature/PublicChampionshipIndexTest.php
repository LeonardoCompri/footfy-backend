<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\League;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicChampionshipIndexTest extends TestCase
{
    use RefreshDatabase;

    private function championship(string $name, string $createdAt): Championship
    {
        $championship = Championship::factory()->create(['name' => $name]);
        DB::table('championships')->where('id', $championship->id)->update(['created_at' => $createdAt]);

        return $championship;
    }

    private function names(array $data): array
    {
        return array_column($data, 'name');
    }

    public function test_without_page_keeps_the_plain_list_in_the_previous_order(): void
    {
        $first = $this->championship('Copa Antiga', '2026-01-01 10:00:00');
        $second = $this->championship('Copa Nova', '2026-06-01 10:00:00');
        League::factory()->count(2)->create(['championship_id' => $second->id]);

        $response = $this->getJson('/api/public/championships');

        $response->assertOk()
            ->assertJsonMissingPath('meta')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0', ['id' => $first->id, 'name' => 'Copa Antiga', 'image' => null, 'leagues_count' => 0])
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.1.leagues_count', 2);
        $this->assertArrayNotHasKey('leagues', $response->json('data.1'));
    }

    public function test_with_page_orders_by_most_recent_first(): void
    {
        $this->championship('Copa 2024', '2024-01-01 10:00:00');
        $this->championship('Copa 2026', '2026-01-01 10:00:00');
        $this->championship('Copa 2025', '2025-01-01 10:00:00');

        $response = $this->getJson('/api/public/championships?page=1&per_page=2');

        $response->assertOk()
            ->assertJsonPath('meta', ['current_page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);
        $this->assertSame(['Copa 2026', 'Copa 2025'], $this->names($response->json('data')));

        $this->assertSame(['Copa 2024'], $this->names($this->getJson('/api/public/championships?page=2&per_page=2')->json('data')));
    }

    public function test_page_without_per_page_uses_the_configured_default(): void
    {
        Championship::factory()->count(21)->create();

        $this->getJson('/api/public/championships?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 21);
    }

    public function test_sort_and_order_are_applied(): void
    {
        $this->championship('Brasileirão', '2024-01-01 10:00:00');
        $this->championship('Amador', '2026-01-01 10:00:00');
        $this->championship('Copa', '2025-01-01 10:00:00');

        $this->assertSame(
            ['Amador', 'Brasileirão', 'Copa'],
            $this->names($this->getJson('/api/public/championships?page=1&sort=name')->json('data')),
        );
        $this->assertSame(
            ['Brasileirão', 'Copa', 'Amador'],
            $this->names($this->getJson('/api/public/championships?sort=created_at')->json('data')),
        );
        $this->assertSame(
            ['Amador', 'Copa', 'Brasileirão'],
            $this->names($this->getJson('/api/public/championships?order=desc')->json('data')),
        );
    }

    public function test_search_by_name_with_and_without_page(): void
    {
        $this->championship('Campeonato Paulista', '2026-01-01 10:00:00');
        $this->championship('Copa do Brasil', '2026-02-01 10:00:00');
        $this->championship('Copa Paulista Sub-15', '2026-03-01 10:00:00');

        $paged = $this->getJson('/api/public/championships?page=1&search=paulista');
        $paged->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame(['Copa Paulista Sub-15', 'Campeonato Paulista'], $this->names($paged->json('data')));

        $plain = $this->getJson('/api/public/championships?search=brasil');
        $plain->assertOk()->assertJsonMissingPath('meta');
        $this->assertSame(['Copa do Brasil'], $this->names($plain->json('data')));
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_listing_parameters_return_422(string $query, string $field): void
    {
        $this->getJson("/api/public/championships?{$query}")
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    public static function invalidQueryProvider(): array
    {
        return [
            'per_page acima do máximo' => ['page=1&per_page=101', 'per_page'],
            'per_page sem página também é validado' => ['per_page=500', 'per_page'],
            'coluna fora da lista' => ['sort=image', 'sort'],
            'direção inválida' => ['order=random', 'order'],
            'página não numérica' => ['page=abc', 'page'],
            'busca longa demais' => ['search='.str_repeat('a', 101), 'search'],
        ];
    }

    public function test_admin_index_is_unchanged(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $user = User::factory()->create();
        $user->assignRole('admin');
        Sanctum::actingAs($user);
        $championship = Championship::factory()->create();
        League::factory()->create(['championship_id' => $championship->id]);

        $response = $this->getJson('/api/championships?page=1');

        $response->assertOk()->assertJsonMissingPath('meta')->assertJsonMissingPath('data.0.leagues_count');
    }
}
