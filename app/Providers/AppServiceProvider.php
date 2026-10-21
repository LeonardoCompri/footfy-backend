<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Master tem acesso total: libera qualquer ability, inclusive o middleware
        // `permission:` do Spatie (que checa via Gate), mesmo sem a permissão no banco.
        Gate::before(fn (User $user) => $user->isMaster() ? true : null);
    }
}
