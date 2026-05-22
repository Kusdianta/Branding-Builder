@php
    /**
     * Phase 7-C BB14: IG profile audit section in the brand-health PDF.
     *
     * Renders Claude's 11-key apikprimadya-style analysis output (plus the
     * worker's _meta record from BB13) as a 6-7 page section appended to
     * the 4-pillar audit. Section is gated to status='done' for BB14;
     * BB16 layers in the degraded-data banner for partial states.
     *
     * DomPDF constraints (mirrors the parent template at line 1-9):
     *  - Inline styles only; no external CSS, no @import.
     *  - HEX color literals only; no var(--token).
     *  - 'DejaVu Sans' family for full Indonesian unicode support.
     *  - No flexbox / grid; <table> for any column layout.
     *  - Page breaks via <div style="page-break-before: always;">.
     *  - page-break-inside: avoid on cards to minimise mid-card splits.
     *
     * Caller binds: $audit (BrandAudit model). Reads:
     *  $audit->instagram_audit_status  (string enum)
     *  $audit->instagram_audit         (array, the Claude output + _meta)
     */

    $igStatus  = (string) ($audit->instagram_audit_status ?? '');
    $igAudit   = (array) ($audit->instagram_audit ?? []);

    // BB16: banner state computed before the section gate. For non-done
    // statuses we render only the section header + banner; for done with
    // auto-backfill or followers=0, we render the banner above the
    // normal section content.
    //
    // Status enum translations + banner severity (amber=warning,
    // grey=info, red=failure). Done-with-warning paths use amber.
    $statusBannerMap = [
        'pending'                   => ['severity' => 'info',    'title' => 'Audit Instagram masih dalam proses', 'body' => 'Halaman akan diperbarui otomatis setelah audit selesai.'],
        'no_instagram_url_provided' => ['severity' => 'info',    'title' => 'Audit Instagram dilewati', 'body' => 'URL Instagram tidak diisi di form audit. Untuk mendapatkan analisis Instagram, ulangi audit dengan mengisi field Instagram.'],
        'no_credentials_available'  => ['severity' => 'warning', 'title' => 'Audit Instagram tidak dapat dijalankan', 'body' => 'Tidak ada kredensial worker Instagram yang aktif di sistem. Operator perlu menambahkan kredensial via /admin/worker-credentials di Hub.'],
        'credentials_stale'         => ['severity' => 'warning', 'title' => 'Audit Instagram gagal — sesi operator kedaluwarsa', 'body' => 'Kredensial Instagram operator sudah kedaluwarsa dan tidak diterima oleh Instagram. Operator perlu memperbarui session via Cookie-Editor lalu jalankan audit ulang.'],
        'rate_limited'              => ['severity' => 'warning', 'title' => 'Audit Instagram di-rate-limit', 'body' => 'Worker membatasi audit 1× per username per 5 menit. Coba jalankan audit ulang dalam beberapa menit.'],
        'profile_not_found'         => ['severity' => 'warning', 'title' => 'Username Instagram tidak ditemukan', 'body' => 'Profile Instagram dengan handle yang dimasukkan tidak ditemukan. Periksa kembali ejaan username dan URL.'],
        'audit_failed'              => ['severity' => 'failure', 'title' => 'Audit Instagram gagal karena error teknis', 'body' => 'Terjadi error tidak terduga di pipeline audit. Periksa logs untuk detail dan jalankan audit ulang.'],
    ];

    $banner = null;
    if ($igStatus !== 'done') {
        $banner = $statusBannerMap[$igStatus] ?? null;
        // BB132 — mirror the dashboard view's per-error-code refinement
        // so the PDF surface tells the same story (worker timeout vs.
        // auth vs. analysis vs. generic).
        if ($banner !== null && $igStatus === 'audit_failed') {
            $errDetail = (string) ($igAudit['error'] ?? '');
            if (str_starts_with($errDetail, 'worker_unavailable')) {
                $banner = [
                    'severity' => 'warning',
                    'title'    => 'Audit Instagram gagal — worker tidak merespons',
                    'body'     => 'Worker Instagram tidak menjawab dalam waktu yang tersedia (timeout). Jalankan ulang dalam 1–2 menit; jika berulang, periksa worker via /admin/worker-health.',
                ];
            } elseif (str_starts_with($errDetail, 'worker_auth_failed')) {
                $banner = [
                    'severity' => 'warning',
                    'title'    => 'Audit Instagram gagal — autentikasi worker',
                    'body'     => 'Worker menolak request audit (token salah atau kedaluwarsa). Operator perlu memeriksa konfigurasi WORKER_AUTH_TOKEN.',
                ];
            } elseif (str_starts_with($errDetail, 'claude_analysis_failed')) {
                $banner = [
                    'severity' => 'warning',
                    'title'    => 'Audit Instagram tersaji sebagian — analisis AI gagal',
                    'body'     => 'Scraping berhasil, analisis AI gagal. Data mentah tersimpan; coba jalankan ulang untuk regenerasi.',
                ];
            }
        }
        // BB14 contract preserved: no IG audit at all → render nothing.
        // The bottom-summary footer is enough context for the operator;
        // a banner inside the document for "we never tried" adds noise.
        if ($banner === null) {
            return;
        }
    } else {
        // Done path: detect partial-data symptoms.
        $limitationsRaw = (array) ($igAudit['limitations'] ?? []);
        $hasAutoBackfill = false;
        foreach ($limitationsRaw as $lim) {
            if (is_string($lim) && str_starts_with($lim, 'Auto-backfilled missing top-level key')) {
                $hasAutoBackfill = true;
                break;
            }
        }
        $metaForBanner = (array) ($igAudit['_meta'] ?? []);
        $hasZeroFollowers = (int) ($metaForBanner['followers'] ?? 0) === 0 && (int) ($metaForBanner['posts_count'] ?? 0) === 0;

        if ($hasAutoBackfill || $hasZeroFollowers) {
            $banner = [
                'severity' => 'warning',
                'title'    => 'Audit Instagram tersaji dengan data terbatas',
                'body'     => $hasZeroFollowers
                    ? 'Worker tidak berhasil mengekstrak data follower/post — analisis berbasis profile shell saja, akurasi keseluruhan rendah. Penyebab umum: cookie operator terlalu lemah atau IP terdeteksi anti-bot.'
                    : 'Sebagian section di audit Instagram di-auto-backfill karena Claude tidak mengembalikan semua field. Konten tetap tersaji namun beberapa rekomendasi mungkin kurang lengkap dari biasanya.',
            ];
        }
    }

    // Skip the full section render entirely for non-done statuses; the
    // banner above is the only payload the document needs.
    if ($igStatus !== 'done' || $igAudit === []) {
        $renderFullSection = false;
    } else {
        $renderFullSection = true;
    }

    // Banner palette.
    $bannerColors = [
        'info'    => ['border' => '#5A6259', 'bg' => '#F7F9F5', 'text' => '#0F1411', 'titleClr' => '#5A6259'],
        'warning' => ['border' => '#C97A1B', 'bg' => '#FEF3DC', 'text' => '#0F1411', 'titleClr' => '#C97A1B'],
        'failure' => ['border' => '#C24E3A', 'bg' => '#FBE6E1', 'text' => '#0F1411', 'titleClr' => '#C24E3A'],
    ];

    $meta       = (array) ($igAudit['_meta'] ?? []);
    $analyzedAt = (string) ($igAudit['analyzed_at'] ?? '');
    try {
        $analyzedAtLabel = $analyzedAt !== ''
            ? (new \DateTimeImmutable($analyzedAt))->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('d M Y, H:i')
            : '—';
    } catch (\Throwable) {
        $analyzedAtLabel = $analyzedAt;
    }

    // Section sub-records.
    // BB131 — executive_summary may be a structured object (new) or a
    // flat string (audits created before BB131). Render both shapes.
    $execSummaryRaw    = $igAudit['executive_summary'] ?? '';
    $execIsStructured  = is_array($execSummaryRaw);
    $execHeadline      = $execIsStructured ? (string) ($execSummaryRaw['headline'] ?? '') : '';
    $execKekuatan      = $execIsStructured ? array_values(array_filter((array) ($execSummaryRaw['kekuatan'] ?? []))) : [];
    $execAreaPerbaikan = $execIsStructured ? array_values(array_filter((array) ($execSummaryRaw['area_perbaikan'] ?? []))) : [];
    $execKonteks       = $execIsStructured ? (string) ($execSummaryRaw['konteks'] ?? '') : '';
    $execSummaryString = $execIsStructured ? '' : (string) $execSummaryRaw;
    $hasExecStructured = $execHeadline !== '' || $execKekuatan !== [] || $execAreaPerbaikan !== [] || $execKonteks !== '';
    $profileBranding = (array) ($igAudit['profile_branding'] ?? []);
    $bioAnalysis     = (array) ($profileBranding['bio_analysis'] ?? []);
    $nameFieldSeo    = (array) ($profileBranding['name_field_seo'] ?? []);
    $highlightsAssessment = (string) ($profileBranding['highlights_assessment'] ?? '');

    $contentAnalysis        = (array) ($igAudit['content_analysis'] ?? []);
    $volumeFreq             = (string) ($contentAnalysis['volume_frequency_summary'] ?? '');
    $contentTypeBreakdown   = (array) ($contentAnalysis['content_type_breakdown'] ?? []);
    $contentPillars         = (array) ($contentAnalysis['content_pillars'] ?? []);
    $captionStyle           = (string) ($contentAnalysis['caption_style'] ?? '');
    $visualStyle            = (string) ($contentAnalysis['visual_style'] ?? '');

    $engagementAnalysis = (array) ($igAudit['engagement_analysis'] ?? []);
    $followerTier       = (string) ($engagementAnalysis['follower_tier'] ?? '');
    $estimatedErRange   = (string) ($engagementAnalysis['estimated_er_range'] ?? '');
    $estimationBasis    = (string) ($engagementAnalysis['estimation_basis'] ?? '');
    $communityNotes     = (string) ($engagementAnalysis['community_interaction_notes'] ?? '');

    $growthPositioning  = (array) ($igAudit['growth_positioning'] ?? []);
    $nicheClarityScore  = (int) ($growthPositioning['niche_clarity_score'] ?? 0);
    $brandClarityScore  = (int) ($growthPositioning['personal_brand_clarity_score'] ?? 0);
    $brandPillarStatus  = (array) ($growthPositioning['brand_pillar_status'] ?? []);

    $contentGaps             = (array) ($igAudit['content_gaps'] ?? []);
    $priorityRecommendations = (array) ($igAudit['priority_recommendations'] ?? []);
    $quickWins               = (array) ($igAudit['quick_wins'] ?? []);
    $competitivePositioning  = (string) ($igAudit['competitive_positioning'] ?? '');
    $scorecard               = (array) ($igAudit['scorecard'] ?? []);
    $limitations             = (array) ($igAudit['limitations'] ?? []);

    // Translation maps — keep local so the partial has no implicit deps.
    $priorityLabel = ['tinggi' => 'Tinggi', 'sedang' => 'Sedang', 'rendah' => 'Rendah'];
    $priorityColor = ['tinggi' => '#C24E3A', 'sedang' => '#C97A1B', 'rendah' => '#5A6259'];
    $priorityBg    = ['tinggi' => '#FBE6E1', 'sedang' => '#FEF3DC', 'rendah' => '#F7F9F5'];
    $effortImpactLabel = ['tinggi' => 'Tinggi', 'sedang' => 'Sedang', 'rendah' => 'Rendah'];
    $pillarStatusLabel = ['ada' => 'Ada', 'sebagian' => 'Sebagian', 'tidak_ada' => 'Tidak Ada'];
    $pillarStatusColor = ['ada' => '#3D8948', 'sebagian' => '#C97A1B', 'tidak_ada' => '#C24E3A'];
    $pillarStatusBg    = ['ada' => '#E8F1E5', 'sebagian' => '#FEF3DC', 'tidak_ada' => '#FBE6E1'];

    // Grade → HEX color (sub-score table cells + grade pills).
    $gradeColor = static fn (string $g): string => match (strtoupper($g)) {
        'A'     => '#3D8948',
        'B'     => '#326D3A',
        'C'     => '#C97A1B',
        'D'     => '#C97A1B',
        'F'     => '#C24E3A',
        default => '#5A6259',
    };
    $gradeBg = static fn (string $g): string => match (strtoupper($g)) {
        'A', 'B' => '#E8F1E5',
        'C', 'D' => '#FEF3DC',
        'F'      => '#FBE6E1',
        default  => '#F7F9F5',
    };

    // Scorecard sub-key → Indonesian label.
    $scorecardLabels = [
        'profile_bio_optimization'      => 'Profile / Bio Optimization',
        'content_quality_variety'       => 'Content Quality & Variety',
        'visual_consistency_aesthetics' => 'Visual Consistency & Aesthetics',
        'niche_clarity_positioning'     => 'Niche Clarity & Positioning',
        'engagement_strategy'           => 'Engagement Strategy',
        'personal_brand_storytelling'   => 'Personal Brand / Storytelling',
        'growth_potential'              => 'Growth Potential',
    ];

    // Content-type bar widths: clamp to total ~100 in case Claude's rounding
    // pushed off-spec. DomPDF won't render width > parent so cap each leg
    // at 100% individually.
    $ctReels    = max(0, min(100, (int) ($contentTypeBreakdown['reels']    ?? 0)));
    $ctCarousel = max(0, min(100, (int) ($contentTypeBreakdown['carousel'] ?? 0)));
    $ctStatic   = max(0, min(100, (int) ($contentTypeBreakdown['static']   ?? 0)));
@endphp

{{-- ========================== IG AUDIT — SECTION HEADER ========================== --}}
<div style="page-break-before: always;"></div>

<table style="margin-bottom: 18px;">
    <tr>
        <td style="width: 70%;">
            <p style="font-size: 8px; color: #8A9088; margin: 0; letter-spacing: 0.5px;">AUDIT PROFIL INSTAGRAM</p>
            <h2 style="font-size: 22px; color: #0F1411; margin: 4px 0 0 0;">
                @if (! empty($meta['username']))
                    @{{ $meta['username'] }}
                @else
                    Instagram
                @endif
            </h2>
        </td>
        <td style="width: 30%; text-align: right; vertical-align: bottom;">
            @if ($renderFullSection)
                <p style="font-size: 9px; color: #8A9088; margin: 0;">Diaudit</p>
                <p style="font-size: 10px; color: #5A6259; margin: 2px 0 0 0;">{{ $analyzedAtLabel }}</p>
            @endif
        </td>
    </tr>
</table>

{{-- ========================== STATUS / DEGRADED-DATA BANNER ========================== --}}
@if ($banner !== null)
    @php $bClrs = $bannerColors[$banner['severity']] ?? $bannerColors['info']; @endphp
    <div style="border: 1px solid {{ $bClrs['border'] }}; border-left: 4px solid {{ $bClrs['border'] }}; background: {{ $bClrs['bg'] }}; padding: 14px 18px; border-radius: 6px; margin-bottom: 18px;">
        <p style="font-size: 12px; font-weight: bold; color: {{ $bClrs['titleClr'] }}; margin: 0 0 6px 0;">⚠ {{ $banner['title'] }}</p>
        <p style="font-size: 10px; color: {{ $bClrs['text'] }}; margin: 0; line-height: 1.6;">{{ $banner['body'] }}</p>
    </div>
@endif

@if ($renderFullSection)

{{-- ========================== PROFILE HEADER CARD ========================== --}}
<div style="background: #F0F4EE; border: 1px solid rgba(15,20,17,0.08); border-radius: 12px; padding: 18px 20px; margin-bottom: 18px;">
    <table>
        <tr>
            <td style="width: 60%; vertical-align: top;">
                <p style="font-size: 14px; font-weight: bold; color: #0F1411; margin: 0;">
                    {{ $meta['name'] ?? '' }}
                    @if (! empty($meta['is_verified']))
                        <span style="font-size: 9px; font-weight: bold; color: #FFFFFF; background: #3D8948; padding: 2px 8px; border-radius: 10px; margin-left: 4px;">✓ Verified</span>
                    @endif
                    @if (! empty($meta['is_business']))
                        <span style="font-size: 9px; font-weight: bold; color: #5A6259; background: #E8F1E5; padding: 2px 8px; border-radius: 10px; margin-left: 4px;">Business</span>
                    @endif
                </p>
                @if (! empty($meta['bio']))
                    <p style="font-size: 10px; color: #5A6259; margin: 8px 0 0 0; line-height: 1.55; white-space: pre-line;">{{ $meta['bio'] }}</p>
                @endif
                @if (! empty($meta['external_url']))
                    <p style="font-size: 9px; color: #326D3A; margin: 8px 0 0 0; font-family: 'DejaVu Sans Mono', monospace; word-break: break-all;">↗ {{ $meta['external_url'] }}</p>
                @endif
            </td>
            <td style="width: 40%; vertical-align: top; padding-left: 16px;">
                <table>
                    <tr>
                        <td style="padding: 4px 0; font-size: 9px; color: #8A9088; width: 50%;">Followers</td>
                        <td style="padding: 4px 0; font-size: 14px; font-weight: bold; color: #0F1411; text-align: right;">{{ number_format((int) ($meta['followers'] ?? 0)) }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; font-size: 9px; color: #8A9088;">Following</td>
                        <td style="padding: 4px 0; font-size: 14px; font-weight: bold; color: #0F1411; text-align: right;">{{ number_format((int) ($meta['following'] ?? 0)) }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; font-size: 9px; color: #8A9088;">Total Post</td>
                        <td style="padding: 4px 0; font-size: 14px; font-weight: bold; color: #0F1411; text-align: right;">{{ number_format((int) ($meta['posts_count'] ?? 0)) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>

{{-- ========================== EXECUTIVE SUMMARY ========================== --}}
@if ($execIsStructured && $hasExecStructured)
    <h3 style="font-size: 13px; color: #0F1411; margin: 18px 0 8px 0;">Ringkasan Eksekutif</h3>
    @if ($execHeadline !== '')
        <p style="font-size: 11px; color: #0F1411; line-height: 1.6; margin: 0 0 8px 0; font-weight: 600;">{{ $execHeadline }}</p>
    @endif
    @if (! empty($execKekuatan))
        <p style="font-size: 10px; color: #3D8948; font-weight: 600; margin: 6px 0 2px 0;">Kekuatan</p>
        <ul style="margin: 0 0 6px 16px; padding: 0; font-size: 10px; color: #0F1411; line-height: 1.6;">
            @foreach ($execKekuatan as $item)<li>{{ $item }}</li>@endforeach
        </ul>
    @endif
    @if (! empty($execAreaPerbaikan))
        <p style="font-size: 10px; color: #C97A1B; font-weight: 600; margin: 6px 0 2px 0;">Area Perbaikan</p>
        <ul style="margin: 0 0 6px 16px; padding: 0; font-size: 10px; color: #0F1411; line-height: 1.6;">
            @foreach ($execAreaPerbaikan as $item)<li>{{ $item }}</li>@endforeach
        </ul>
    @endif
    @if ($execKonteks !== '')
        <p style="font-size: 10px; color: #0F1411; line-height: 1.7; margin: 4px 0 8px 0; white-space: pre-line;">{{ $execKonteks }}</p>
    @endif
@elseif (! $execIsStructured && $execSummaryString !== '')
    <h3 style="font-size: 13px; color: #0F1411; margin: 18px 0 8px 0;">Ringkasan Eksekutif</h3>
    <p style="font-size: 10px; color: #0F1411; line-height: 1.7; margin: 0 0 8px 0; white-space: pre-line;">{{ $execSummaryString }}</p>
@endif

{{-- ========================== PROFILE & BRANDING ========================== --}}
<div style="page-break-before: always;"></div>

<h2 style="font-size: 18px; color: #0F1411; margin: 0 0 4px 0;">Profil &amp; Branding</h2>
<p style="font-size: 10px; color: #8A9088; margin: 0 0 16px 0;">Audit bio, Name field SEO, dan struktur highlights</p>

@if (! empty($bioAnalysis))
    <h3 style="font-size: 12px; color: #0F1411; margin: 12px 0 8px 0;">◆ Analisis Bio</h3>

    @if (! empty($bioAnalysis['current_bio']))
        <p style="font-size: 9px; color: #8A9088; margin: 0 0 4px 0; font-weight: bold;">Bio saat ini:</p>
        <div style="background: #F7F9F5; border-left: 3px solid #8A9088; padding: 8px 12px; margin-bottom: 12px;">
            <p style="font-size: 10px; color: #0F1411; margin: 0; line-height: 1.6; white-space: pre-line;">{{ $bioAnalysis['current_bio'] }}</p>
        </div>
    @endif

    @if (! empty($bioAnalysis['strengths']))
        <p style="font-size: 9px; color: #3D8948; margin: 8px 0 4px 0; font-weight: bold;">Kekuatan:</p>
        @foreach ((array) $bioAnalysis['strengths'] as $s)
            <div style="border-left: 3px solid #3D8948; background: #E8F1E5; padding: 6px 10px; margin-bottom: 4px;">
                <p style="font-size: 9px; color: #0F1411; margin: 0; line-height: 1.55;">{{ $s }}</p>
            </div>
        @endforeach
    @endif

    @if (! empty($bioAnalysis['weaknesses']))
        <p style="font-size: 9px; color: #C97A1B; margin: 12px 0 4px 0; font-weight: bold;">Area Perbaikan:</p>
        @foreach ((array) $bioAnalysis['weaknesses'] as $w)
            <div style="border-left: 3px solid #C97A1B; background: #FEF3DC; padding: 6px 10px; margin-bottom: 4px;">
                <p style="font-size: 9px; color: #0F1411; margin: 0; line-height: 1.55;">{{ $w }}</p>
            </div>
        @endforeach
    @endif

    @if (! empty($bioAnalysis['recommended_bio']))
        <p style="font-size: 9px; color: #326D3A; margin: 12px 0 4px 0; font-weight: bold;">Rekomendasi Bio:</p>
        <div style="background: #E8F1E5; border: 1px solid #C6DDC0; border-radius: 6px; padding: 10px 12px; margin-bottom: 12px;">
            <p style="font-size: 10px; color: #0F1411; margin: 0; line-height: 1.6; white-space: pre-line;">{{ $bioAnalysis['recommended_bio'] }}</p>
        </div>
    @endif
@endif

@if (! empty($nameFieldSeo))
    <h3 style="font-size: 12px; color: #0F1411; margin: 16px 0 8px 0;">◆ Name Field SEO</h3>
    <table style="margin-bottom: 12px;">
        @if (! empty($nameFieldSeo['current']))
            <tr>
                <td style="width: 30%; padding: 4px 12px 4px 0; font-size: 9px; color: #8A9088; vertical-align: top;">Saat ini:</td>
                <td style="padding: 4px 0; font-size: 10px; color: #0F1411; font-family: 'DejaVu Sans Mono', monospace;">{{ $nameFieldSeo['current'] }}</td>
            </tr>
        @endif
        @if (! empty($nameFieldSeo['recommended']))
            <tr>
                <td style="padding: 4px 12px 4px 0; font-size: 9px; color: #326D3A; vertical-align: top; font-weight: bold;">Rekomendasi:</td>
                <td style="padding: 4px 0; font-size: 10px; color: #0F1411; font-family: 'DejaVu Sans Mono', monospace; background: #E8F1E5;">{{ $nameFieldSeo['recommended'] }}</td>
            </tr>
        @endif
    </table>
    @if (! empty($nameFieldSeo['assessment']))
        <p style="font-size: 10px; color: #0F1411; line-height: 1.65; margin: 0 0 12px 0;">{{ $nameFieldSeo['assessment'] }}</p>
    @endif
@endif

@if ($highlightsAssessment !== '')
    <h3 style="font-size: 12px; color: #0F1411; margin: 16px 0 8px 0;">◆ Penilaian Highlights</h3>
    <p style="font-size: 10px; color: #0F1411; line-height: 1.65; margin: 0;">{{ $highlightsAssessment }}</p>
@endif

{{-- ========================== ANALISIS KONTEN ========================== --}}
<div style="page-break-before: always;"></div>

<h2 style="font-size: 18px; color: #0F1411; margin: 0 0 4px 0;">Analisis Konten</h2>
<p style="font-size: 10px; color: #8A9088; margin: 0 0 16px 0;">Volume, frekuensi, pillar, dan gaya konten yang teridentifikasi</p>

@if ($volumeFreq !== '')
    <h3 style="font-size: 12px; color: #0F1411; margin: 12px 0 6px 0;">◆ Volume &amp; Frekuensi</h3>
    <p style="font-size: 10px; color: #0F1411; line-height: 1.65; margin: 0 0 12px 0;">{{ $volumeFreq }}</p>
@endif

<h3 style="font-size: 12px; color: #0F1411; margin: 16px 0 8px 0;">◆ Distribusi Tipe Konten</h3>
<table style="margin-bottom: 16px;">
    @foreach ([
        ['Reels',    $ctReels,    '#3D8948'],
        ['Carousel', $ctCarousel, '#326D3A'],
        ['Static',   $ctStatic,   '#8A9088'],
    ] as [$label, $pct, $clr])
        <tr>
            <td style="width: 18%; padding: 3px 12px 3px 0; font-size: 10px; color: #5A6259;">{{ $label }}</td>
            <td style="width: 62%; padding: 3px 0;">
                <div style="height: 10px; background: #E8F1E5; border-radius: 5px;">
                    <div style="height: 10px; background: {{ $clr }}; width: {{ $pct }}%; border-radius: 5px;"></div>
                </div>
            </td>
            <td style="width: 20%; padding: 3px 0 3px 12px; font-size: 10px; font-weight: bold; color: #0F1411; text-align: right;">{{ $pct }}%</td>
        </tr>
    @endforeach
</table>

@if (! empty($contentPillars))
    <h3 style="font-size: 12px; color: #0F1411; margin: 16px 0 8px 0;">◆ Content Pillars Terdeteksi</h3>
    @foreach ($contentPillars as $i => $pillar)
        @php
            $pName = (string) ($pillar['name'] ?? '');
            $pDesc = (string) ($pillar['description'] ?? '');
            $pExamples = (array) ($pillar['examples'] ?? []);
        @endphp
        <div style="border: 1px solid rgba(15,20,17,0.08); border-radius: 6px; padding: 10px 14px; margin-bottom: 8px; page-break-inside: avoid;">
            <p style="font-size: 11px; font-weight: bold; color: #0F1411; margin: 0 0 4px 0;">{{ $i + 1 }}. {{ $pName }}</p>
            @if ($pDesc !== '')
                <p style="font-size: 9px; color: #5A6259; margin: 0 0 6px 0; line-height: 1.6;">{{ $pDesc }}</p>
            @endif
            @if (! empty($pExamples))
                <p style="font-size: 8px; color: #8A9088; margin: 4px 0 2px 0; font-weight: bold;">Contoh:</p>
                <ul style="margin: 0 0 0 14px; padding: 0; font-size: 9px; color: #5A6259; line-height: 1.55;">
                    @foreach ($pExamples as $ex)
                        <li>{{ $ex }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endforeach
@endif

@if ($captionStyle !== '')
    <h3 style="font-size: 12px; color: #0F1411; margin: 16px 0 6px 0;">◆ Gaya Caption</h3>
    <p style="font-size: 10px; color: #0F1411; line-height: 1.65; margin: 0 0 12px 0;">{{ $captionStyle }}</p>
@endif

@if ($visualStyle !== '')
    <h3 style="font-size: 12px; color: #0F1411; margin: 12px 0 6px 0;">◆ Gaya Visual</h3>
    <p style="font-size: 10px; color: #0F1411; line-height: 1.65; margin: 0;">{{ $visualStyle }}</p>
@endif

{{-- ========================== ANALISIS ENGAGEMENT + GROWTH ========================== --}}
<div style="page-break-before: always;"></div>

<h2 style="font-size: 18px; color: #0F1411; margin: 0 0 4px 0;">Analisis Engagement</h2>
<p style="font-size: 10px; color: #8A9088; margin: 0 0 14px 0;">Tier, estimasi ER, dan kualitas interaksi komunitas</p>

<table style="margin-bottom: 14px;">
    @if ($followerTier !== '')
        <tr>
            <td style="width: 30%; padding: 4px 12px 4px 0; font-size: 9px; color: #8A9088; vertical-align: middle;">Tier Follower</td>
            <td style="padding: 4px 0;"><span style="font-size: 10px; font-weight: bold; color: #0F1411; background: #E8F1E5; padding: 3px 10px; border-radius: 10px; border: 1px solid #C6DDC0;">{{ $followerTier }}</span></td>
        </tr>
    @endif
    @if ($estimatedErRange !== '')
        <tr>
            <td style="padding: 4px 12px 4px 0; font-size: 9px; color: #8A9088; vertical-align: middle;">Estimasi ER</td>
            <td style="padding: 4px 0; font-size: 12px; font-weight: bold; color: #0F1411;">{{ $estimatedErRange }}</td>
        </tr>
    @endif
</table>

@if ($estimationBasis !== '')
    <h3 style="font-size: 11px; color: #5A6259; margin: 10px 0 6px 0;">Dasar Estimasi</h3>
    <p style="font-size: 9px; color: #0F1411; line-height: 1.65; margin: 0 0 10px 0;">{{ $estimationBasis }}</p>
@endif

@if ($communityNotes !== '')
    <h3 style="font-size: 11px; color: #5A6259; margin: 10px 0 6px 0;">Catatan Interaksi Komunitas</h3>
    <p style="font-size: 9px; color: #0F1411; line-height: 1.65; margin: 0;">{{ $communityNotes }}</p>
@endif

<h2 style="font-size: 18px; color: #0F1411; margin: 24px 0 4px 0;">Pertumbuhan &amp; Positioning</h2>
<p style="font-size: 10px; color: #8A9088; margin: 0 0 12px 0;">Kejelasan niche, brand clarity, dan status pillar</p>

<table style="margin-bottom: 14px;">
    <tr>
        <td style="width: 50%; padding: 6px 12px; font-size: 10px; color: #5A6259; background: #F7F9F5; border: 1px solid rgba(15,20,17,0.08);">Skor Kejelasan Niche</td>
        <td style="width: 50%; padding: 6px 12px; font-size: 14px; font-weight: bold; color: #0F1411; text-align: right; background: #F7F9F5; border: 1px solid rgba(15,20,17,0.08);">{{ $nicheClarityScore }} <span style="font-size: 9px; color: #8A9088; font-weight: normal;">/ 10</span></td>
    </tr>
    <tr>
        <td style="padding: 6px 12px; font-size: 10px; color: #5A6259; background: #F7F9F5; border: 1px solid rgba(15,20,17,0.08);">Skor Brand Clarity</td>
        <td style="padding: 6px 12px; font-size: 14px; font-weight: bold; color: #0F1411; text-align: right; background: #F7F9F5; border: 1px solid rgba(15,20,17,0.08);">{{ $brandClarityScore }} <span style="font-size: 9px; color: #8A9088; font-weight: normal;">/ 10</span></td>
    </tr>
</table>

@if (! empty($brandPillarStatus))
    <h3 style="font-size: 11px; color: #5A6259; margin: 12px 0 6px 0;">Status Pillar Brand</h3>
    <table style="border: 1px solid rgba(15,20,17,0.08); margin-bottom: 12px;">
        <thead>
            <tr style="background: #F7F9F5;">
                <th style="padding: 6px 10px; font-size: 9px; color: #5A6259; text-align: left; width: 35%;">Pillar</th>
                <th style="padding: 6px 10px; font-size: 9px; color: #5A6259; text-align: left; width: 15%;">Status</th>
                <th style="padding: 6px 10px; font-size: 9px; color: #5A6259; text-align: left;">Gap / Catatan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($brandPillarStatus as $bp)
                @php
                    $bpStatus = (string) ($bp['status'] ?? '');
                    $bpStatusKey = strtolower($bpStatus);
                    $sLabel = $pillarStatusLabel[$bpStatusKey] ?? $bpStatus;
                    $sClr   = $pillarStatusColor[$bpStatusKey] ?? '#5A6259';
                    $sBg    = $pillarStatusBg[$bpStatusKey] ?? '#F7F9F5';
                @endphp
                <tr style="border-top: 1px solid rgba(15,20,17,0.08);">
                    <td style="padding: 6px 10px; font-size: 9px; color: #0F1411; vertical-align: top;">{{ $bp['pillar'] ?? '' }}</td>
                    <td style="padding: 6px 10px; vertical-align: top;">
                        <span style="font-size: 8px; font-weight: bold; color: {{ $sClr }}; background: {{ $sBg }}; padding: 2px 8px; border-radius: 10px; border: 1px solid {{ $sClr }};">{{ $sLabel }}</span>
                    </td>
                    <td style="padding: 6px 10px; font-size: 9px; color: #5A6259; line-height: 1.55; vertical-align: top;">{{ $bp['gap'] ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- ========================== CONTENT GAPS ========================== --}}
<div style="page-break-before: always;"></div>

<h2 style="font-size: 18px; color: #0F1411; margin: 0 0 4px 0;">Kesenjangan Konten</h2>
<p style="font-size: 10px; color: #8A9088; margin: 0 0 14px 0;">Area konten yang absent atau kurang dieksekusi</p>

@foreach ($contentGaps as $i => $gap)
    @php
        $gCat = (string) ($gap['category'] ?? '');
        $gRationale = (string) ($gap['rationale'] ?? '');
        $gExample   = (string) ($gap['example_content_idea'] ?? '');
    @endphp
    <div style="border-left: 4px solid #C97A1B; padding: 8px 12px; margin-bottom: 10px; background: #FEF3DC; page-break-inside: avoid;">
        <p style="font-size: 11px; font-weight: bold; color: #0F1411; margin: 0 0 4px 0;">{{ $i + 1 }}. {{ $gCat }}</p>
        @if ($gRationale !== '')
            <p style="font-size: 8px; color: #8A9088; margin: 4px 0 2px 0; font-weight: bold;">Mengapa penting:</p>
            <p style="font-size: 9px; color: #0F1411; margin: 0 0 6px 0; line-height: 1.6;">{{ $gRationale }}</p>
        @endif
        @if ($gExample !== '')
            <p style="font-size: 8px; color: #8A9088; margin: 4px 0 2px 0; font-weight: bold;">Contoh ide konten:</p>
            <p style="font-size: 9px; color: #0F1411; margin: 0; line-height: 1.6; font-style: italic;">{{ $gExample }}</p>
        @endif
    </div>
@endforeach

{{-- ========================== PRIORITY RECOMMENDATIONS ========================== --}}
<div style="page-break-before: always;"></div>

<h2 style="font-size: 18px; color: #0F1411; margin: 0 0 4px 0;">Rekomendasi Prioritas (Instagram)</h2>
<p style="font-size: 10px; color: #8A9088; margin: 0 0 14px 0;">Tindakan utama untuk meningkatkan performa profil Instagram</p>

@foreach ($priorityRecommendations as $i => $rec)
    @php
        $rPriority = strtolower((string) ($rec['priority'] ?? 'rendah'));
        $rTitle = (string) ($rec['title'] ?? '');
        $rDesc = (string) ($rec['description'] ?? '');
        $rEffort = strtolower((string) ($rec['effort'] ?? ''));
        $rImpact = strtolower((string) ($rec['impact'] ?? ''));
        $pLabel = $priorityLabel[$rPriority] ?? ucfirst($rPriority);
        $pClr   = $priorityColor[$rPriority] ?? '#5A6259';
        $pBg    = $priorityBg[$rPriority] ?? '#F7F9F5';
    @endphp
    <div style="border: 1px solid rgba(15,20,17,0.08); border-radius: 8px; padding: 12px 16px; margin-bottom: 10px; page-break-inside: avoid;">
        <table style="margin-bottom: 6px;">
            <tr>
                <td>
                    <span style="font-size: 8px; font-weight: bold; color: {{ $pClr }}; background: {{ $pBg }}; padding: 2px 8px; border-radius: 10px; border: 1px solid {{ $pClr }};">Prioritas {{ $pLabel }}</span>
                    @if ($rEffort !== '' || $rImpact !== '')
                        <span style="font-size: 8px; color: #8A9088; margin-left: 8px;">
                            @if ($rEffort !== '') Effort: <strong style="color: #5A6259;">{{ $effortImpactLabel[$rEffort] ?? $rEffort }}</strong> @endif
                            @if ($rEffort !== '' && $rImpact !== '') · @endif
                            @if ($rImpact !== '') Impact: <strong style="color: #5A6259;">{{ $effortImpactLabel[$rImpact] ?? $rImpact }}</strong> @endif
                        </span>
                    @endif
                </td>
            </tr>
        </table>
        <p style="font-size: 12px; font-weight: bold; color: #0F1411; margin: 0 0 4px 0;">{{ $i + 1 }}. {{ $rTitle }}</p>
        @if ($rDesc !== '')
            <p style="font-size: 9px; color: #5A6259; margin: 0; line-height: 1.65;">{{ $rDesc }}</p>
        @endif
    </div>
@endforeach

{{-- ========================== QUICK WINS + COMPETITIVE + SCORECARD + LIMITATIONS ========================== --}}
<div style="page-break-before: always;"></div>

@if (! empty($quickWins))
    <h2 style="font-size: 18px; color: #0F1411; margin: 0 0 4px 0;">Quick Wins</h2>
    <p style="font-size: 10px; color: #8A9088; margin: 0 0 12px 0;">Aksi yang bisa dieksekusi &lt;1 minggu dengan effort rendah</p>
    <ul style="margin: 0 0 18px 18px; padding: 0; font-size: 10px; color: #0F1411; line-height: 1.65;">
        @foreach ($quickWins as $qw)
            <li style="margin-bottom: 4px;">{{ $qw }}</li>
        @endforeach
    </ul>
@endif

@if ($competitivePositioning !== '')
    <h2 style="font-size: 18px; color: #0F1411; margin: 20px 0 4px 0;">Positioning Kompetitif</h2>
    <p style="font-size: 10px; color: #0F1411; line-height: 1.7; margin: 8px 0 18px 0;">{{ $competitivePositioning }}</p>
@endif

{{-- Scorecard --}}
@if (! empty($scorecard))
    <h2 style="font-size: 18px; color: #0F1411; margin: 20px 0 8px 0;">Scorecard Audit</h2>
    <table style="border: 1px solid rgba(15,20,17,0.08); margin-bottom: 18px;">
        <thead>
            <tr style="background: #F7F9F5;">
                <th style="padding: 8px 12px; font-size: 9px; color: #5A6259; text-align: left; width: 60%;">Kategori</th>
                <th style="padding: 8px 12px; font-size: 9px; color: #5A6259; text-align: right; width: 20%;">Skor</th>
                <th style="padding: 8px 12px; font-size: 9px; color: #5A6259; text-align: center; width: 20%;">Grade</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($scorecardLabels as $key => $label)
                @php
                    $cell  = (array) ($scorecard[$key] ?? []);
                    $score = $cell['score'] ?? 0;
                    $grade = (string) ($cell['grade'] ?? 'F');
                    $gClr  = $gradeColor($grade);
                    $gBg   = $gradeBg($grade);
                @endphp
                <tr style="border-top: 1px solid rgba(15,20,17,0.08); background: {{ $gBg }};">
                    <td style="padding: 6px 12px; font-size: 10px; color: #0F1411;">{{ $label }}</td>
                    <td style="padding: 6px 12px; font-size: 11px; font-weight: bold; color: #0F1411; text-align: right;">{{ $score }} <span style="font-size: 8px; color: #8A9088; font-weight: normal;">/ 10</span></td>
                    <td style="padding: 6px 12px; text-align: center;">
                        <span style="font-size: 10px; font-weight: bold; color: #FFFFFF; background: {{ $gClr }}; padding: 2px 10px; border-radius: 10px; display: inline-block; min-width: 18px; text-align: center;">{{ $grade }}</span>
                    </td>
                </tr>
            @endforeach
            @php
                $overall      = (array) ($scorecard['overall'] ?? []);
                $overallScore = $overall['score'] ?? 0;
                $overallGrade = (string) ($overall['grade'] ?? 'F');
                $oClr  = $gradeColor($overallGrade);
                $oBg   = $gradeBg($overallGrade);
            @endphp
            <tr style="border-top: 2px solid #0F1411; background: #0F1411;">
                <td style="padding: 10px 12px; font-size: 11px; font-weight: bold; color: #F0F4EE; letter-spacing: 0.5px;">OVERALL <span style="font-size: 8px; color: #8A9088; font-weight: normal;">(rata-rata server-side)</span></td>
                <td style="padding: 10px 12px; font-size: 14px; font-weight: bold; color: #F0F4EE; text-align: right;">{{ $overallScore }} <span style="font-size: 9px; color: #8A9088; font-weight: normal;">/ 10</span></td>
                <td style="padding: 10px 12px; text-align: center;">
                    <span style="font-size: 12px; font-weight: bold; color: #FFFFFF; background: {{ $oClr }}; padding: 4px 14px; border-radius: 12px; display: inline-block; min-width: 22px; text-align: center;">{{ $overallGrade }}</span>
                </td>
            </tr>
        </tbody>
    </table>
    {{-- BB17: scorecard transparency — how the 7 sub-scores were derived. --}}
    <p style="font-size: 8px; color: #8A9088; margin: 0 0 16px; font-style: italic; line-height: 1.55;">
        ▸ <strong>Cara Perhitungan:</strong> Sumber — hasil scrape worker (profile + 12 post + 6 caption + 6 highlight) + analisis Claude Sonnet 4.6. Formula — penilaian LLM 0–10 berdasarkan rubrik kalibrasi pasar laundry Indonesia (9–10 best-in-class, 7–8 solid, 5–6 baseline, 3–4 gap signifikan, 0–2 absen). Overall dihitung server-side sebagai rata-rata sederhana dari 7 sub-skor.
    </p>
@endif

{{-- Limitations --}}
@if (! empty($limitations))
    <h3 style="font-size: 11px; color: #8A9088; margin: 18px 0 4px 0;">Keterbatasan Analisis</h3>
    <p style="font-size: 8px; color: #8A9088; margin: 0 0 8px 0; font-style: italic;">Transparansi mengenai data yang tidak tersedia atau yang mungkin tidak lengkap</p>
    <ul style="margin: 0 0 0 16px; padding: 0; font-size: 9px; color: #5A6259; line-height: 1.6;">
        @foreach ($limitations as $lim)
            <li style="margin-bottom: 3px;">{{ $lim }}</li>
        @endforeach
    </ul>
@endif

@endif {{-- /renderFullSection --}}
