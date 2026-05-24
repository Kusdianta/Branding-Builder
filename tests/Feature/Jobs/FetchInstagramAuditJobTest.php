<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\FetchInstagramAuditJob;
use App\Models\BrandAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB146 — FetchInstagramAuditJob::failed() coverage. The job is tries=1
 * with a hard 240s timeout; when the queue kills it before the never-throw
 * service persisted a terminal status, instagram_audit_status is left at
 * its 'pending' default. failed() must coerce that to a terminal failure
 * so the dashboard reveal gate can open — without clobbering a real status
 * the service already wrote.
 */
class FetchInstagramAuditJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(array $overrides = []): BrandAudit
    {
        return BrandAudit::create(array_merge([
            'session_token'          => Str::random(64),
            'ip_address'             => '127.0.0.1',
            'brand_name'             => 'Less Worry Laundry',
            'city'                   => 'Bandung',
            'service_type'           => 'kiloan',
            'touchpoints'            => ['instagram_url' => 'https://www.instagram.com/lessworry.id/'],
            'status'                 => BrandAudit::STATUS_ANALYZING,
            'instagram_audit_status' => 'pending',
            'expires_at'             => now()->addDays(30),
        ], $overrides));
    }

    #[Test]
    public function failed_coerces_a_lingering_pending_status_to_terminal(): void
    {
        $audit = $this->makeAudit();

        (new FetchInstagramAuditJob($audit->id))->failed(new \RuntimeException('worker hung'));

        $audit->refresh();
        $this->assertSame('audit_failed', $audit->instagram_audit_status);
        $this->assertStringStartsWith('worker_unavailable', (string) ($audit->instagram_audit['error'] ?? ''));
    }

    #[Test]
    public function failed_is_a_noop_when_status_already_terminal(): void
    {
        $audit = $this->makeAudit([
            'instagram_audit_status' => 'profile_not_found',
            'instagram_audit'        => ['error' => 'profile_not_found'],
        ]);

        (new FetchInstagramAuditJob($audit->id))->failed(new \RuntimeException('worker hung'));

        $audit->refresh();
        $this->assertSame('profile_not_found', $audit->instagram_audit_status);
        $this->assertSame('profile_not_found', $audit->instagram_audit['error']);
    }
}
