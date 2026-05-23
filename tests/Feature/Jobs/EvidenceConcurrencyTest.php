<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\Concerns\WritesAuditEvidence;
use App\Models\BrandAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB139 — race-safe audit_evidence writes.
 *
 * Once the gather/analyze batches run under multiple queue workers their jobs
 * write audit_evidence concurrently. The WritesAuditEvidence trait replaces the
 * old read-modify-write-the-whole-blob pattern with a single atomic json_set
 * UPDATE per key, so a sibling key written by another job is never clobbered
 * and present-but-null members survive (json_set, not json_patch).
 *
 * These tests drive the trait directly and assert the persisted shape — the
 * exact contract the six Fetch/Analyze jobs depend on.
 */
class EvidenceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(): BrandAudit
    {
        return BrandAudit::create([
            'session_token' => Str::random(64),
            'ip_address'    => '127.0.0.1',
            'brand_name'    => 'Less Worry Laundry',
            'city'          => 'Bandung',
            'service_type'  => 'kiloan',
            'touchpoints'   => [],
            'status'        => BrandAudit::STATUS_ANALYZING,
            'expires_at'    => now()->addDays(30),
        ]);
    }

    /** A minimal consumer of the trait, mirroring how a job exposes $auditId. */
    private function writerFor(string $auditId): object
    {
        return new class($auditId)
        {
            use WritesAuditEvidence;

            public function __construct(public readonly string $auditId) {}

            public function set(string $key, mixed $value): void
            {
                $this->writeEvidenceKey($key, $value);
            }

            public function setNested(string $parent, string $child, mixed $value): void
            {
                $this->writeEvidenceNestedKey($parent, $child, $value);
            }
        };
    }

    #[Test]
    public function all_six_evidence_slices_coexist_without_clobbering(): void
    {
        $audit  = $this->makeAudit();
        $writer = $this->writerFor($audit->id);

        // Interleave the writes the way the parallel gather + analyze batches
        // would: each writer touches only its own key.
        $writer->set('places_api', ['rating' => 4.6, 'review_count' => 142]);
        $writer->set('gmaps_scrape', ['business_name' => 'Less Worry', 'reviews' => [['t' => 'good']]]);
        $writer->set('instagram_audit', null); // skipped scrape -> present null
        $writer->set('website', ['has_pricing_keywords' => true]);
        $writer->set('instagram_analysis', ['scorecard' => ['overall' => ['score' => 71]]]);
        $writer->setNested('analysis', 'service_signals', ['express' => ['detected' => true]]);

        $ev = $audit->fresh()->audit_evidence;

        // Every slice present, none overwritten by a later write.
        $this->assertSame(4.6, $ev['places_api']['rating']);
        $this->assertSame('Less Worry', $ev['gmaps_scrape']['business_name']);
        $this->assertSame('good', $ev['gmaps_scrape']['reviews'][0]['t']);
        $this->assertTrue($ev['website']['has_pricing_keywords']);
        $this->assertSame(71, $ev['instagram_analysis']['scorecard']['overall']['score']);
        $this->assertTrue($ev['analysis']['service_signals']['express']['detected']);

        // Skipped scrape: key present, value null (json_set keeps null members;
        // json_patch would have deleted it).
        $this->assertArrayHasKey('instagram_audit', $ev);
        $this->assertNull($ev['instagram_audit']);
    }

    #[Test]
    public function nested_write_preserves_top_level_sibling(): void
    {
        // The Phase-2 clobber scenario: AnalyzeInstagramJob writes
        // instagram_analysis while ExtractServiceSignalsJob writes the nested
        // analysis.service_signals. Neither must drop the other.
        $audit  = $this->makeAudit();
        $writer = $this->writerFor($audit->id);

        $writer->set('instagram_analysis', ['x' => 1]);
        $writer->setNested('analysis', 'service_signals', [1, 2, 3]);

        $ev = $audit->fresh()->audit_evidence;

        $this->assertSame(1, $ev['instagram_analysis']['x']);
        $this->assertSame([1, 2, 3], $ev['analysis']['service_signals']);
    }

    #[Test]
    public function nested_write_preserves_existing_parent_sibling(): void
    {
        $audit  = $this->makeAudit();
        $writer = $this->writerFor($audit->id);

        // Pre-existing analysis child must survive a service_signals write.
        $writer->setNested('analysis', 'other', ['keep' => true]);
        $writer->setNested('analysis', 'service_signals', ['ok' => 1]);

        $ev = $audit->fresh()->audit_evidence;

        $this->assertTrue($ev['analysis']['other']['keep']);
        $this->assertSame(1, $ev['analysis']['service_signals']['ok']);
    }

    #[Test]
    public function coerces_legacy_array_base_to_object(): void
    {
        // A row whose audit_evidence was persisted as an empty array '[]'
        // must still accept a keyed write.
        $audit = $this->makeAudit();
        $audit->update(['audit_evidence' => []]);

        $this->writerFor($audit->id)->set('website', ['ok' => true]);

        $ev = $audit->fresh()->audit_evidence;
        $this->assertTrue($ev['website']['ok']);
    }

    #[Test]
    public function tolerates_null_base_column(): void
    {
        $audit = $this->makeAudit();
        $audit->update(['audit_evidence' => null]);

        $this->writerFor($audit->id)->set('places_api', null);

        $ev = $audit->fresh()->audit_evidence;
        $this->assertArrayHasKey('places_api', $ev);
        $this->assertNull($ev['places_api']);
    }
}
