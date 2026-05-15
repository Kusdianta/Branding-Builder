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
use Illuminate\Support\Facades\Storage;
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

        // BB13: image persistence writes to the 'local' disk during the
        // happy path. Fake it so tests don't pollute storage/app/private
        // and assertions can probe file existence.
        Storage::fake('local');
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

    // -- BB13: image persistence + _meta sub-key --------------------------

    #[Test]
    public function it_persists_visual_assets_and_attaches_meta_to_instagram_audit(): void
    {
        $audit = $this->makeAudit();

        // 1×1 PNG (smallest valid PNG, ~67 bytes) + 1×1 JPEG (smallest valid
        // JPEG, ~125 bytes). Wrapping them in base64 lets the strict
        // base64_decode + screenshotBytes() / thumbnailBytes() paths
        // succeed and the files actually land on the fake disk.
        $pngBytes  = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        $jpegBytes = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2NjIpLCBxdWFsaXR5ID0gOTAK/9sAQwADAgIDAgIDAwMDBAMDBAUIBQUEBAUKBwcGCAwKDAwLCgsLDQ4SEA0OEQ4LCxAWEBETFBUVFQwPFxgWFBgSFBUU/9sAQwEDBAQFBAUJBQUJFA0LDRQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQU/8AAEQgAAQABAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/aAAwDAQACEQMRAD8A/v8oooooA//Z');

        $this->hub->expects($this->once())
            ->method('getNextCredential')
            ->willReturn([
                'id'              => '01j_CRED',
                'platform'        => 'instagram',
                'username'        => 'naufalk',
                'session_cookies' => [['name' => 'sessionid', 'value' => 'abc']],
            ]);
        $this->worker->expects($this->once())
            ->method('auditInstagramProfile')
            ->willReturn(new InstagramProfileAudit(
                username: 'lessworry.id',
                capturedAt: new DateTimeImmutable('2026-05-11T10:00:00Z'),
                isPrivate: false,
                profile: new ProfileMetadata(
                    name: 'Less Worry | Laundry Bebas Worry',
                    bio: 'Laundry Terlengkap di Satu Tempat',
                    externalUrl: 'https://lessworry.id',
                    followers: 2930,
                    following: 27,
                    postsCount: 163,
                    isVerified: true,
                    isBusiness: true,
                    profilePicBase64: base64_encode($jpegBytes),
                ),
                profilePicFetchError: null,
                screenshotBase64: base64_encode($pngBytes),
                recentPosts: [
                    new RecentPost('A1', 'https://instagram.com/lessworry.id/p/A1/', 'image', base64_encode($pngBytes), 'caption A', '2 hari'),
                    new RecentPost('B2', 'https://instagram.com/lessworry.id/p/B2/', 'reel',  base64_encode($pngBytes), 'caption B', '5 hari'),
                ],
                highlights: [new Highlight('Promo', 'cov'), new Highlight('Testimoni', 'cov2')],
                durationMs: 35000,
            ));
        $this->claude->expects($this->once())
            ->method('analyzeInstagramProfile')
            ->willReturn([
                'executive_summary' => 'Real analysis from Claude',
                'scorecard'         => ['overall' => ['score' => 5.1, 'grade' => 'C']],
            ]);

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('done', $audit->instagram_audit_status);

        // Files persisted under storage/app/private/audits/{id}/instagram/
        $base = "audits/{$audit->id}/instagram";
        Storage::disk('local')->assertExists("{$base}/profile_pic.jpg");
        Storage::disk('local')->assertExists("{$base}/screenshot.png");
        Storage::disk('local')->assertExists("{$base}/posts/0.png");
        Storage::disk('local')->assertExists("{$base}/posts/1.png");

        // _meta attached to the analysis payload with full worker context.
        $meta = $audit->instagram_audit['_meta'] ?? null;
        $this->assertIsArray($meta);
        $this->assertSame('lessworry.id', $meta['username']);
        $this->assertSame('Less Worry | Laundry Bebas Worry', $meta['name']);
        $this->assertSame('Laundry Terlengkap di Satu Tempat', $meta['bio']);
        $this->assertSame('https://lessworry.id', $meta['external_url']);
        $this->assertSame(2930, $meta['followers']);
        $this->assertSame(27, $meta['following']);
        $this->assertSame(163, $meta['posts_count']);
        $this->assertTrue($meta['is_verified']);
        $this->assertTrue($meta['is_business']);
        $this->assertFalse($meta['is_private']);
        $this->assertSame("{$base}/profile_pic.jpg", $meta['profile_pic_path']);
        $this->assertSame("{$base}/screenshot.png", $meta['screenshot_path']);
        $this->assertSame(
            [0 => "{$base}/posts/0.png", 1 => "{$base}/posts/1.png"],
            $meta['post_thumbnail_paths'],
        );
        $this->assertSame(['Promo', 'Testimoni'], $meta['highlight_names']);

        // Claude's analysis payload is preserved verbatim alongside _meta.
        $this->assertSame('Real analysis from Claude', $audit->instagram_audit['executive_summary']);
    }

    #[Test]
    public function it_skips_malformed_visual_payloads_without_failing_the_audit(): void
    {
        $audit = $this->makeAudit();

        $this->hub->expects($this->once())
            ->method('getNextCredential')
            ->willReturn([
                'id'              => '01j_CRED',
                'platform'        => 'instagram',
                'session_cookies' => [['name' => 'sessionid', 'value' => 'abc']],
            ]);

        // Mix of malformed and valid payloads — defensive path: malformed
        // entries are silently skipped, valid ones still land on disk.
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');

        $this->worker->expects($this->once())
            ->method('auditInstagramProfile')
            ->willReturn(new InstagramProfileAudit(
                username: 'broken.profile',
                capturedAt: new DateTimeImmutable('2026-05-11T10:00:00Z'),
                isPrivate: false,
                profile: new ProfileMetadata(
                    name: 'Broken',
                    bio: '',
                    externalUrl: '',
                    followers: 0,
                    following: 0,
                    postsCount: 0,
                    isVerified: false,
                    isBusiness: false,
                    // Profile pic empty → skipped.
                    profilePicBase64: '',
                ),
                profilePicFetchError: 'profile pic fetch failed: TimeoutException',
                // Screenshot malformed (underscore not valid in standard
                // base64) → strict decode fails, file skipped.
                screenshotBase64: 'not_valid_base64!',
                recentPosts: [
                    // Empty thumbnail → skipped.
                    new RecentPost('A1', 'https://instagram.com/broken.profile/p/A1/', 'image', '', null, null),
                    // Valid → persisted.
                    new RecentPost('B2', 'https://instagram.com/broken.profile/p/B2/', 'reel',  base64_encode($pngBytes), null, null),
                ],
                highlights: [],
                durationMs: 12000,
            ));
        $this->claude->expects($this->once())
            ->method('analyzeInstagramProfile')
            ->willReturn(['executive_summary' => 'Sparse analysis']);

        $this->makeService()->audit($audit);

        $audit->refresh();
        $this->assertSame('done', $audit->instagram_audit_status);

        $base = "audits/{$audit->id}/instagram";
        Storage::disk('local')->assertMissing("{$base}/profile_pic.jpg");
        Storage::disk('local')->assertMissing("{$base}/screenshot.png");
        Storage::disk('local')->assertMissing("{$base}/posts/0.png");
        Storage::disk('local')->assertExists("{$base}/posts/1.png");

        $meta = $audit->instagram_audit['_meta'];
        $this->assertNull($meta['profile_pic_path']);
        $this->assertNull($meta['screenshot_path']);
        // Only the valid thumbnail at index 1 made it.
        $this->assertSame([1 => "{$base}/posts/1.png"], $meta['post_thumbnail_paths']);
        // Numeric metadata is preserved for the followers=0 banner trigger.
        $this->assertSame(0, $meta['followers']);
        $this->assertSame(0, $meta['posts_count']);
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

    // W7.5: removed two tests covering the normalizeSessionCookies
    // shim (it_accepts_session_cookies_as_json_string_via_shim +
    // it_treats_unparseable_string_cookies_as_stale). The shim
    // itself was dropped — Hub now consistently writes arrays, so
    // string-shape cookies coming through the Hub HTTP response are
    // a contract violation, not an expected case to defensively handle.
    // The empty-cookies stale path is still covered by
    // it_skips_credential_with_empty_session_cookies_and_retries below.

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
