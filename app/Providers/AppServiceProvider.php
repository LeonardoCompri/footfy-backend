<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app['config']->get('telescope.enabled')) {
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        Gate::before(fn (User $user) => $user->isMaster() ? true : null);

        $this->configureLoginRateLimiting();
    }

    private function configureLoginRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $document = $request->input('document');
            $cpf = is_string($document) ? preg_replace('/\D/', '', $document) : '';

            if ($cpf === '') {
                return Limit::none();
            }

            return Limit::perMinute(config('auth.login_throttle.per_minute'))
                ->by('login|'.$cpf)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Muitas tentativas de login. Aguarde um minuto e tente novamente.',
                ], 429, $headers));
        });
    }
}
