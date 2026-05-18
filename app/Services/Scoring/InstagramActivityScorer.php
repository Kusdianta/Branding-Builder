<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Phase 12c.2-rubric-alignment BB116 — Instagram activity scorer.
 *
 * Pure-PHP. Consumes the existing $audit->instagram_audit blob
 * (already scraped by FetchInstagramAuditJob — no new worker call).
 *
 * Score breakdown (cap 20):
 *   + 8 pts  — last post within 14 days ("Posting feed konsisten")
 *   + 4 pts  — active story right now ("Story aktif")
 *   + 5 pts  — average ≥ 2 posts per week over last 8 weeks
 *   + 3 pts  — cadence variance < 1.5 across ≥ 4 weeks ("Konsisten")
 *
 * Tier labels match the PPT rubric. When the IG scrape returned no
 * posts at all (failed scrape, private account, fresh handle), the
 * scorer surfaces an honest unavailable result instead of pretending
 * the brand has zero activity — that's the difference between "tidak
 * aktif" (known inactive) and "tidak tersedia" (unknown).
 */
final class InstagramActivityScorer
{
    private const MAX_SCORE   = 20;
    private const ACTIVE_DAYS = 14;
    private const TARGET_PPW  = 2.0;
    private const VAR_CAP     = 1.5;

    /**
     * @param array<string,mixed> $instagramData  $audit->instagram_audit shape.
     * @return array{
     *   score: int|null,
     *   tier: string|null,
     *   evidence: array<string,mixed>,
     *   source: string,
     *   unavailable_reason: string|null,
     * }
     */
    public function score(array $instagramData): array
    {
        $posts = $instagramData['posts'] ?? $instagramData['recent_posts'] ?? [];
        if (! is_array($posts) || $posts === []) {
            return [
                'score'              => null,
                'tier'               => null,
                'evidence'           => [],
                'source'             => 'Sumber: scrape feed Instagram (12 post terakhir)',
                'unavailable_reason' => $this->resolveUnavailableReason($instagramData),
            ];
        }

        // Posts may arrive with 'posted_at' (canonical) or 'taken_at_ts'
        // (worker raw). Coerce to Carbon, skip rows we cannot parse.
        $dates = collect($posts)
            ->map(fn ($p) => $this->parseDate($p))
            ->filter()
            ->sortDesc()
            ->values();

        if ($dates->isEmpty()) {
            return [
                'score'              => null,
                'tier'               => null,
                'evidence'           => [],
                'source'             => 'Sumber: scrape feed Instagram (12 post terakhir)',
                'unavailable_reason' => 'Worker mengembalikan post Instagram tanpa tanggal yang bisa diparse.',
            ];
        }

        $now              = Carbon::now();
        $daysSinceLastPost = (int) max(0, $now->diffInDays($dates->first(), false) * -1);
        $hasActiveStory    = (bool) ($instagramData['has_active_story'] ?? false);

        $cutoff      = $now->copy()->subWeeks(8);
        $recentDates = $dates->filter(fn (Carbon $d) => $d->gte($cutoff))->values();
        $postsPerWeek = $recentDates->count() / 8.0;

        $weeklyCounts = $recentDates
            ->groupBy(fn (Carbon $d) => $d->year . '-' . str_pad((string) $d->weekOfYear, 2, '0', STR_PAD_LEFT))
            ->map->count()
            ->values();
        $variance = $this->variance($weeklyCounts);

        $score  = 0;
        $score += $daysSinceLastPost <= self::ACTIVE_DAYS ? 8 : 0;
        $score += $hasActiveStory ? 4 : 0;
        $score += $postsPerWeek >= self::TARGET_PPW ? 5 : 0;
        $score += ($variance < self::VAR_CAP && $weeklyCounts->count() >= 4) ? 3 : 0;
        $score  = (int) min($score, self::MAX_SCORE);

        return [
            'score'    => $score,
            'tier'     => $this->tierFor($score),
            'evidence' => [
                'last_post_days_ago' => $daysSinceLastPost,
                'has_active_story'   => $hasActiveStory,
                'posts_per_week_avg' => round($postsPerWeek, 1),
                'cadence_variance'   => round($variance, 2),
                'recent_post_count'  => $recentDates->count(),
            ],
            'source'             => 'Sumber: scrape feed & story Instagram',
            'unavailable_reason' => null,
        ];
    }

    /** @param array<string,mixed> $post */
    private function parseDate(array $post): ?Carbon
    {
        foreach (['posted_at', 'taken_at_iso', 'taken_at', 'created_at'] as $key) {
            $raw = $post[$key] ?? null;
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            try {
                return Carbon::parse($raw);
            } catch (\Throwable) {
                continue;
            }
        }
        $ts = $post['taken_at_ts'] ?? null;
        if (is_numeric($ts) && (int) $ts > 0) {
            return Carbon::createFromTimestamp((int) $ts);
        }
        return null;
    }

    /** @param Collection<int,int> $values */
    private function variance(Collection $values): float
    {
        if ($values->count() < 2) {
            return 0.0;
        }
        $mean = $values->avg();
        return (float) $values->map(fn ($v) => ($v - $mean) ** 2)->avg();
    }

    private function tierFor(int $score): string
    {
        return match (true) {
            $score >= 18 => 'sangat aktif',
            $score >= 12 => 'aktif',
            $score >= 6  => 'jarang',
            default      => 'tidak aktif',
        };
    }

    /** @param array<string,mixed> $data */
    private function resolveUnavailableReason(array $data): string
    {
        $status = (string) ($data['_meta']['scrape_status'] ?? $data['status'] ?? '');
        return match ($status) {
            'credentials_stale'         => 'Sesi Instagram operator kedaluwarsa, scrape gagal.',
            'rate_limited'              => 'Worker di-rate-limit oleh Instagram. Jalankan audit ulang.',
            'private_account'           => 'Akun Instagram diset private, feed tidak bisa dianalisis.',
            'profile_shell_only'        => 'Hanya profile shell yang berhasil diambil, feed tidak tersedia.',
            'no_credentials_available'  => 'Belum ada kredensial worker Instagram aktif.',
            default                     => 'Scrape Instagram tidak tersedia untuk audit ini.',
        };
    }
}
