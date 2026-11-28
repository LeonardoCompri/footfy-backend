<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        $this->hideSensitiveRequestDetails();

        $recordsEverything = $this->app->environment('local');

        Telescope::filter(fn (IncomingEntry $entry) => $recordsEverything || $this->isError($entry));
    }

    protected function authorization(): void
    {
        $this->gate();

        Telescope::auth(fn ($request) => Gate::check('viewTelescope', [$request->user()]));
    }

    protected function gate(): void
    {
        Gate::define('viewTelescope', fn (User $user) => $user->isMaster());
    }

    private function isError(IncomingEntry $entry): bool
    {
        return $entry->isReportableException()
            || $entry->isFailedRequest()
            || $entry->isFailedJob()
            || $entry->isSlowQuery()
            || $entry->isScheduledTask()
            || $entry->isLog()
            || $entry->hasMonitoredTag();
    }

    private function hideSensitiveRequestDetails(): void
    {
        $parameters = [
            ...config('audit.sensitive_keys'),
            ...config('audit.document_keys'),
        ];

        Telescope::hideRequestParameters($parameters);
        Telescope::hideResponseParameters($parameters);

        Telescope::hideRequestHeaders([
            'authorization',
            'cookie',
            'set-cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }
}
