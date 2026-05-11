<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\HubCredentialsClient;
use Illuminate\Contracts\Foundation\Application;
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
    }

    public function boot(): void
    {
        Volt::mount([
            resource_path('views/livewire'),
        ]);
    }
}
