<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\User;
use App\Providers\TelescopeServiceProvider;
use App\Services\ChampionshipService;
use App\Services\UserService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\Watchers;
use RuntimeException;
use Tests\TestCase;

class TelescopeEnabledTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCTION_WATCHERS = [
        Watchers\ExceptionWatcher::class,
        Watchers\RequestWatcher::class,
        Watchers\JobWatcher::class,
        Watchers\LogWatcher::class,
        Watchers\ScheduleWatcher::class,
        Watchers\QueryWatcher::class,
    ];

    private const USER_PAYLOAD = [
        'name' => 'Admin',
        'document' => '52998224725',
        'password' => 'senha-secreta',
        'role' => 'admin',
    ];

    private const SECRET_HEADERS = [
        'Authorization' => 'Bearer token-secreto',
        'Cookie' => 'sessao=cookie-secreto',
    ];

    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->afterBootstrapping(LoadConfiguration::class, fn (Application $app) => $this->enableTelescopeLikeProduction($app['config']));

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    private function enableTelescopeLikeProduction(Repository $config): void
    {
        foreach (array_keys($config->get('telescope.watchers')) as $watcher) {
            $key = is_array($config->get("telescope.watchers.{$watcher}"))
                ? "telescope.watchers.{$watcher}.enabled"
                : "telescope.watchers.{$watcher}";

            $config->set($key, in_array($watcher, self::PRODUCTION_WATCHERS, true));
        }

        $config->set('telescope.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        DB::table('telescope_entries')->delete();
    }

    protected function tearDown(): void
    {
        Telescope::stopRecording();
        Telescope::flushEntries();
        Telescope::$filterUsing = [];
        Telescope::$authUsing = null;
        Queue::createPayloadUsing(null);

        parent::tearDown();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function entries(string $type)
    {
        return DB::table('telescope_entries')->where('type', $type)->get()
            ->map(fn ($entry) => json_decode($entry->content, true));
    }

    public function test_master_opens_the_panel_with_cpf_and_password(): void
    {
        $master = $this->userWithRole('master');

        $this->withBasicAuth($master->document, 'password')->get('/telescope')->assertOk();
    }

    public function test_admin_is_forbidden(): void
    {
        $admin = $this->userWithRole('admin');

        $this->withBasicAuth($admin->document, 'password')->get('/telescope')->assertForbidden();
        $this->withBasicAuth($admin->document, 'password')->getJson('/telescope/telescope-api/requests')->assertForbidden();
    }

    public function test_wrong_password_or_no_credentials_is_unauthorized(): void
    {
        $master = $this->userWithRole('master');

        $this->get('/telescope')->assertUnauthorized();
        $this->withBasicAuth($master->document, 'errada')->get('/telescope')->assertUnauthorized();
    }

    public function test_handled_server_error_is_recorded(): void
    {
        Sanctum::actingAs($this->userWithRole('master'));
        $this->mock(ChampionshipService::class)->shouldReceive('index')->andThrow(new RuntimeException('falha simulada'));

        $this->getJson('/api/championships')->assertStatus(500);

        $requests = $this->entries(EntryType::REQUEST);
        $this->assertCount(1, $requests);
        $this->assertSame(500, $requests->first()['response_status']);

        $exceptions = $this->entries(EntryType::EXCEPTION);
        $this->assertCount(1, $exceptions);
        $this->assertSame('falha simulada', $exceptions->first()['message']);
        $this->assertSame(RuntimeException::class, $exceptions->first()['class']);

        $this->assertCount(0, $this->entries(EntryType::QUERY));
    }

    public function test_successful_request_is_not_recorded(): void
    {
        Sanctum::actingAs($this->userWithRole('master'));
        Championship::factory()->create();

        $this->getJson('/api/championships')->assertOk();

        $this->assertSame(0, DB::table('telescope_entries')->count());
    }

    public function test_client_error_is_not_recorded(): void
    {
        Sanctum::actingAs($this->userWithRole('master'));

        $this->postJson('/api/users', [])->assertUnprocessable();

        $this->assertSame(0, DB::table('telescope_entries')->count());
    }

    public function test_password_document_and_token_are_hidden(): void
    {
        Sanctum::actingAs($this->userWithRole('master'));
        $this->mock(UserService::class)->shouldReceive('store')->andThrow(new RuntimeException('falha simulada'));

        $this->withHeaders(self::SECRET_HEADERS)
            ->postJson('/api/users', self::USER_PAYLOAD)
            ->assertStatus(500);

        $request = $this->entries(EntryType::REQUEST)->first();
        $this->assertSame('********', $request['payload']['password']);
        $this->assertSame('********', $request['payload']['document']);
        $this->assertSame('Admin', $request['payload']['name']);
        $this->assertSame('********', $request['headers']['authorization']);
        $this->assertSame('********', $request['headers']['cookie']);

        $stored = DB::table('telescope_entries')->pluck('content')->implode('');
        $this->assertStringNotContainsString('52998224725', $stored);
        $this->assertStringNotContainsString('senha-secreta', $stored);
        $this->assertStringNotContainsString('token-secreto', $stored);
        $this->assertStringNotContainsString('cookie-secreto', $stored);
    }

    public function test_error_logs_are_recorded_and_lower_levels_are_not(): void
    {
        Sanctum::actingAs($this->userWithRole('master'));
        Championship::factory()->create();

        Log::warning('aviso');
        Log::error('erro sem exception');
        $this->getJson('/api/championships')->assertOk();

        $logs = $this->entries(EntryType::LOG);
        $this->assertCount(1, $logs);
        $this->assertSame('erro sem exception', $logs->first()['message']);
    }

    public function test_filter_keeps_only_errors_outside_local(): void
    {
        $keep = fn (IncomingEntry $entry) => collect(Telescope::$filterUsing)->every->__invoke($entry);

        $this->assertTrue($keep(IncomingEntry::make(['slow' => true])->type(EntryType::QUERY)));
        $this->assertFalse($keep(IncomingEntry::make(['slow' => false])->type(EntryType::QUERY)));
        $this->assertTrue($keep(IncomingEntry::make(['response_status' => 503])->type(EntryType::REQUEST)));
        $this->assertFalse($keep(IncomingEntry::make(['response_status' => 404])->type(EntryType::REQUEST)));
        $this->assertTrue($keep(IncomingEntry::make(['status' => 'failed'])->type(EntryType::JOB)));
        $this->assertFalse($keep(IncomingEntry::make(['status' => 'pending'])->type(EntryType::JOB)));
        $this->assertTrue($keep(IncomingEntry::make([])->type(EntryType::SCHEDULED_TASK)));
        $this->assertTrue($keep(IncomingEntry::make([])->type(EntryType::LOG)));
        $this->assertFalse($keep(IncomingEntry::make([])->type(EntryType::CACHE)));
    }

    public function test_local_records_everything(): void
    {
        $this->app['env'] = 'local';
        Telescope::$filterUsing = [];
        (new TelescopeServiceProvider($this->app))->register();

        $this->assertTrue(collect(Telescope::$filterUsing)->every->__invoke(IncomingEntry::make([])->type(EntryType::CACHE)));
    }
}
