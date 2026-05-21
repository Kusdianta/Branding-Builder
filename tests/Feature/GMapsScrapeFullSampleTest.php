<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BrandAudit;
use App\Services\EvidenceMapper;
use App\Services\Scoring\OwnerReplyRateScorer;
use App\Services\Scoring\RecallScorer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB130 regression — owner-reply rate must survive the path from the
 * scraped corpus to the manajemen_ulasan sub-bucket.
 *
 * The bug: ScorePillarsJob normalized reviews to {text, rating} for the
 * keyword/sentiment corpus and fed THAT (owner_reply already stripped)
 * to the owner-reply scorer, forcing reply rate to 0% even when the
 * scrape captured replies. The fix threads the RAW EvidenceMapper rows
 * to the owner-reply scorer instead.
 *
 * This test builds a 60-review corpus with 30 owner replies (50%) and
 * walks the deterministic chain EvidenceMapper -> OwnerReplyRateScorer
 * -> RecallScorer, asserting the expected 50% / 10-pt outcome.
 */
class GMapsScrapeFullSampleTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function corpus(int $total, int $replied): array
    {
        $reviews = [];
        for ($i = 0; $i < $total; $i++) {
            $reviews[] = [
                'author'        => "Reviewer {$i}",
                'rating_label'  => '5 stars',
                'rating_value'  => 5,
                'date_relative' => '1 month ago',
                'text'          => "Pelayanan bagus dan cepat #{$i}",
                'owner_reply'   => $i < $replied
                    ? ['has_reply' => true, 'reply_text' => "Terima kasih {$i}!", 'reply_date_relative' => '3 weeks ago']
                    : null,
            ];
        }
        return $reviews;
    }

    private function auditWithCorpus(array $reviews, bool $sop = false): BrandAudit
    {
        // Unsaved model — audit_evidence/touchpoints are cast attributes,
        // so EvidenceMapper reads them straight from memory (no DB).
        $audit = new BrandAudit();
        $audit->audit_evidence = ['gmaps_scrape' => ['reviews' => $reviews]];
        $audit->touchpoints = ['operational' => ['complaint_sop' => $sop]];

        return $audit;
    }

    #[Test]
    public function evidence_mapper_preserves_owner_reply_but_corpus_normalization_strips_it(): void
    {
        $audit = $this->auditWithCorpus($this->corpus(60, 30));
        $raw = app(EvidenceMapper::class)->fullReviews($audit);

        $this->assertCount(60, $raw);
        $withReply = array_filter($raw, fn ($r) => is_array($r['owner_reply'] ?? null) && ($r['owner_reply']['has_reply'] ?? false) === true);
        $this->assertCount(30, $withReply, 'EvidenceMapper must keep owner_reply on the raw rows');

        // The {text, rating} normalization used for the keyword corpus
        // DROPS owner_reply — which is exactly why owner-reply scoring
        // must read the raw rows, not the normalized ones.
        $normalized = array_map(fn ($r) => ['text' => $r['text'], 'rating' => (float) $r['rating_value']], $raw);
        $this->assertArrayNotHasKey('owner_reply', $normalized[0]);
    }

    #[Test]
    public function fifty_percent_corpus_scores_ten_points_kadang(): void
    {
        $audit = $this->auditWithCorpus($this->corpus(60, 30), sop: false);
        $raw = app(EvidenceMapper::class)->fullReviews($audit);

        $reply = (new OwnerReplyRateScorer())->score($raw, hasSopDeclared: false);
        $this->assertSame(50.0, $reply['evidence']['reply_rate_pct']);

        $replyRate = (float) ($reply['evidence']['reply_rate_pct'] / 100.0);
        $pillar = (new RecallScorer())->score([
            '_wizard_version'           => BrandAudit::WIZARD_V3,
            'rating'                    => 5.0,
            'review_count'              => 60,
            'sampled_reviews'           => [],
            'full_reviews'              => array_map(fn ($r) => ['text' => $r['text'], 'rating' => (float) $r['rating_value']], $raw),
            'owner_reply_rate'          => $replyRate,
            'has_sop_declared'          => false,
            'manajemen_ulasan_evidence' => $reply['evidence'],
        ]);

        $this->assertSame(10, $pillar->subBucketScores['manajemen_ulasan']);
        $this->assertSame('Kadang membalas', $pillar->scoreBreakdown['manajemen_ulasan']['tier']);
    }

    #[Test]
    public function fifty_percent_corpus_with_sop_scores_fifteen(): void
    {
        $audit = $this->auditWithCorpus($this->corpus(60, 30), sop: true);
        $raw = app(EvidenceMapper::class)->fullReviews($audit);

        $reply = (new OwnerReplyRateScorer())->score($raw, hasSopDeclared: true);
        $replyRate = (float) ($reply['evidence']['reply_rate_pct'] / 100.0);

        $pillar = (new RecallScorer())->score([
            '_wizard_version'           => BrandAudit::WIZARD_V3,
            'rating'                    => 5.0,
            'review_count'              => 60,
            'sampled_reviews'           => [],
            'full_reviews'              => array_map(fn ($r) => ['text' => $r['text'], 'rating' => (float) $r['rating_value']], $raw),
            'owner_reply_rate'          => $replyRate,
            'has_sop_declared'          => true,
            'manajemen_ulasan_evidence' => $reply['evidence'],
        ]);

        $this->assertSame(15, $pillar->subBucketScores['manajemen_ulasan']);
    }
}
