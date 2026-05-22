<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\BrandAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB131 — AuditController::instagramScreenshot coverage. The worker-captured
 * profile screenshot lives on the PRIVATE local disk; this token-scoped
 * route streams it inline as scrape proof. Confirms it serves the PNG when
 * present and 404s cleanly when the path is absent, the file is missing, or
 * the token doesn't resolve.
 */
class InstagramScreenshotTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(array $overrides = []): BrandAudit
    {
        return BrandAudit::create(array_merge([
            'session_token' => Str::random(64),
            'ip_address'    => '127.0.0.1',
            'brand_name'    => 'Less Worry Laundry',
            'city'          => 'Bandung',
            'service_type'  => 'kiloan',
            'touchpoints'   => ['instagram_url' => 'https://instagram.com/lessworry.id'],
            'status'        => BrandAudit::STATUS_DONE,
            'expires_at'    => now()->addDays(30),
        ], $overrides));
    }

    /** Minimal valid PNG signature bytes for the magic-byte assertion. */
    private function fakePngBytes(): string
    {
        return "\x89PNG\r\n\x1a\n" . 'fake-image-payload';
    }

    #[Test]
    public function screenshot_streams_png_when_present(): void
    {
        Storage::fake('local');

        $path  = 'audits/test-id/instagram/screenshot.png';
        $audit = $this->makeAudit([
            'instagram_audit_status' => 'done',
            'instagram_audit'        => ['_meta' => ['screenshot_path' => $path]],
        ]);
        Storage::disk('local')->put($path, $this->fakePngBytes());

        $response = $this->get("/audit/{$audit->session_token}/instagram/screenshot");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
        // PNG magic bytes — first 4 bytes must be \x89PNG.
        $this->assertSame("\x89PNG", substr($response->streamedContent(), 0, 4));
    }

    #[Test]
    public function returns_404_when_no_screenshot_path_recorded(): void
    {
        Storage::fake('local');

        $audit = $this->makeAudit([
            'instagram_audit_status' => 'done',
            'instagram_audit'        => ['_meta' => ['username' => 'lessworry.id']],
        ]);

        $response = $this->get("/audit/{$audit->session_token}/instagram/screenshot");

        $response->assertStatus(404);
    }

    #[Test]
    public function returns_404_when_file_missing_on_disk(): void
    {
        Storage::fake('local');

        $audit = $this->makeAudit([
            'instagram_audit_status' => 'done',
            'instagram_audit'        => ['_meta' => ['screenshot_path' => 'audits/gone/instagram/screenshot.png']],
        ]);
        // Deliberately do NOT put() the file — a recorded path with no file
        // (failed scrape / legacy audit) must 404, not 500.

        $response = $this->get("/audit/{$audit->session_token}/instagram/screenshot");

        $response->assertStatus(404);
    }

    #[Test]
    public function returns_404_when_instagram_audit_is_null(): void
    {
        Storage::fake('local');

        $audit = $this->makeAudit(['instagram_audit' => null]);

        $response = $this->get("/audit/{$audit->session_token}/instagram/screenshot");

        $response->assertStatus(404);
    }

    #[Test]
    public function returns_404_on_unknown_session_token(): void
    {
        $response = $this->get('/audit/unknown-token-xxx/instagram/screenshot');

        $response->assertStatus(404);
    }

    // -- BB133: per-section screenshots -----------------------------------

    #[Test]
    public function streams_feed_section_when_present(): void
    {
        Storage::fake('local');

        $base  = 'audits/test-id/instagram';
        $audit = $this->makeAudit([
            'instagram_audit_status' => 'done',
            'instagram_audit'        => ['_meta' => ['screenshot_paths' => [
                'profile' => "{$base}/profile.png",
                'feed'    => "{$base}/feed.png",
                'reels'   => "{$base}/reels.png",
            ]]],
        ]);
        Storage::disk('local')->put("{$base}/feed.png", $this->fakePngBytes());

        $response = $this->get("/audit/{$audit->session_token}/instagram/screenshot/feed");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertSame("\x89PNG", substr($response->streamedContent(), 0, 4));
    }

    #[Test]
    public function profile_section_falls_back_to_legacy_screenshot_path(): void
    {
        Storage::fake('local');

        // Pre-BB133 audit: only the legacy single screenshot_path exists.
        $path  = 'audits/legacy-id/instagram/screenshot.png';
        $audit = $this->makeAudit([
            'instagram_audit_status' => 'done',
            'instagram_audit'        => ['_meta' => ['screenshot_path' => $path]],
        ]);
        Storage::disk('local')->put($path, $this->fakePngBytes());

        $response = $this->get("/audit/{$audit->session_token}/instagram/screenshot/profile");

        $response->assertStatus(200);
        $this->assertSame("\x89PNG", substr($response->streamedContent(), 0, 4));
    }

    #[Test]
    public function returns_404_for_unknown_section(): void
    {
        Storage::fake('local');

        $audit = $this->makeAudit([
            'instagram_audit_status' => 'done',
            'instagram_audit'        => ['_meta' => ['screenshot_paths' => ['profile' => 'audits/x/instagram/profile.png']]],
        ]);

        // 'stories' is not an allowed section — route constraint rejects it.
        $response = $this->get("/audit/{$audit->session_token}/instagram/screenshot/stories");

        $response->assertStatus(404);
    }

    #[Test]
    public function returns_404_for_feed_section_when_absent(): void
    {
        Storage::fake('local');

        // Account with no reels/feed captured — only profile present.
        $audit = $this->makeAudit([
            'instagram_audit_status' => 'done',
            'instagram_audit'        => ['_meta' => ['screenshot_paths' => ['profile' => 'audits/x/instagram/profile.png']]],
        ]);

        $response = $this->get("/audit/{$audit->session_token}/instagram/screenshot/feed");

        $response->assertStatus(404);
    }
}
