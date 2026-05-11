<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\HubCredentialsClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HubCredentialsClientTest extends TestCase
{
    /** @var list<Request> */
    private array $sentRequests;

    private function makeClient(MockHandler $mock): HubCredentialsClient
    {
        $this->sentRequests = [];
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->sentRequests));

        $http = new Client([
            'base_uri'    => 'http://hub.test/',
            'handler'     => $stack,
            'http_errors' => false,
        ]);

        return new HubCredentialsClient(
            baseUrl: 'http://hub.test',
            apiKey: 'TEST_KEY',
            timeoutSeconds: 5.0,
            http: $http,
        );
    }

    #[Test]
    public function it_returns_decoded_credential_on_200(): void
    {
        $payload = [
            'id'              => '01j_TEST_ULID',
            'platform'        => 'instagram',
            'username'        => 'lessworry.id',
            'password'        => 'secret',
            'session_cookies' => [['name' => 'sessionid', 'value' => 'abc']],
        ];
        $client = $this->makeClient(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($payload)),
        ]));

        $result = $client->getNextCredential('instagram');

        $this->assertSame($payload, $result);
        $this->assertCount(1, $this->sentRequests);
        $req = $this->sentRequests[0]['request'];
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('Bearer TEST_KEY', $req->getHeaderLine('Authorization'));
        $this->assertStringContainsString('/api/internal/credentials/instagram/next', $req->getUri()->getPath());
    }

    #[Test]
    public function it_returns_null_on_404(): void
    {
        $client = $this->makeClient(new MockHandler([
            new Response(404, ['Content-Type' => 'application/json'], '{"error":"no_healthy_credentials"}'),
        ]));

        $this->assertNull($client->getNextCredential('instagram'));
    }

    #[Test]
    public function it_throws_on_5xx(): void
    {
        $client = $this->makeClient(new MockHandler([
            new Response(503, ['Content-Type' => 'application/json'], '{"error":"upstream"}'),
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 503/');

        $client->getNextCredential('instagram');
    }

    #[Test]
    public function it_throws_on_invalid_json(): void
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '<<not json>>'),
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid JSON/');

        $client->getNextCredential('instagram');
    }

    #[Test]
    public function it_posts_status_update_with_bearer_auth(): void
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"id":"01j_TEST","status":"requires_2fa"}'),
        ]));

        $client->reportCredentialStatus('01j_TEST', 'requires_2fa', 'login_wall_hit during profile audit');

        $this->assertCount(1, $this->sentRequests);
        $req = $this->sentRequests[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('Bearer TEST_KEY', $req->getHeaderLine('Authorization'));
        $this->assertStringContainsString('/api/internal/credentials/01j_TEST/status', $req->getUri()->getPath());
        /** @var array<string,mixed> $body */
        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame('requires_2fa', $body['status']);
        $this->assertSame('login_wall_hit during profile audit', $body['last_failure_reason']);
    }

    #[Test]
    public function it_omits_failure_reason_when_null(): void
    {
        $client = $this->makeClient(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"id":"x","status":"healthy"}'),
        ]));

        $client->reportCredentialStatus('01j_TEST', 'healthy', null);

        /** @var array<string,mixed> $body */
        $body = json_decode((string) $this->sentRequests[0]['request']->getBody(), true);
        $this->assertSame('healthy', $body['status']);
        $this->assertArrayNotHasKey('last_failure_reason', $body);
    }

    #[Test]
    public function it_throws_on_status_update_failure(): void
    {
        $client = $this->makeClient(new MockHandler([
            new Response(422, ['Content-Type' => 'application/json'], '{"errors":{"status":["invalid"]}}'),
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 422/');

        $client->reportCredentialStatus('01j_TEST', 'definitely_invalid_value');
    }
}
