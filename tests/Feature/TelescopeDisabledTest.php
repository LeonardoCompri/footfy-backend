<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Telescope\Watchers\QueryWatcher;
use Tests\TestCase;

class TelescopeDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_telescope_is_disabled_in_tests(): void
    {
        $this->assertFalse((bool) config('telescope.enabled'));
        $this->assertFalse(Gate::has('viewTelescope'));
    }

    public function test_telescope_and_every_watcher_are_disabled_without_variables(): void
    {
        $config = $this->withoutTelescopeVariables(fn () => require config_path('telescope.php'));

        $this->assertFalse($config['enabled']);
        $this->assertSame(500, $config['watchers'][QueryWatcher::class]['slow']);

        foreach ($config['watchers'] as $watcher => $options) {
            $enabled = is_array($options) ? $options['enabled'] : $options;

            $this->assertFalse($enabled, $watcher);
        }
    }

    private function withoutTelescopeVariables(callable $callback): mixed
    {
        $saved = [];

        foreach ([...array_keys($_ENV), ...array_keys($_SERVER), ...array_keys(getenv())] as $name) {
            if (str_starts_with($name, 'TELESCOPE_') && ! array_key_exists($name, $saved)) {
                $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);
            }
        }

        try {
            return $callback();
        } finally {
            foreach ($saved as $name => [$env, $server, $put]) {
                if ($env !== null) {
                    $_ENV[$name] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
                if ($put !== false) {
                    putenv("{$name}={$put}");
                }
            }
        }
    }

    public function test_panel_is_not_exposed_when_disabled(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $master = User::factory()->create();
        $master->assignRole('master');

        $this->withBasicAuth($master->document, 'password')->get('/telescope')->assertNotFound();
        $this->get('/telescope/telescope-api/requests')->assertNotFound();
    }

    public function test_tables_exist_even_when_disabled(): void
    {
        $this->assertTrue(Schema::hasTable('telescope_entries'));
        $this->assertTrue(Schema::hasTable('telescope_entries_tags'));
        $this->assertTrue(Schema::hasTable('telescope_monitoring'));
    }

    public function test_prune_is_scheduled_daily_with_the_configured_hours(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains($event->command, 'telescope:prune'));

        $this->assertCount(1, $events);
        $this->assertStringContainsString('--hours=48', $events->first()->command);
        $this->assertSame('0 0 * * *', $events->first()->expression);
    }

    public function test_schedule_list_shows_the_prune(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('telescope:prune --hours=48')
            ->assertSuccessful();
    }

    public function test_prune_runs_when_disabled(): void
    {
        $this->artisan('telescope:prune', ['--hours' => 48])->assertSuccessful();
    }
}
