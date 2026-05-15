<?php

declare(strict_types=1);

namespace App\Services\Recommendation;

use Anthropic\Client;
use App\Exceptions\MissingAnthropicKeyException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 9 BB37: shared scaffolding for the three apikprimadya-style
 * generators (RecommendationGenerator, QuickWinsGenerator,
 * CompetitivePositioningGenerator).
 *
 * Centralises the boring bits — API key resolution, Anthropic SDK
 * client construction, model selection, fence-stripping, JSON
 * decoding, exception swallowing — so the concrete generators stay
 * focused on prompt construction + result-shape transformation.
 *
 * Each concrete generator implements:
 *   - systemPrompt(): the apikprimadya-style instruction
 *   - userPrompt(BrandAudit): the audit-data context block
 *   - parseResponse(array): map LLM JSON to the column shape
 *   - fallbackPayload(): what to persist when the LLM call fails
 */
abstract class AbstractClaudeGenerator
{
    private const DEFAULT_MODEL = 'claude-sonnet-4-6';
    protected const MAX_TOKENS  = 2048;
    protected const TEMPERATURE = 0.4;

    protected Client $client;
    protected string $model;

    public function __construct()
    {
        $apiKey = (string) config('services.anthropic.key', '');
        if ($apiKey === '') {
            throw MissingAnthropicKeyException::create();
        }
        $this->client = new Client(apiKey: $apiKey);
        $this->model  = (string) config('services.anthropic.model_recommendation', config('services.anthropic.model', self::DEFAULT_MODEL));
    }

    /**
     * Run the LLM call for `$userPrompt` under `$systemPrompt`. Returns
     * decoded JSON (array) on success, OR the fallback payload from
     * the concrete generator if anything fails (network, parse, schema).
     *
     * @return array<string,mixed>
     */
    protected function callAndParse(string $systemPrompt, string $userPrompt, string $callerLabel): array
    {
        try {
            $response = $this->client->messages->create(
                maxTokens:   static::MAX_TOKENS,
                messages:    [['role' => 'user', 'content' => $userPrompt]],
                model:       $this->model,
                system:      $systemPrompt,
                temperature: static::TEMPERATURE,
            );
        } catch (Throwable $e) {
            Log::warning("{$callerLabel}: LLM call failed", ['error' => $e->getMessage()]);
            return $this->fallbackPayload();
        }

        $raw = '';
        foreach ((array) ($response->content ?? []) as $block) {
            $text = $block->text ?? null;
            if (is_string($text) && $text !== '') {
                $raw = $text;
                break;
            }
        }

        if ($raw === '') {
            Log::warning("{$callerLabel}: empty LLM response");
            return $this->fallbackPayload();
        }

        $cleaned = $this->stripFences($raw);
        $decoded = json_decode($cleaned, true);

        if (! is_array($decoded)) {
            Log::warning("{$callerLabel}: LLM returned non-JSON", ['preview' => substr($raw, 0, 200)]);
            return $this->fallbackPayload();
        }

        try {
            return $this->parseResponse($decoded);
        } catch (Throwable $e) {
            Log::warning("{$callerLabel}: response parse failed", ['error' => $e->getMessage()]);
            return $this->fallbackPayload();
        }
    }

    private function stripFences(string $raw): string
    {
        $trimmed = trim($raw);
        if (! str_starts_with($trimmed, '```')) {
            return $trimmed;
        }
        $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/```\s*$/', '', $trimmed) ?? $trimmed;
        return trim($trimmed);
    }

    /**
     * @param  array<string,mixed>  $decoded
     * @return array<string,mixed>
     */
    abstract protected function parseResponse(array $decoded): array;

    /**
     * @return array<string,mixed>
     */
    abstract protected function fallbackPayload(): array;
}
