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
