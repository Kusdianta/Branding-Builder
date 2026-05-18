@php
    /**
     * Phase 8 BB29: GMaps reviews + experience-penalty section in the
     * brand-audit dashboard. Mirrors pdf/_gmaps-reviews.blade.php — same
     * data shape, Tailwind / design-token rendering instead of DomPDF
     * inline-HEX tables.
     *
     * Caller binds:
     *   $audit  BrandAudit model (or already-decoded shape via Livewire props)
     *
     * Gate: full section only renders when status='done' AND reviews
     * non-empty. Other statuses surface a banner.
     */

    $status  = (string) ($audit->gmaps_reviews_status ?? '');
    $payload = (array)  ($audit->gmaps_reviews ?? []);

    $statusBannerMap = [
        'pending'                  => ['severity' => 'info',    'title' => 'Scrape ulasan masih dalam proses', 'body' => 'Dashboard akan diperbarui otomatis setelah selesai.'],
        'no_gmaps_url_provided'    => ['severity' => 'info',    'title' => 'Scrape ulasan dilewati', 'body' => 'URL Google Maps tidak diisi di form audit.'],
        'no_credentials_available' => ['severity' => 'warning', 'title' => 'Scrape ulasan tidak dapat dijalankan', 'body' => 'Tidak ada kredensial worker Google Maps aktif. Operator perlu menambahkan kredensial via /admin/worker-credentials di Hub.'],
        'credentials_stale'        => ['severity' => 'warning', 'title' => 'Sesi Google operator kedaluwarsa', 'body' => 'Operator perlu memperbarui session Google via Cookie-Editor lalu jalankan audit ulang.'],
        'rate_limited'             => ['severity' => 'warning', 'title' => 'Scrape ulasan di-rate-limit', 'body' => 'Worker membatasi scrape 1× per URL per 5 menit. Coba jalankan audit ulang dalam beberapa menit.'],
        'place_not_found'          => ['severity' => 'warning', 'title' => 'Listing tempat tidak ditemukan', 'body' => 'URL Google Maps tidak menampilkan listing tempat.'],
        'captcha_blocked'          => ['severity' => 'warning', 'title' => 'Google mendeteksi aktivitas tidak biasa', 'body' => 'Worker mendapat CAPTCHA / consent interstitial. Operator perlu rotate kredensial atau ganti IP.'],
        'scrape_failed'            => ['severity' => 'failure', 'title' => 'Scrape ulasan gagal', 'body' => 'Terjadi error tidak terduga di pipeline scrape.'],
        'legacy_places_api_only'   => ['severity' => 'info',    'title' => 'Audit lawas, sampel 5 ulasan', 'body' => 'Audit ini menggunakan sampel 5 ulasan dari Google Places. Audit baru akan menggunakan scraping ulasan lengkap.'],
    ];

    $banner = null;
    if ($status !== 'done') {
        $banner = $statusBannerMap[$status] ?? null;
        if ($banner === null) {
            return;
        }
    }

    $reviews          = (array) ($payload['reviews'] ?? []);
    $businessName     = (string) ($payload['business_name'] ?? '');
    $rating           = $payload['rating'] ?? null;
    $totalReviewCount = $payload['total_review_count'] ?? null;
    $scrapedAt        = (string) ($payload['scraped_at'] ?? '');

    $breakdown    = (array) ($audit->score_breakdown ?? []);
    $expBreakdown = (array) ($breakdown['experience'] ?? []);
    $penalties    = (array) ($expBreakdown['penalties'] ?? []);
    $totalPenalty = (int) ($penalties['total'] ?? 0);
@endphp

<div class="mb-12" x-data="{ showAll: false, showPenalties: false }">
    <div class="flex items-end justify-between mb-6 flex-wrap gap-3">
        <div>
            <p style="font-size: 11px; color: var(--text-tertiary); letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 4px;">
                Ulasan Pelanggan dari Google Maps
            </p>
            <h3 style="font-size: 22px; font-weight: 600; color: var(--text-primary); margin: 0;">
                {{ $businessName !== '' ? $businessName : 'Google Maps' }}
            </h3>
        </div>
        @if ($status === 'done')
            <p style="font-size: 12px; color: var(--text-tertiary); margin: 0;">
                ★ {{ $rating ?? '?' }} · {{ $totalReviewCount ?? '?' }} ulasan · {{ count($reviews) }} diambil
            </p>
        @endif
    </div>

    @if ($banner !== null)
        @php
            $palette = match ($banner['severity']) {
                'failure' => ['border' => 'var(--color-danger)',  'bg' => '#FBE6E2', 'title' => 'var(--color-danger)'],
                'warning' => ['border' => 'var(--color-warning)', 'bg' => '#FEF3DC', 'title' => 'var(--color-warning)'],
                default   => ['border' => 'var(--text-tertiary)', 'bg' => 'var(--surface-muted)', 'title' => 'var(--text-secondary)'],
            };
        @endphp
        <div style="border: 1px solid {{ $palette['border'] }}; background: {{ $palette['bg'] }}; padding: 14px 16px; border-radius: 12px; margin-bottom: 18px;">
            <p style="font-size: 13px; font-weight: 600; color: {{ $palette['title'] }}; margin: 0 0 4px 0;">{{ $banner['title'] }}</p>
            <p style="font-size: 12px; color: var(--text-secondary); margin: 0;">{{ $banner['body'] }}</p>
        </div>
    @endif

    @if ($status === 'done' && ! empty($reviews))
        <p style="font-size: 12px; color: var(--text-tertiary); margin-bottom: 14px;">
            {{ count($reviews) }} ulasan diambil dari Google Maps.
            @if ($scrapedAt !== '')
                @php
                    $scrapedAtCarbon = \Illuminate\Support\Carbon::parse($scrapedAt)->setTimezone('Asia/Jakarta');
                    $scrapedAtLabel  = $scrapedAtCarbon->locale('id')->translatedFormat('d F Y, H:i');
                @endphp
                Diambil pada {{ $scrapedAtLabel }} WIB.
            @endif
        </p>

        @foreach (array_slice($reviews, 0, 5) as $review)
            <div class="rounded-lg p-4 mb-3" style="background: var(--surface-muted); border: 1px solid var(--border-default);">
                <div class="flex items-baseline justify-between gap-2 mb-2">
                    <p style="font-size: 13px; font-weight: 600; color: var(--text-primary); margin: 0;">{{ $review['author'] !== '' ? $review['author'] : '—' }}</p>
                    <p style="font-size: 12px; margin: 0;">
                        <span style="color: var(--color-warning);">★ {{ $review['rating_value'] ?? '?' }}</span>
                        <span style="color: var(--text-tertiary); margin-left: 6px;">{{ $review['date_relative'] ?? '' }}</span>
                    </p>
                </div>
                <p style="font-size: 13px; color: var(--text-primary); line-height: 1.6; margin: 0;">
                    {{ \Illuminate\Support\Str::limit($review['text'] ?? '', 320) }}
                </p>
            </div>
        @endforeach

        @if (count($reviews) > 5)
            <button type="button" @click="showAll = ! showAll" class="text-sm font-medium" style="color: var(--text-link); margin-top: 8px;">
                <span x-show="! showAll">Lihat {{ count($reviews) - 5 }} ulasan lainnya</span>
                <span x-show="showAll" x-cloak>Sembunyikan</span>
            </button>
            <div x-show="showAll" x-cloak class="mt-3">
                @foreach (array_slice($reviews, 5) as $review)
                    <div class="rounded-lg p-4 mb-3" style="background: var(--surface-muted); border: 1px solid var(--border-default);">
                        <div class="flex items-baseline justify-between gap-2 mb-2">
                            <p style="font-size: 13px; font-weight: 600; color: var(--text-primary); margin: 0;">{{ $review['author'] !== '' ? $review['author'] : '—' }}</p>
                            <p style="font-size: 12px; margin: 0;">
                                <span style="color: var(--color-warning);">★ {{ $review['rating_value'] ?? '?' }}</span>
                                <span style="color: var(--text-tertiary); margin-left: 6px;">{{ $review['date_relative'] ?? '' }}</span>
                            </p>
                        </div>
                        <p style="font-size: 13px; color: var(--text-primary); line-height: 1.6; margin: 0;">
                            {{ \Illuminate\Support\Str::limit($review['text'] ?? '', 320) }}
                        </p>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($totalPenalty < 0)
            <div class="rounded-lg p-4 mt-6" style="border: 1px solid var(--color-danger); background: #FBE6E2;">
                <button type="button" @click="showPenalties = ! showPenalties" class="w-full text-left">
                    <p style="font-size: 13px; font-weight: 600; color: var(--color-danger); margin: 0;">
                        Penalty Brand Experience: {{ $totalPenalty }} poin dari ulasan
                    </p>
                    <p style="font-size: 12px; color: var(--text-secondary); margin: 4px 0 0 0;">
                        Berdasarkan {{ (int) ($penalties['reviews_scanned'] ?? 0) }} ulasan dianalisis · {{ (int) ($penalties['reviews_skipped_short'] ?? 0) }} ulasan terlalu pendek dilewati
                        · <span x-text="showPenalties ? 'Sembunyikan' : 'Lihat detail'" style="color: var(--text-link);"></span>
                    </p>
                </button>
                <div x-show="showPenalties" x-cloak class="mt-4">
                    @foreach ((array) ($penalties['per_type'] ?? []) as $key => $delta)
                        @php $evidence = (array) ($penalties['evidence'][$key] ?? []); @endphp
                        <div class="mt-3">
                            <p style="font-size: 12px; font-weight: 600; color: var(--text-primary); margin: 0;">
                                {{ \App\Support\AuditLabels::subBucket((string) $key) }} <span style="color: var(--color-danger);">({{ (int) $delta }} poin, {{ count($evidence) }} kecocokan)</span>
                            </p>
                            @foreach ($evidence as $ev)
                                <p style="font-size: 11px; color: var(--text-secondary); margin: 4px 0 0 12px; font-style: italic;">
                                    <strong>{{ $ev['author'] ?? 'Anonim' }}</strong> · ★{{ $ev['rating_value'] ?? '?' }} · "{{ $ev['matched_phrase'] ?? '' }}", {{ \Illuminate\Support\Str::limit($ev['text_snippet'] ?? '', 180) }}
                                </p>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</div>
