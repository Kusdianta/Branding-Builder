<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class VaultServiceProvider extends ServiceProvider
{
    private const VAULT_FILENAME = 'branding-builder.json';

    private const SHARED_FILENAME = '_shared.json';

    /**
     * Map of vault keys to dotted config paths.
     * Tolerates the legacy "ANTHROPHIC_API_KEY" typo present in dev vault.
     */
    private const KEY_MAP = [
        'ANTHROPIC_API_KEY' => 'services.anthropic.key',
        'ANTHROPHIC_API_KEY' => 'services.anthropic.key',
        'GOOGLE_MAPS_API_KEY' => 'services.google.maps_api_key',
    ];

    public function register(): void
    {
        $vault = $this->readVault();

        foreach (self::KEY_MAP as $vaultKey => $configPath) {
            if (! array_key_exists($vaultKey, $vault)) {
                continue;
            }

            $value = $vault[$vaultKey];
            if ($value === null || $value === '') {
                continue;
            }

            config([$configPath => $value]);
        }

        $this->loadSharedVault();
    }

    /**
     * Read workspace-shared vault (vault/_shared.json) and merge worker.* into
     * services.nema_worker.* so NemaWorkerServiceProvider can resolve the client.
     * Values in _shared.json are stored plaintext by design (shared across spokes
     * that may not share APP_KEY).
     */
    private function loadSharedVault(): void
    {
        $candidates = [
            '/run/secrets/_shared.json',
            base_path('../vault/'.self::SHARED_FILENAME),
        ];

        foreach ($candidates as $path) {
            if (! file_exists($path)) {
                continue;
            }

            $raw = @file_get_contents($path);
            if ($raw === false) {
                continue;
            }

            try {
                /** @var array<string,mixed> $data */
                $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                Log::error('[branding-builder] vault/_shared.json is not valid JSON', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            $worker = (array) ($data['worker'] ?? []);
            if ($worker !== []) {
                config([
                    'services.nema_worker.url'     => $worker['url']     ?? null,
                    'services.nema_worker.api_key' => $worker['api_key'] ?? null,
                    'services.nema_worker.timeout' => (float) ($worker['timeout'] ?? 10.0),
                ]);
            }

            // Hub block — Phase 7-B credentials fetch + status callbacks.
            // `inbound_api_key` is the SAME bearer the worker uses for callbacks;
            // sharing it lets branding-builder reuse the existing hub.inbound
            // middleware with zero Hub-side changes.
            $hub = (array) ($data['hub'] ?? []);
            if ($hub !== []) {
                if (! empty($hub['url'])) {
                    config(['services.hub.url' => (string) $hub['url']]);
                }
                if (array_key_exists('inbound_api_key', $hub)) {
                    config(['services.hub.inbound_api_key' => (string) $hub['inbound_api_key']]);
                }
                if (isset($hub['timeout'])) {
                    config(['services.hub.timeout' => (float) $hub['timeout']]);
                }
            }

            // Analysis-model override (Phase 7-B). Falls through to scorer model
            // when absent — keeps the door open for swapping to opus-4-7 later
            // without a code change.
            $analysis = (array) ($data['analysis'] ?? []);
            if (! empty($analysis['model'])) {
                config(['services.anthropic.model_analysis' => (string) $analysis['model']]);
            }

            return;
        }

        Log::debug('[branding-builder] vault/_shared.json not found; worker config will be empty.');
    }

    /** @return array<string,mixed> */
    private function readVault(): array
    {
        $candidates = [
            '/run/secrets/vault.json',
            base_path('../vault/'.self::VAULT_FILENAME),
        ];

        foreach ($candidates as $path) {
            if (! is_string($path) || ! file_exists($path)) {
                continue;
            }

            $raw = @file_get_contents($path);
            if ($raw === false) {
                continue;
            }

            $data = json_decode($raw, true);
            if (! is_array($data)) {
                continue;
            }

            return $this->decryptValues($data);
        }

        Log::debug('Branding-builder vault not found; falling back to env-driven config.');

        return [];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function decryptValues(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (! is_string($value)) {
                $out[$key] = $value;

                continue;
            }

            try {
                $out[$key] = Crypt::decryptString($value);
            } catch (DecryptException) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
