<?php

namespace App\Jobs;

use App\Services\ClaudeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Exception;

class GenerateBrandKit implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;
    public int $tries   = 1;

    public function __construct(
        private string $token,
        private array $data,
    ) {}

    public function handle(): void
    {
        try {
            $claude   = new ClaudeService();
            $brandKit = $claude->generateBrandKit($this->data);

            Cache::put("brandkit:{$this->token}", [
                'status'     => 'done',
                'brand_kit'  => $brandKit,
                'brand_data' => $this->data,
            ], now()->addHours(2));
        } catch (Exception $e) {
            Cache::put("brandkit:{$this->token}", [
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], now()->addMinutes(10));
        }
    }
}
