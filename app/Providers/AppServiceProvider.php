<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\HubCredentialsClient;
use App\Services\HubUsageLogger;
use App\View\Composers\MapsConfigComposer;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Volt\Volt;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Phase 7-B: HubCredentialsClient has primitive constructor params,
        // so Laravel cannot auto-resolve. Bind it as a singleton driven by
        // services.hub.* (populated by VaultServiceProvider from
        // vault/_shared.json).
        $this->app->singleton(HubCredentialsClient::class, static function (Application $app): HubCredentialsClient {
            return new HubCredentialsClient(
                baseUrl: (string) config('services.hub.url', 'http://nema-hub.test'),
                apiKey: (string) config('services.hub.inbound_api_key', ''),
                timeoutSeconds: (float) config('services.hub.timeout', 10.0),
            );
        });

        // BB66: HubUsageLogger — POSTs api_usage_log rows to Hub
        // (fire-and-forget; failures don't block the audit). Same shared
        // bearer as HubCredentialsClient.
        $this->app->singleton(HubUsageLogger::class, static function (Application $app): HubUsageLogger {
            return new HubUsageLogger(
                baseUrl: (string) config('services.hub.url', 'http://nema-hub.test'),
                apiKey: (string) config('services.hub.inbound_api_key', ''),
            );
        });
    }

    public function boot(): void
    {
        Volt::mount([
            resource_path('views/livewire'),
        ]);

        // BB83 — there is no Laravel 'login' route in branding-builder
        // (OAuth-only). Send unauthenticated users to /auth/google so the
        // auth middleware on /audits and friends does the right thing.
        Authenticate::redirectUsing(static function (): string {
            return route('auth.google.redirect');
        });

        // BB89 — scope the Places API key + country bias to the wizard
        // and its step partials only. Other views (admin, /audits history,
        // result/processing screens) don't need the key in scope.
        View::composer(
            ['livewire.brand-audit-wizard', 'livewire.audit-wizard.*'],
            MapsConfigComposer::class,
        );
    }
}
