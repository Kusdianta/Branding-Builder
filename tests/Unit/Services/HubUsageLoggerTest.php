<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\HubUsageLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB66 — HubUsageLogger fire-and-forget contract coverage.
 */
class HubUsageLoggerTest extends TestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface, options: array<string,mixed>}> */
    private array $history = [];

    /** @param list<Response|\Throwable> $responses */
    private function makeLogger(array $responses = [new Response(201, [], '{"id":"01x"}')]): HubUsageLogger
    {
        $this->history = [];
        $mock          = new MockHandler($responses);
        $stack         = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $http = new Client([
            'base_uri'    => 'http://hub.test/',
            'handler'     => $stack,
            'http_errors' => false,
        ]);

        return new HubUsageLogger(
            baseUrl: 'http://hub.test',
            apiKey: 'test-key',
            http: $http,
        );
    }

    #[Test]
    public function logs_claude_call_with_token_payload(): void
    {
        $logger = $this->makeLogger();
        $logger->logClaude(
            model: 'claude-sonnet-4-6',
            operation: 'analyze_instagram_profile',
            inputTokens: 1500,
            outputTokens: 800,
            auditId: '01abc',
            metadata: ['stop_reason' => 'end_turn'],
        );

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('anthropic_claude', $body['service']);
        $this->assertSame('claude-sonnet-4-6', $body['model']);
        $this->assertSame(1500, $body['input_tokens']);
        $this->assertSame(800, $body['output_tokens']);
        $this->assertSame('branding-builder', $body['spoke']);
        $this->assertSame('01abc', $body['audit_id']);
        $this->assertSame('end_turn', $body['metadata']['stop_reason']);
        $this->assertStringContainsString('Bearer test-key', $request->getHeaderLine('Authorization'));
    }

    #[Test]
    public function logs_google_call_with_request_count(): void
    {
        $logger = $this->makeLogger();
        $logger->logGoogle(
            sku: 'place-details-essentials',
            operation: 'place_details_fetch',
            requestCount: 2,
            auditId: '01abc',
        );

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('google_places', $body['service']);
        $this->assertSame('place-details-essentials', $body['model']);
        $this->assertSame(2, $body['request_count']);
    }

    #[Test]
    public function silently_swallows_transport_errors(): void
    {
        $logger = $this->makeLogger([
            new ConnectException('Hub unreachable', new Request('POST', 'api/internal/usage-logs')),
        ]);

        // No exception thrown — fire-and-forget contract.
        $logger->logClaude(
            model: 'claude-haiku-4-5',
            operation: 'validate',
            inputTokens: 100,
            outputTokens: 50,
        );

        $this->assertTrue(true);
    }

    #[Test]
    public function silently_swallows_5xx_responses(): void
    {
        $logger = $this->makeLogger([new Response(500, [], '{"error":"boom"}')]);

        $logger->logClaude(
            model: 'claude-opus-4-7',
            operation: 'score',
            inputTokens: 1000,
            outputTokens: 200,
        );

        $this->assertCount(1, $this->history);
    }

    #[Test]
    public function skips_post_when_api_key_empty(): void
    {
        // Empty API key = misconfigured Hub bridge; skip cleanly so
        // dev environments without Hub don't spam warnings.
        $logger = new HubUsageLogger(baseUrl: 'http://hub.test', apiKey: '');
        $logger->logClaude('claude-haiku-4-5', 'op', 1, 1);
        $this->assertTrue(true); // no exception
    }
}
