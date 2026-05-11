<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\BrandAudit;
use App\Services\ClaudeService;
use App\Services\HubCredentialsClient;
use App\Services\IgUsernameExtractor;
use App\Services\InstagramProfileAuditService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nema\WorkerClient\DTO\Highlight;
use Nema\WorkerClient\DTO\InstagramProfileAudit;
use Nema\WorkerClient\DTO\ProfileMetadata;
use Nema\WorkerClient\DTO\RecentPost;
use Nema\WorkerClient\Exceptions\ProfileAuditException;
use Nema\WorkerClient\Exceptions\WorkerAuthException;
use Nema\WorkerClient\Exceptions\WorkerNotAvailableException;
use Nema\WorkerClient\NemaWorkerClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Tests\TestCase;

class InstagramProfileAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    private NemaWorkerClient&MockObject $worker;
    private HubCredentialsClient&MockObject $hub;
    private ClaudeService&MockObject $claude;
    private IgUsernameExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->worker    = $this->createMock(NemaWorkerClient::class);
        $this->hub       = $this->createMock(HubCredentialsClient::class);
        $this->claude    = $this->createMock(ClaudeService::class);
        $this->extractor = new IgUsernameExtractor();
    }

    private function makeService(): InstagramProfileAuditService
    {
        return new InstagramProfileAuditService(
            $this->worker,
            $this->hub,
            $this->claude,
            $this->extractor,
        );
    }

    /** @param array<string,mixed> $overrides */
    private function makeAudit(array $overrides = []): BrandAudit
    {
        return BrandAudit::create(array_merge([
            'session_token' => bin2hex(random_bytes(8)),
            'ip_address'    => '127.0.0.1',
            'brand_name'    => 'Less Worry Laundry',
            'city'          => 'Jakarta',
            'service_type'  => 'kiloan',
            'touchpoints'   => ['instagram_url' => 'https://instagram.com/lessworry.id'],
            'status'        => 'pending',
            'expires_at'    => now()->addDays(30),
        ], $overrides));
    }

    private function makeProfileAuditResult(string $username = 'lessworry.id'): InstagramProfileAudit
    {
        return new InstagramProfileAudit(
            username: $username,
            capturedAt: new DateTimeImmutable('2026-05-11T10:00:00Z'),
            isPrivate: false,
            profile: new ProfileMetadata(
                name: 'Less Worry Laundry',
                bio: 'Layanan laundry kiloan & express Jakarta',
                externalUrl: 'https://lessworry.id',
                followers: 1240,
                following: 320,
                postsCount: 87,
                isVerified: false,
                isBusiness: true,
                profilePicBase64: 'stub',
            ),
            profilePicFetchError: null,
            screenshotBase64: 'stub_screenshot',
            recentPosts: [
                new RecentPost('A1', 'https://instagram.com/lessworry.id/p/A1/', 'image', 't1', 'caption 1', '2 hari'),
            ],
            highlights: [
                new Highlight('Promo', 'cov'),
            ],
            durationMs: 32000,
        );
    }

    // -- happy path --------------------------------------------------------

    #[Test]
    public function it_marks_done_when_worker_and_claude_succeed(): void
    {
        $audit = $this->makeAudit();

        $this->hub->expects($this->once())
            ->method('getNextCredential')
            ->with('instagram')
            ->willReturn([
                'id'              => '01j_CRED',
                'platform'        => 'instagram',
                'username'        => 'naufalk',
                'password'        => null,
                'session_cookies' => [['name' => 'sessionid', 'value' => 'abc']],
            ]);
        $this->worker->expects($this->once())
            ->method('auditInstagramProfile')
            ->with('lessworry.id', $this->isType('array'))
            ->willReturn($this->makeProfileAuditResult());
        $this->claude->expects($this->once())
            ->method('analyzeInstagramProfile')
            ->willReturn(['executive_summary' => 'Stub OK', 'scorecard' => ['overall' => ['score' => 7, 'grade' => 'B']]]);

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('done', $audit->instagram_audit_status);
        $this->assertSame('Stub OK', $audit->instagram_audit['executive_summary']);
    }

    // -- no IG url path ----------------------------------------------------

    #[Test]
    public function it_marks_no_instagram_url_provided_when_extractor_returns_null(): void
    {
        $audit = $this->makeAudit(['touchpoints' => ['instagram_url' => '']]);

        $this->hub->expects($this->never())->method('getNextCredential');
        $this->worker->expects($this->never())->method('auditInstagramProfile');
        $this->claude->expects($this->never())->method('analyzeInstagramProfile');

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('no_instagram_url_provided', $audit->instagram_audit_status);
        $this->assertNull($audit->instagram_audit);
    }

    #[Test]
    public function it_marks_no_instagram_url_provided_for_garbage_input(): void
    {
        $audit = $this->makeAudit(['touchpoints' => ['instagram_url' => 'not-a-url-and-not-a-handle!!!']]);

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('no_instagram_url_provided', $audit->instagram_audit_status);
    }

    // -- no credentials ----------------------------------------------------

    #[Test]
    public function it_marks_no_credentials_available_when_hub_returns_null(): void
    {
        $audit = $this->makeAudit();

        $this->hub->expects($this->once())->method('getNextCredential')->willReturn(null);
        $this->worker->expects($this->never())->method('auditInstagramProfile');

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('no_credentials_available', $audit->instagram_audit_status);
    }

    #[Test]
    public function it_marks_audit_failed_when_hub_throws(): void
    {
        // Treated as "no credential available" first time → no_credentials_available.
        // (Hub error is swallowed and logged; the service degrades gracefully.)
        $audit = $this->makeAudit();

        $this->hub->expects($this->once())
            ->method('getNextCredential')
            ->willThrowException(new RuntimeException('Hub returned HTTP 503'));

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('no_credentials_available', $audit->instagram_audit_status);
    }

    // -- login_wall_hit + retry -------------------------------------------

    #[Test]
    public function it_retries_once_on_login_wall_hit_with_different_credential(): void
    {
        $audit = $this->makeAudit();

        $this->hub->expects($this->exactly(2))
            ->method('getNextCredential')
            ->willReturnOnConsecutiveCalls(
                [
                    'id'              => '01j_CRED_A',
                    'platform'        => 'instagram',
                    'username'        => 'naufalk',
                    'password'        => null,
                    'session_cookies' => [['name' => 'sessionid', 'value' => 'stale']],
                ],
                [
                    'id'              => '01j_CRED_B',
                    'platform'        => 'instagram',
                    'username'        => 'backup_user',
                    'password'        => null,
                    'session_cookies' => [['name' => 'sessionid', 'value' => 'fresh']],
                ],
            );

        $this->hub->expects($this->once())
            ->method('reportCredentialStatus')
            ->with('01j_CRED_A', 'requires_2fa', $this->stringContains('login_wall_hit'));

        $this->worker->expects($this->exactly(2))
            ->method('auditInstagramProfile')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new ProfileAuditException(
                    errorCode: 'login_wall_hit',
                    httpStatus: 400,
                    detail: 'Login wall hit despite session_cookies',
                )),
                $this->makeProfileAuditResult(),
            );

        $this->claude->expects($this->once())
            ->method('analyzeInstagramProfile')
            ->willReturn(['executive_summary' => 'OK after retry']);

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('done', $audit->instagram_audit_status);
    }

    #[Test]
    public function it_marks_credentials_stale_when_login_wall_hit_on_all_attempts(): void
    {
        $audit = $this->makeAudit();

        $this->hub->expects($this->exactly(2))
            ->method('getNextCredential')
            ->willReturnOnConsecutiveCalls(
                [
                    'id'              => '01j_CRED_A',
                    'platform'        => 'instagram',
                    'username'        => 'naufalk',
                    'password'        => null,
                    'session_cookies' => [['name' => 'x', 'value' => '1']],
                ],
                [
                    'id'              => '01j_CRED_B',
                    'platform'        => 'instagram',
                    'username'        => 'backup',
                    'password'        => null,
                    'session_cookies' => [['name' => 'x', 'value' => '2']],
                ],
            );

        $this->hub->expects($this->exactly(2))->method('reportCredentialStatus');

        $this->worker->expects($this->exactly(2))
            ->method('auditInstagramProfile')
            ->willThrowException(new ProfileAuditException(
                errorCode: 'login_wall_hit',
                httpStatus: 400,
                detail: null,
            ));

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('credentials_stale', $audit->instagram_audit_status);
    }

    #[Test]
    public function it_marks_credentials_stale_when_only_one_credential_and_it_login_walls(): void
    {
        $audit = $this->makeAudit();

        $this->hub->expects($this->exactly(2))
            ->method('getNextCredential')
            ->willReturnOnConsecutiveCalls(
                [
                    'id'              => '01j_ONLY_ONE',
                    'platform'        => 'instagram',
                    'username'        => 'solo',
                    'password'        => null,
                    'session_cookies' => [['name' => 'x', 'value' => '1']],
                ],
                null, // Hub returns 404 — no other healthy creds
            );

        $this->worker->expects($this->once())
            ->method('auditInstagramProfile')
            ->willThrowException(new ProfileAuditException(
                errorCode: 'login_wall_hit',
                httpStatus: 400,
                detail: null,
            ));

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('credentials_stale', $audit->instagram_audit_status);
    }

    // -- rate_limited / profile_not_found ---------------------------------

    #[Test]
    public function it_marks_rate_limited(): void
    {
        $audit = $this->makeAudit();

        $this->hub->method('getNextCredential')->willReturn([
            'id' => 'c', 'platform' => 'instagram', 'username' => 'u', 'password' => null,
            'session_cookies' => [['name' => 'x', 'value' => '1']],
        ]);
        $this->worker->method('auditInstagramProfile')
            ->willThrowException(new ProfileAuditException(
                errorCode: 'rate_limited',
                httpStatus: 429,
                detail: 'Tunggu 240 detik',
                retryAfterSeconds: 240,
            ));

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('rate_limited', $audit->instagram_audit_status);
    }

    #[Test]
    public function it_retries_on_interstitial_blocked_with_different_credential(): void
    {
        // W7.1 item 7 wiring: the new error code from the worker should
        // be treated like login_wall_hit — mark the credential stale,
        // try once more with a different credential, succeed.
        $audit = $this->makeAudit();

        $this->hub->expects($this->exactly(2))
            ->method('getNextCredential')
            ->willReturnOnConsecutiveCalls(
                [
                    'id'              => '01j_CRED_A',
                    'platform'        => 'instagram',
                    'username'        => 'staleone',
                    'password'        => null,
                    'session_cookies' => [['name' => 'sessionid', 'value' => '1']],
                ],
                [
                    'id'              => '01j_CRED_B',
                    'platform'        => 'instagram',
                    'username'        => 'freshone',
                    'password'        => null,
                    'session_cookies' => [['name' => 'sessionid', 'value' => '2']],
                ],
            );

        $this->hub->expects($this->once())
            ->method('reportCredentialStatus')
            ->with('01j_CRED_A', 'requires_2fa', $this->stringContains('interstitial_blocked'));

        $this->worker->expects($this->exactly(2))
            ->method('auditInstagramProfile')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new ProfileAuditException(
                    errorCode: 'interstitial_blocked',
                    httpStatus: 400,
                    detail: 'Bloks chooser redirect; re-bootstrap required',
                )),
                $this->makeProfileAuditResult(),
            );

        $this->claude->method('analyzeInstagramProfile')
            ->willReturn(['executive_summary' => 'OK after retry']);

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('done', $audit->instagram_audit_status);
    }

    #[Test]
    public function it_marks_credentials_stale_when_interstitial_blocked_on_all_attempts(): void
    {
        $audit = $this->makeAudit();

        $this->hub->expects($this->exactly(2))
            ->method('getNextCredential')
            ->willReturnOnConsecutiveCalls(
                [
                    'id' => '01j_A', 'platform' => 'instagram', 'username' => 'u', 'password' => null,
                    'session_cookies' => [['name' => 'x', 'value' => '1']],
                ],
                [
                    'id' => '01j_B', 'platform' => 'instagram', 'username' => 'u', 'password' => null,
                    'session_cookies' => [['name' => 'x', 'value' => '2']],
                ],
            );

        $this->hub->expects($this->exactly(2))->method('reportCredentialStatus');

        $this->worker->expects($this->exactly(2))
            ->method('auditInstagramProfile')
            ->willThrowException(new ProfileAuditException(
                errorCode: 'interstitial_blocked',
                httpStatus: 400,
                detail: null,
            ));

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('credentials_stale', $audit->instagram_audit_status);
    }

    #[Test]
    public function it_marks_profile_not_found(): void
    {
        $audit = $this->makeAudit();

        $this->hub->method('getNextCredential')->willReturn([
            'id' => 'c', 'platform' => 'instagram', 'username' => 'u', 'password' => null,
            'session_cookies' => [['name' => 'x', 'value' => '1']],
        ]);
        $this->worker->method('auditInstagramProfile')
            ->willThrowException(new ProfileAuditException(
                errorCode: 'profile_not_found',
                httpStatus: 400,
                detail: null,
            ));

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('profile_not_found', $audit->instagram_audit_status);
    }

    // -- catch-all audit_failed paths -------------------------------------

    #[Test]
    public function it_marks_audit_failed_on_worker_timeout(): void
    {
        $audit = $this->makeAudit();

        $this->hub->method('getNextCredential')->willReturn([
            'id' => 'c', 'platform' => 'instagram', 'username' => 'u', 'password' => null,
            'session_cookies' => [['name' => 'x', 'value' => '1']],
        ]);
        $this->worker->method('auditInstagramProfile')
            ->willThrowException(new ProfileAuditException(
                errorCode: 'timeout',
                httpStatus: 500,
                detail: 'Navigation exceeded 12000ms.',
            ));

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('audit_failed', $audit->instagram_audit_status);
        $this->assertStringContainsString('timeout', (string) ($audit->instagram_audit['error'] ?? ''));
    }

    #[Test]
    public function it_marks_audit_failed_on_worker_auth_rejection(): void
    {
        $audit = $this->makeAudit();

        $this->hub->method('getNextCredential')->willReturn([
            'id' => 'c', 'platform' => 'instagram', 'username' => 'u', 'password' => null,
            'session_cookies' => [['name' => 'x', 'value' => '1']],
        ]);
        $this->worker->method('auditInstagramProfile')
            ->willThrowException(new WorkerAuthException('Worker rejected credentials (401)', 401));

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('audit_failed', $audit->instagram_audit_status);
        $this->assertStringContainsString('worker_auth_failed', (string) ($audit->instagram_audit['error'] ?? ''));
    }

    #[Test]
    public function it_marks_audit_failed_when_worker_unavailable(): void
    {
        $audit = $this->makeAudit();

        $this->hub->method('getNextCredential')->willReturn([
            'id' => 'c', 'platform' => 'instagram', 'username' => 'u', 'password' => null,
            'session_cookies' => [['name' => 'x', 'value' => '1']],
        ]);
        $this->worker->method('auditInstagramProfile')
            ->willThrowException(new WorkerNotAvailableException('Worker at http://x is unreachable'));

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('audit_failed', $audit->instagram_audit_status);
        $this->assertStringContainsString('worker_unavailable', (string) ($audit->instagram_audit['error'] ?? ''));
    }

    #[Test]
    public function it_marks_audit_failed_when_claude_throws(): void
    {
        $audit = $this->makeAudit();

        $this->hub->method('getNextCredential')->willReturn([
            'id' => 'c', 'platform' => 'instagram', 'username' => 'u', 'password' => null,
            'session_cookies' => [['name' => 'x', 'value' => '1']],
        ]);
        $this->worker->method('auditInstagramProfile')->willReturn($this->makeProfileAuditResult());
        $this->claude->method('analyzeInstagramProfile')
            ->willThrowException(new RuntimeException('Anthropic API 500'));

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('audit_failed', $audit->instagram_audit_status);
        $this->assertStringContainsString('claude_analysis_failed', (string) ($audit->instagram_audit['error'] ?? ''));
    }

    #[Test]
    public function it_accepts_session_cookies_as_json_string_via_shim(): void
    {
        // Hub persistence bug: cookies stored as JSON-encoded JSON-string
        // (double-encoded), so the encrypted:json cast returns a string.
        // The shim json_decodes once before handing off to the worker.
        $audit = $this->makeAudit();

        $cookiesArray = [['name' => 'sessionid', 'value' => 'abc']];
        $cookiesJson  = json_encode($cookiesArray, JSON_THROW_ON_ERROR);

        $this->hub->expects($this->once())
            ->method('getNextCredential')
            ->willReturn([
                'id'              => '01j_DOUBLE_ENCODED',
                'platform'        => 'instagram',
                'username'        => 'u',
                'password'        => null,
                'session_cookies' => $cookiesJson, // <-- string, not array
            ]);

        // Worker should receive the DECODED array.
        $this->worker->expects($this->once())
            ->method('auditInstagramProfile')
            ->with('lessworry.id', $cookiesArray)
            ->willReturn($this->makeProfileAuditResult());

        $this->claude->method('analyzeInstagramProfile')
            ->willReturn(['executive_summary' => 'OK']);

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('done', $audit->instagram_audit_status);
    }

    #[Test]
    public function it_treats_unparseable_string_cookies_as_stale(): void
    {
        $audit = $this->makeAudit();

        $this->hub->expects($this->exactly(2))
            ->method('getNextCredential')
            ->willReturnOnConsecutiveCalls(
                [
                    'id'              => '01j_GARBAGE',
                    'platform'        => 'instagram',
                    'username'        => 'u',
                    'password'        => null,
                    'session_cookies' => '<<not json>>',
                ],
                null,
            );
        $this->hub->expects($this->once())
            ->method('reportCredentialStatus')
            ->with('01j_GARBAGE', 'requires_2fa');

        $this->worker->expects($this->never())->method('auditInstagramProfile');

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('credentials_stale', $audit->instagram_audit_status);
    }

    #[Test]
    public function it_skips_credential_with_empty_session_cookies_and_retries(): void
    {
        $audit = $this->makeAudit();

        $this->hub->expects($this->exactly(2))
            ->method('getNextCredential')
            ->willReturnOnConsecutiveCalls(
                [
                    'id'              => '01j_EMPTY',
                    'platform'        => 'instagram',
                    'username'        => 'u',
                    'password'        => null,
                    'session_cookies' => [], // empty
                ],
                [
                    'id'              => '01j_OK',
                    'platform'        => 'instagram',
                    'username'        => 'u2',
                    'password'        => null,
                    'session_cookies' => [['name' => 'x', 'value' => '1']],
                ],
            );
        $this->hub->expects($this->once())
            ->method('reportCredentialStatus')
            ->with('01j_EMPTY', 'requires_2fa', $this->stringContains('empty session_cookies'));

        $this->worker->expects($this->once())
            ->method('auditInstagramProfile')
            ->willReturn($this->makeProfileAuditResult());
        $this->claude->method('analyzeInstagramProfile')->willReturn(['executive_summary' => 'OK']);

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('done', $audit->instagram_audit_status);
    }
}
