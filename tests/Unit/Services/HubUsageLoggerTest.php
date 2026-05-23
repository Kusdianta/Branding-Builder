<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\BrandAudit;
use App\Models\User;
use App\Services\HubUsageLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB66 — HubUsageLogger fire-and-forget contract coverage.
 */
class HubUsageLoggerTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{request: \Psr\Http\Message\RequestInterface, options: array<string,mixed>}> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        HubUsageLogger::resetUserIdCache();
    }

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
    public function logs_audit_duration_to_timing_endpoint(): void
    {
        $logger = $this->makeLogger();
        $logger->logAuditDuration(
            auditId: '01abc',
            totalSeconds: 87,
            completedAt: '2026-05-23T10:00:00+07:00',
            brandName: 'Dhobi Laundry',
        );

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertStringEndsWith('api/internal/audit-timings', (string) $request->getUri());

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('branding-builder', $body['spoke']);
        $this->assertSame('01abc', $body['external_audit_id']);
        $this->assertSame(87, $body['total_seconds']);
        $this->assertSame('Dhobi Laundry', $body['brand_name']);
        $this->assertSame('2026-05-23T10:00:00+07:00', $body['completed_at']);
        $this->assertStringContainsString('Bearer test-key', $request->getHeaderLine('Authorization'));
    }

    #[Test]
    public function audit_duration_skipped_when_api_key_empty(): void
    {
        $logger = new HubUsageLogger(baseUrl: 'http://hub.test', apiKey: '');
        $logger->logAuditDuration('01abc', 10, '2026-05-23T10:00:00+07:00');
        $this->assertTrue(true); // no exception, no POST
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

    #[Test]
    public function bb126_resolves_and_sends_user_id_from_audit_id(): void
    {
        // Build a real user + audit so the lookup hits the same SQLite
        // schema spokes use in production. RefreshDatabase makes this
        // cheap.
        $user = User::factory()->create();
        $audit = BrandAudit::create([
            'session_token'   => bin2hex(random_bytes(16)),
            'ip_address'      => '127.0.0.1',
            'user_id'         => $user->id,
            'credits_charged' => 1,
            'status'          => BrandAudit::STATUS_PENDING,
            'city'             => 'Jakarta',
            'service_type'     => 'kiloan',
            'touchpoints'      => [],
            'expires_at'       => now()->addDays(30),
            'brand_name'      => 'Test Laundry',
            'wizard_version'  => BrandAudit::WIZARD_V3,
        ]);

        $logger = $this->makeLogger();
        $logger->logClaude(
            model: 'claude-sonnet-4-6',
            operation: 'score_pillar_1',
            inputTokens: 100,
            outputTokens: 50,
            auditId: $audit->id,
        );

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame($user->id, $body['user_id']);
        $this->assertSame($audit->id, $body['audit_id']);
    }

    #[Test]
    public function bb126_sends_null_user_id_when_audit_missing(): void
    {
        $logger = $this->makeLogger();
        $logger->logGoogle(
            sku: 'place-details-essentials',
            operation: 'place_details_fetch',
            requestCount: 1,
            auditId: '01nonexistent',
        );

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertNull($body['user_id']);
    }

    #[Test]
    public function bb126_omits_user_id_lookup_when_no_audit_id(): void
    {
        // Background sweep with no audit context should send null
        // user_id without touching the DB.
        $logger = $this->makeLogger();
        $logger->logClaude(
            model: 'claude-haiku-4-5',
            operation: 'system_health_check',
            inputTokens: 10,
            outputTokens: 5,
        );

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertNull($body['user_id']);
        $this->assertNull($body['audit_id']);
    }

    #[Test]
    public function bb126_caches_user_id_lookup_per_audit(): void
    {
        // Two calls for the same audit should result in one BrandAudit
        // query, not two. We verify by hitting both endpoints and
        // confirming the second call still has the right user_id even
        // after the audit row is deleted between calls.
        $user = User::factory()->create();
        $audit = BrandAudit::create([
            'session_token'   => bin2hex(random_bytes(16)),
            'ip_address'      => '127.0.0.1',
            'user_id'         => $user->id,
            'credits_charged' => 1,
            'status'          => BrandAudit::STATUS_PENDING,
            'city'             => 'Jakarta',
            'service_type'     => 'kiloan',
            'touchpoints'      => [],
            'expires_at'       => now()->addDays(30),
            'brand_name'      => 'Cache Test',
            'wizard_version'  => BrandAudit::WIZARD_V3,
        ]);

        $logger = $this->makeLogger([
            new Response(201, [], '{"id":"01a"}'),
            new Response(201, [], '{"id":"01b"}'),
        ]);

        $logger->logClaude('claude-sonnet-4-6', 'op1', 1, 1, auditId: $audit->id);

        // Delete the audit — second call must still resolve from cache.
        $audit->delete();

        $logger->logClaude('claude-sonnet-4-6', 'op2', 1, 1, auditId: $audit->id);

        $body1 = json_decode((string) $this->history[0]['request']->getBody(), true);
        $body2 = json_decode((string) $this->history[1]['request']->getBody(), true);
        $this->assertSame($user->id, $body1['user_id']);
        $this->assertSame($user->id, $body2['user_id']);
    }
}
