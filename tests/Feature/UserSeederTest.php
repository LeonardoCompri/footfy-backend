<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class UserSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        config(['footfy.seed.password' => null]);
    }

    public static function publishedEnvironments(): array
    {
        return [
            'staging' => ['staging'],
            'production' => ['production'],
        ];
    }

    #[DataProvider('publishedEnvironments')]
    public function test_fails_without_seed_password_outside_local_and_testing(string $environment): void
    {
        $this->app['env'] = $environment;

        try {
            $this->runUserSeeder();
            $this->fail('O seed deveria falhar sem SEED_PASSWORD.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('SEED_PASSWORD', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_fails_with_empty_seed_password_outside_local_and_testing(): void
    {
        $this->app['env'] = 'staging';
        config(['footfy.seed.password' => '']);

        $this->expectException(RuntimeException::class);

        $this->runUserSeeder();
    }

    #[DataProvider('publishedEnvironments')]
    public function test_uses_seed_password_outside_local_and_testing(string $environment): void
    {
        $this->app['env'] = $environment;
        config(['footfy.seed.password' => 'Senha-Forte-Do-Staging-2026']);

        $this->runUserSeeder();

        $this->assertSeededPassword('Senha-Forte-Do-Staging-2026');
        $this->assertSeededPasswordIsNot('123456');
    }

    public static function localEnvironments(): array
    {
        return [
            'local' => ['local'],
            'testing' => ['testing'],
        ];
    }

    #[DataProvider('localEnvironments')]
    public function test_uses_default_password_in_local_and_testing_without_seed_password(string $environment): void
    {
        $this->app['env'] = $environment;

        $this->runUserSeeder();

        $this->assertSeededPassword('123456');
    }

    public function test_uses_seed_password_in_testing_when_defined(): void
    {
        config(['footfy.seed.password' => 'outra-senha']);

        $this->runUserSeeder();

        $this->assertSeededPassword('outra-senha');
    }

    public function test_seeded_users_log_in_with_the_seed_password(): void
    {
        $this->app['env'] = 'staging';
        config(['footfy.seed.password' => 'Senha-Forte-Do-Staging-2026']);
        $this->runUserSeeder();
        $this->app['env'] = 'testing';

        $this->postJson('/api/login', ['document' => '12345678901', 'password' => '123456'])
            ->assertStatus(401);

        $this->postJson('/api/login', ['document' => '12345678901', 'password' => 'Senha-Forte-Do-Staging-2026'])
            ->assertOk();
    }

    private function runUserSeeder(): void
    {
        $this->app->make(UserSeeder::class)->run();
    }

    private function assertSeededPassword(string $password): void
    {
        $users = User::all();

        $this->assertCount(2, $users);
        $this->assertTrue($users->every(fn (User $user) => Hash::check($password, $user->password)));
    }

    private function assertSeededPasswordIsNot(string $password): void
    {
        $this->assertTrue(User::all()->every(fn (User $user) => ! Hash::check($password, $user->password)));
    }
}
