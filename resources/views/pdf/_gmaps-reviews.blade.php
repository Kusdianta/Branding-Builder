@php
    /**
     * Phase 8 BB28: GMaps reviews + experience-penalty section in the
     * brand-health PDF. Renders under the Brand Experience pillar to
     * surface the expanded review corpus that drove the BB25 +
     * BB26 scoring deltas.
     *
     * DomPDF constraints: inline styles only, HEX literals, DejaVu
     * Sans for unicode, <table> for layout (no flex/grid).
     *
     * Caller: $audit (BrandAudit model). Reads:
     *   $audit->gmaps_reviews_status  (string enum)
     *   $audit->gmaps_reviews         (array)
     *   $audit->score_breakdown[experience][penalties]  (BB27 stash)
     */

    $status  = (string) ($audit->gmaps_reviews_status ?? '');
    $payload = (array)  ($audit->gmaps_reviews ?? []);

    $statusBannerMap = [
        'pending'                  => ['severity' => 'info',    'title' => 'Scrape ulasan masih dalam proses', 'body' => 'Halaman akan diperbarui otomatis setelah selesai.'],
        'no_gmaps_url_provided'    => ['severity' => 'info',    'title' => 'Scrape ulasan dilewati', 'body' => 'URL Google Maps tidak diisi di form audit.'],
        'no_credentials_available' => ['severity' => 'warning', 'title' => 'Scrape ulasan tidak dapat dijalankan', 'body' => 'Tidak ada kredensial worker Google Maps aktif. Operator perlu menambahkan kredensial via /admin/worker-credentials di Hub.'],
        'credentials_stale'        => ['severity' => 'warning', 'title' => 'Sesi Google operator kedaluwarsa', 'body' => 'Operator perlu memperbarui session Google via Cookie-Editor lalu jalankan audit ulang.'],
        'rate_limited'             => ['severity' => 'warning', 'title' => 'Scrape ulasan di-rate-limit', 'body' => 'Worker membatasi scrape 1× per URL per 5 menit. Coba jalankan audit ulang dalam beberapa menit.'],
        'place_not_found'          => ['severity' => 'warning', 'title' => 'Listing tempat tidak ditemukan', 'body' => 'URL Google Maps tidak menampilkan listing tempat. Periksa kembali URL.'],
        'captcha_blocked'          => ['severity' => 'warning', 'title' => 'Google mendeteksi aktivitas tidak biasa', 'body' => 'Worker mendapat CAPTCHA / consent interstitial. Operator perlu rotate kredensial atau ganti IP.'],
        'scrape_failed'            => ['severity' => 'failure', 'title' => 'Scrape ulasan gagal', 'body' => 'Terjadi error tidak terduga di pipeline scrape. Periksa logs untuk detail.'],
        'legacy_places_api_only'   => ['severity' => 'info',    'title' => 'Audit lawas — sample 5 ulasan', 'body' => 'Audit ini menggunakan sample 5 ulasan dari Places API. Audit baru akan menggunakan scraping ulasan lengkap.'],
    ];

    $banner = null;
    if ($status !== 'done') {
        $banner = $statusBannerMap[$status] ?? null;
        if ($banner === null) {
            return; // unknown status — render nothing
        }
    }

    $reviews          = (array) ($payload['reviews'] ?? []);
    $businessName     = (string) ($payload['business_name'] ?? '');
    $rating           = $payload['rating'] ?? null;
    $totalReviewCount = $payload['total_review_count'] ?? null;
    $scrapedAt        = (string) ($payload['scraped_at'] ?? '');

    // Penalty payload from BB27 (only present when scrape ran).
    $breakdown    = (array) ($audit->score_breakdown ?? []);
    $expBreakdown = (array) ($breakdown['experience'] ?? []);
    $penalties    = (array) ($expBreakdown['penalties'] ?? []);

    $bannerColors = [
        'info'    => ['border' => '#9CC393', 'bg' => '#F0F4EE', 'title' => '#3D6E89'],
        'warning' => ['border' => '#C97A1B', 'bg' => '#FBF1E0', 'title' => '#C97A1B'],
        'failure' => ['border' => '#C24E3A', 'bg' => '#FBE6E2', 'title' => '#C24E3A'],
    ];
@endphp

<div style="page-break-before: always;"></div>

<table style="margin-bottom: 18px;">
    <tr>
        <td>
            <p style="font-size: 8px; color: #8A9088; margin: 0; letter-spacing: 0.5px;">ULASAN PELANGGAN — GOOGLE MAPS</p>
            <h2 style="font-size: 22px; color: #0F1411; margin: 4px 0 0 0;">
                {{ $businessName !== '' ? $businessName : 'Google Maps Reviews' }}
            </h2>
        </td>
    </tr>
</table>

@if ($banner !== null)
    @php $bc = $bannerColors[$banner['severity']] ?? $bannerColors['info']; @endphp
    <div style="border: 1px solid {{ $bc['border'] }}; background: {{ $bc['bg'] }}; padding: 14px 16px; border-radius: 8px; margin-bottom: 18px;">
        <p style="font-size: 12px; font-weight: 600; color: {{ $bc['title'] }}; margin: 0 0 4px 0;">{{ $banner['title'] }}</p>
        <p style="font-size: 11px; color: #5A6259; margin: 0;">{{ $banner['body'] }}</p>
    </div>
@endif

@if ($status === 'done' && ! empty($reviews))
    <table style="margin-bottom: 14px; width: 100%;">
        <tr>
            <td style="font-size: 11px; color: #5A6259;">
                ★ {{ $rating ?? '?' }}
                · {{ $totalReviewCount ?? '?' }} ulasan total di Google
                · {{ count($reviews) }} ulasan diambil
                @if ($scrapedAt !== '')
                    · {{ \Illuminate\Support\Carbon::parse($scrapedAt)->setTimezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
                @endif
            </td>
        </tr>
    </table>

    <h3 style="font-size: 14px; color: #0F1411; margin: 20px 0 8px 0;">Sample ulasan terbaru</h3>
    @foreach (array_slice($reviews, 0, 5) as $review)
        <table style="border: 1px solid #E5E5E5; padding: 0; margin-bottom: 10px; width: 100%; page-break-inside: avoid;">
            <tr>
                <td style="padding: 10px 12px;">
                    <p style="font-size: 11px; color: #0F1411; margin: 0;">
                        <strong>{{ $review['author'] !== '' ? $review['author'] : '—' }}</strong>
                        <span style="color: #C97A1B;"> ★ {{ $review['rating_value'] ?? '?' }}</span>
                        <span style="color: #8A9088;"> · {{ $review['date_relative'] ?? '' }}</span>
                    </p>
                    <p style="font-size: 10px; color: #5A6259; margin: 6px 0 0 0; line-height: 1.5;">
                        {{ \Illuminate\Support\Str::limit($review['text'] ?? '', 280) }}
                    </p>
                </td>
            </tr>
        </table>
    @endforeach

    @php $totalPenalty = (int) ($penalties['total'] ?? 0); @endphp
    @if ($totalPenalty < 0)
        <h3 style="font-size: 14px; color: #C24E3A; margin: 24px 0 8px 0;">Penalty Brand Experience dari ulasan</h3>
        <p style="font-size: 11px; color: #5A6259; margin: 0 0 10px 0;">
            Total {{ $totalPenalty }} poin dipotong dari skor Brand Experience setelah analisis kata kunci di {{ (int) ($penalties['reviews_scanned'] ?? 0) }} ulasan ({{ (int) ($penalties['reviews_skipped_short'] ?? 0) }} ulasan terlalu pendek dilewati).
        </p>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 14px;">
            <thead>
                <tr style="background: #F7F9F5;">
                    <th style="text-align: left; padding: 8px 10px; font-size: 10px; color: #5A6259;">Penalty</th>
                    <th style="text-align: right; padding: 8px 10px; font-size: 10px; color: #5A6259;">Delta</th>
                    <th style="text-align: right; padding: 8px 10px; font-size: 10px; color: #5A6259;">Jumlah Match</th>
                </tr>
            </thead>
            <tbody>
                @foreach ((array) ($penalties['per_type'] ?? []) as $key => $delta)
                    <tr>
                        <td style="padding: 6px 10px; font-size: 10px; color: #0F1411; border-top: 1px solid #E5E5E5;">{{ $key }}</td>
                        <td style="padding: 6px 10px; font-size: 10px; color: #C24E3A; text-align: right; border-top: 1px solid #E5E5E5;">{{ (int) $delta }}</td>
                        <td style="padding: 6px 10px; font-size: 10px; color: #5A6259; text-align: right; border-top: 1px solid #E5E5E5;">{{ count((array) ($penalties['evidence'][$key] ?? [])) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endif
