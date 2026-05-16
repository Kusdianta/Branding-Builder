<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\BrandAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB79 — AuditController::downloadKit coverage. Confirms the download
 * route returns a real PDF with proper attachment headers when the
 * activation_kit_path points to an existing file, and 404s cleanly when
 * the file is missing or the column is null (BB79 pre-fix legacy state).
 */
class DownloadKitTest extends TestCase
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

    /** Minimal but valid 4-byte PDF header bytes for the magic-byte assertion. */
    private function fakePdfBytes(): string
    {
        return "%PDF-1.4\n%fake\n%%EOF\n";
    }

    #[Test]
    public function download_returns_pdf_with_attachment_headers(): void
    {
        Storage::fake('local');

        $audit = $this->makeAudit([
            'activation_kit_path' => 'audits/test-id/activation-kit.pdf',
        ]);

        Storage::disk('local')->put('audits/test-id/activation-kit.pdf', $this->fakePdfBytes());

        $response = $this->get("/audit/{$audit->session_token}/kit/download");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader(
            'Content-Disposition',
            'attachment; filename=activation-kit-Less-Worry-Laundry.pdf',
        );
        // PDF magic bytes — first 4 chars must be %PDF.
        $this->assertSame('%PDF', substr($response->streamedContent(), 0, 4));
    }

    #[Test]
    public function download_returns_404_when_activation_kit_path_is_null(): void
    {
        $audit = $this->makeAudit(['activation_kit_path' => null]);

        $response = $this->get("/audit/{$audit->session_token}/kit/download");

        $response->assertStatus(404);
    }

    #[Test]
    public function download_returns_404_when_file_does_not_exist_on_disk(): void
    {
        Storage::fake('local');

        $audit = $this->makeAudit([
            'activation_kit_path' => 'audits/no-such-file/activation-kit.pdf',
        ]);
        // Deliberately do NOT call Storage::disk('local')->put() — file
        // missing should 404 even when the path column is populated.

        $response = $this->get("/audit/{$audit->session_token}/kit/download");

        $response->assertStatus(404);
    }

    #[Test]
    public function download_404s_on_unknown_session_token(): void
    {
        $response = $this->get('/audit/unknown-token-xxx/kit/download');
        $response->assertStatus(404);
    }

    #[Test]
    public function filename_sanitization_strips_unsafe_chars_from_brand_name(): void
    {
        Storage::fake('local');

        $audit = $this->makeAudit([
            'brand_name'          => 'Less Worry / Laundry & Co.',
            'activation_kit_path' => 'audits/sanitize/activation-kit.pdf',
        ]);
        Storage::disk('local')->put('audits/sanitize/activation-kit.pdf', $this->fakePdfBytes());

        $response = $this->get("/audit/{$audit->session_token}/kit/download");

        $response->assertStatus(200);
        // Slashes / ampersands collapse to '-' per AuditController regex.
        $response->assertHeader(
            'Content-Disposition',
            'attachment; filename=activation-kit-Less-Worry-Laundry-Co..pdf',
        );
    }
}
