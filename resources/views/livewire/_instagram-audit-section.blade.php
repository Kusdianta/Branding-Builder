@php
    /**
     * Phase 7-C BB15: IG profile audit section in the brand-audit dashboard.
     *
     * Mirrors pdf/_instagram-audit.blade.php structurally — same data shape,
     * different renderer. Tailwind + design-token CSS + Alpine.js accordion
     * instead of DomPDF inline-HEX tables. Default-collapsed everything
     * except executive_summary + scorecard, per the BB14/15 sketch.
     *
     * Caller binds:
     *   $instagramAudit       array, the Claude 11-key payload + _meta
     *   $instagramAuditStatus string enum
     *
     * Gate: BB15 renders the full section only when status='done'.
     * BB16 adds the status-aware degraded-data banner on top.
     */

    $igStatus = (string) ($instagramAuditStatus ?? '');
    $igAudit  = (array) ($instagramAudit ?? []);

    // BB16: status-aware banner state. Same logic as pdf/_instagram-audit
    // — extracted here so PHP-side rendering stays identical across surfaces.
    $statusBannerMap = [
        'pending'                   => ['severity' => 'info',    'title' => 'Audit Instagram masih dalam proses', 'body' => 'Halaman akan diperbarui otomatis setelah audit selesai.'],
        'no_instagram_url_provided' => ['severity' => 'info',    'title' => 'Audit Instagram dilewati', 'body' => 'URL Instagram tidak diisi di form audit. Untuk mendapatkan analisis Instagram, ulangi audit dengan mengisi field Instagram.'],
        'no_credentials_available'  => ['severity' => 'warning', 'title' => 'Audit Instagram tidak dapat dijalankan', 'body' => 'Tidak ada kredensial worker Instagram yang aktif di sistem. Operator perlu menambahkan kredensial via /admin/worker-credentials di Hub.'],
        'credentials_stale'         => ['severity' => 'warning', 'title' => 'Audit Instagram gagal, sesi operator kedaluwarsa', 'body' => 'Kredensial Instagram operator sudah kedaluwarsa dan tidak diterima oleh Instagram. Operator perlu memperbarui session via Cookie-Editor lalu jalankan audit ulang.'],
        'rate_limited'              => ['severity' => 'warning', 'title' => 'Audit Instagram di-rate-limit', 'body' => 'Worker membatasi audit 1× per username per 5 menit. Coba jalankan audit ulang dalam beberapa menit.'],
        'profile_not_found'         => ['severity' => 'warning', 'title' => 'Username Instagram tidak ditemukan', 'body' => 'Profile Instagram dengan handle yang dimasukkan tidak ditemukan. Periksa kembali ejaan username dan URL.'],
        'audit_failed'              => ['severity' => 'failure', 'title' => 'Audit Instagram gagal karena error teknis', 'body' => 'Terjadi error tidak terduga di pipeline audit. Periksa logs untuk detail dan jalankan audit ulang.'],
    ];

    $banner = null;
    if ($igStatus !== 'done') {
        $banner = $statusBannerMap[$igStatus] ?? null;
        // BB132 — `audit_failed` is a catch-all bucket. The stored
        // instagram_audit.error carries the actual failure code
        // (worker_unavailable / worker_auth_failed / claude_analysis_failed /
        // raw exception). Surface the friendlier wording so operators
        // don't all see the same "error teknis" banner regardless of
        // root cause. Falls through to the generic banner when the
        // detail prefix isn't recognised.
        if ($banner !== null && $igStatus === 'audit_failed') {
            $errDetail = (string) ($igAudit['error'] ?? '');
            if (str_starts_with($errDetail, 'worker_unavailable')) {
                $banner = [
                    'severity' => 'warning',
                    'title'    => 'Audit Instagram gagal — worker tidak merespons',
                    'body'     => 'Worker Instagram tidak menjawab dalam waktu yang tersedia (timeout). Worker mungkin sedang restart atau menangani audit lain. Jalankan ulang dalam 1–2 menit; jika berulang, periksa worker via /admin/worker-health.',
                ];
            } elseif (str_starts_with($errDetail, 'worker_auth_failed')) {
                $banner = [
                    'severity' => 'warning',
                    'title'    => 'Audit Instagram gagal — autentikasi worker',
                    'body'     => 'Worker menolak request audit (token salah atau kedaluwarsa). Operator perlu memeriksa konfigurasi WORKER_AUTH_TOKEN di Hub dan spoke.',
                ];
            } elseif (str_starts_with($errDetail, 'analysis_incomplete')) {
                // BB147 — the analysis was INTERRUPTED before it finished
                // (worker restart / re-reservation / timeout). This is NOT a
                // Claude error or quota issue — say so honestly so the
                // operator just re-runs instead of chasing a phantom API fault.
                $banner = [
                    'severity' => 'warning',
                    'title'    => 'Audit Instagram belum selesai',
                    'body'     => 'Scraping Instagram berhasil, tetapi analisis AI terhenti sebelum selesai (proses dihentikan / worker di-restart) — bukan karena error atau kuota Claude. Data mentah tersimpan; jalankan ulang untuk melengkapi analisis.',
                ];
            } elseif (str_starts_with($errDetail, 'claude_analysis_failed')) {
                $banner = [
                    'severity' => 'warning',
                    'title'    => 'Audit Instagram tersaji sebagian — analisis AI gagal',
                    'body'     => 'Scraping Instagram berhasil, namun analisis AI gagal diselesaikan (Claude error / kuota habis). Data mentah tersimpan; coba jalankan ulang untuk regenerasi analisis.',
                ];
            }
        }
        if ($banner === null) {
            return;
        }
    } else {
        if ($igAudit === []) {
            return;
        }
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

    $renderFullSection = ($igStatus === 'done' && $igAudit !== []);

    // Dashboard palette uses design tokens via var() where possible.
    $bannerStyles = [
        'info'    => ['border' => 'var(--text-tertiary)', 'bg' => 'var(--surface-muted)', 'title' => 'var(--text-secondary)'],
        'warning' => ['border' => 'var(--color-warning)', 'bg' => '#FEF3DC',              'title' => 'var(--color-warning)'],
        'failure' => ['border' => 'var(--color-danger)',  'bg' => '#FBE6E1',              'title' => 'var(--color-danger)'],
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

    // BB131 — executive_summary is now a structured object
    // {headline, kekuatan[], area_perbaikan[], konteks}. Audits created
    // before BB131 persisted a flat string; render both shapes.
    $execSummaryRaw    = $igAudit['executive_summary'] ?? '';
    $execIsStructured  = is_array($execSummaryRaw);
    $execHeadline      = $execIsStructured ? (string) ($execSummaryRaw['headline'] ?? '') : '';
    $execKekuatan      = $execIsStructured ? array_values(array_filter((array) ($execSummaryRaw['kekuatan'] ?? []))) : [];
    $execAreaPerbaikan = $execIsStructured ? array_values(array_filter((array) ($execSummaryRaw['area_perbaikan'] ?? []))) : [];
    $execKonteks       = $execIsStructured ? (string) ($execSummaryRaw['konteks'] ?? '') : '';
    $execSummaryString = $execIsStructured ? '' : (string) $execSummaryRaw;
    $hasExecStructured = $execHeadline !== '' || $execKekuatan !== [] || $execAreaPerbaikan !== [] || $execKonteks !== '';

    // BB131 + BB133 — screenshot proof. PNGs are on the private disk; we
    // serve each section through the token-scoped audit.instagram-screenshot
    // route. BB133 captures up to three sections (profile / feed / reels);
    // pre-BB133 audits only recorded the single screenshot_path → render it
    // as the lone "Profil" tab.
    $sessionTokenForProof = (string) ($sessionToken ?? '');
    $screenshotPathsRaw   = (array) ($meta['screenshot_paths'] ?? []);
    $proofSections        = [];
    if ($sessionTokenForProof !== '') {
        // BB139 Fix D — Feed tab dropped: its screenshot duplicated Profile.
        // The worker still captures and persists feed.png; the dashboard
        // simply doesn't list it (≈400KB/audit storage, acceptable trade-off
        // vs. removing worker logic in this ticket's scope).
        foreach (['profile' => 'Profil', 'reels' => 'Reels'] as $section => $label) {
            if (! empty($screenshotPathsRaw[$section])) {
                $proofSections[$section] = [
                    'label' => $label,
                    'url'   => route('audit.instagram-screenshot', [
                        'token'   => $sessionTokenForProof,
                        'section' => $section,
                    ]),
                ];
            }
        }
        // Back-compat: pre-BB133 audits only have the single screenshot_path.
        if ($proofSections === [] && ! empty($meta['screenshot_path'])) {
            $proofSections['profile'] = [
                'label' => 'Profil',
                'url'   => route('audit.instagram-screenshot', ['token' => $sessionTokenForProof]),
            ];
        }
    }
    $proofDefaultTab = $proofSections !== [] ? array_key_first($proofSections) : '';

    $profileBranding        = (array) ($igAudit['profile_branding'] ?? []);
    $bioAnalysis            = (array) ($profileBranding['bio_analysis'] ?? []);
    $nameFieldSeo           = (array) ($profileBranding['name_field_seo'] ?? []);
    $highlightsAssessment   = (string) ($profileBranding['highlights_assessment'] ?? '');

    $contentAnalysis        = (array) ($igAudit['content_analysis'] ?? []);
    $volumeFreq             = (string) ($contentAnalysis['volume_frequency_summary'] ?? '');
    $contentTypeBreakdown   = (array) ($contentAnalysis['content_type_breakdown'] ?? []);
    $contentPillars         = (array) ($contentAnalysis['content_pillars'] ?? []);
    $captionStyle           = (string) ($contentAnalysis['caption_style'] ?? '');
    $visualStyle            = (string) ($contentAnalysis['visual_style'] ?? '');

    $engagementAnalysis     = (array) ($igAudit['engagement_analysis'] ?? []);
    $followerTier           = (string) ($engagementAnalysis['follower_tier'] ?? '');
    $estimatedErRange       = (string) ($engagementAnalysis['estimated_er_range'] ?? '');
    $estimationBasis        = (string) ($engagementAnalysis['estimation_basis'] ?? '');
    $communityNotes         = (string) ($engagementAnalysis['community_interaction_notes'] ?? '');

    $growthPositioning      = (array) ($igAudit['growth_positioning'] ?? []);
    $nicheClarityScore      = (int) ($growthPositioning['niche_clarity_score'] ?? 0);
    $brandClarityScore      = (int) ($growthPositioning['personal_brand_clarity_score'] ?? 0);
    $brandPillarStatus      = (array) ($growthPositioning['brand_pillar_status'] ?? []);

    $contentGaps            = (array) ($igAudit['content_gaps'] ?? []);
    $priorityRecommendations = (array) ($igAudit['priority_recommendations'] ?? []);
    $quickWins              = (array) ($igAudit['quick_wins'] ?? []);
    // BB139 Fix C — competitive_positioning is a string today; a future
    // ticket may emit a structured object {headline, current_state,
    // opportunity, timeframe}. Capture the raw value and derive a
    // render-ready shape so BOTH forms read as tidy short paragraphs
    // instead of one wall of text (same back-compat pattern as
    // executive_summary in BB131).
    $competitivePositioningRaw = $igAudit['competitive_positioning'] ?? '';
    $positioningIsStructured   = is_array($competitivePositioningRaw);
    $competitivePositioning    = $positioningIsStructured ? '' : (string) $competitivePositioningRaw;
    $positioningHeadline       = $positioningIsStructured ? (string) ($competitivePositioningRaw['headline'] ?? '') : '';
    $positioningTimeframe      = $positioningIsStructured ? (string) ($competitivePositioningRaw['timeframe'] ?? '') : '';
    if ($positioningIsStructured) {
        $positioningParagraphs = array_values(array_filter(
            [(string) ($competitivePositioningRaw['current_state'] ?? ''), (string) ($competitivePositioningRaw['opportunity'] ?? '')],
            static fn (string $p): bool => trim($p) !== ''
        ));
    } else {
        $posText = trim($competitivePositioning);
        // Honour explicit line breaks first; otherwise group sentences in
        // pairs so a single long blob still reads as a few short paragraphs.
        $byLine = array_values(array_filter(array_map('trim', preg_split('/\n+/', $posText) ?: [])));
        if (count($byLine) > 1) {
            $positioningParagraphs = $byLine;
        } elseif ($posText !== '') {
            $sentences = array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+/', $posText) ?: [])));
            $positioningParagraphs = array_map(
                static fn (array $chunk): string => implode(' ', $chunk),
                array_chunk($sentences, 2)
            );
        } else {
            $positioningParagraphs = [];
        }
    }
    $hasPositioning = $positioningHeadline !== '' || $positioningParagraphs !== [] || $positioningTimeframe !== '';
    $scorecard              = (array) ($igAudit['scorecard'] ?? []);
    $limitations            = (array) ($igAudit['limitations'] ?? []);

    $priorityLabel = ['tinggi' => 'Tinggi', 'sedang' => 'Sedang', 'rendah' => 'Rendah'];
    $priorityClr = [
        'tinggi'  => 'var(--color-danger)',
        'sedang'  => 'var(--color-warning)',
        'rendah'  => 'var(--text-tertiary)',
    ];
    $priorityBg = [
        'tinggi'  => '#FBE6E1',
        'sedang'  => '#FEF3DC',
        'rendah'  => 'var(--surface-muted)',
    ];
    $pillarStatusLabel = ['ada' => 'Ada', 'sebagian' => 'Sebagian', 'tidak_ada' => 'Tidak Ada'];
    $pillarStatusClr = [
        'ada'       => 'var(--color-success)',
        'sebagian'  => 'var(--color-warning)',
        'tidak_ada' => 'var(--color-danger)',
    ];
    $pillarStatusBg = [
        'ada'       => 'var(--chimera-50)',
        'sebagian'  => '#FEF3DC',
        'tidak_ada' => '#FBE6E1',
    ];

    $gradeClr = static fn (string $g): string => match (strtoupper($g)) {
        'A', 'B' => 'var(--color-success)',
        'C', 'D' => 'var(--color-warning)',
        'F'      => 'var(--color-danger)',
        default  => 'var(--text-tertiary)',
    };

    $scorecardLabels = [
        'profile_bio_optimization'      => 'Profile / Bio Optimization',
        'content_quality_variety'       => 'Content Quality & Variety',
        'visual_consistency_aesthetics' => 'Visual Consistency & Aesthetics',
        'niche_clarity_positioning'     => 'Niche Clarity & Positioning',
        'engagement_strategy'           => 'Engagement Strategy',
        'personal_brand_storytelling'   => 'Personal Brand / Storytelling',
        'growth_potential'              => 'Growth Potential',
    ];

    $ctReels    = max(0, min(100, (int) ($contentTypeBreakdown['reels']    ?? 0)));
    $ctCarousel = max(0, min(100, (int) ($contentTypeBreakdown['carousel'] ?? 0)));
    $ctStatic   = max(0, min(100, (int) ($contentTypeBreakdown['static']   ?? 0)));

    $overall = (array) ($scorecard['overall'] ?? []);
    $overallScore = $overall['score'] ?? 0;
    $overallGrade = (string) ($overall['grade'] ?? 'F');

    $prioCounts = ['tinggi' => 0, 'sedang' => 0, 'rendah' => 0];
    foreach ($priorityRecommendations as $r) {
        $k = strtolower((string) ($r['priority'] ?? 'rendah'));
        $prioCounts[$k] = ($prioCounts[$k] ?? 0) + 1;
    }
@endphp

<div
    class="mb-12"
    x-data="{
        sections: { profile: false, content: false, engagement: false, growth: false, gaps: false, recs: false, quickWins: false, positioning: false, limitations: false },
        expandAll() { Object.keys(this.sections).forEach(k => this.sections[k] = true); },
        collapseAll() { Object.keys(this.sections).forEach(k => this.sections[k] = false); }
    }"
>
    {{-- ===== Section header ===== --}}
    <div class="flex items-end justify-between mb-6 flex-wrap gap-3">
        <div>
            <p style="font-size: 11px; color: var(--text-tertiary); letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 4px;">
                Audit Profil Instagram
            </p>
            <h3 style="font-size: 22px; font-weight: 600; color: var(--text-primary); margin: 0;">
                @if (! empty($meta['username']))
                    {{ '@' . $meta['username'] }}
                @else
                    Instagram
                @endif
            </h3>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
            @if ($renderFullSection)
                <p style="font-size: 12px; color: var(--text-tertiary); margin: 0;">Diaudit {{ $analyzedAtLabel }}</p>
                <div class="flex gap-2">
                    <button type="button" @click="expandAll()" style="font-size: 12px; color: var(--chimera-600); background: none; border: none; cursor: pointer; padding: 0;">Buka semua ↓</button>
                    <span style="color: var(--text-tertiary);">·</span>
                    <button type="button" @click="collapseAll()" style="font-size: 12px; color: var(--chimera-600); background: none; border: none; cursor: pointer; padding: 0;">Tutup semua ↑</button>
                </div>
            @endif
        </div>
    </div>

    {{-- ===== BB16: Status / degraded-data banner ===== --}}
    @if ($banner !== null)
        @php $bStyle = $bannerStyles[$banner['severity']] ?? $bannerStyles['info']; @endphp
        <div style="border: 1px solid {{ $bStyle['border'] }}; border-left: 4px solid {{ $bStyle['border'] }}; background: {{ $bStyle['bg'] }}; padding: 16px 20px; border-radius: var(--radius-md); margin-bottom: 16px;">
            <p style="font-size: 13px; font-weight: 600; color: {{ $bStyle['title'] }}; margin: 0 0 6px;">⚠ {{ $banner['title'] }}</p>
            <p style="font-size: 13px; color: var(--text-primary); margin: 0; line-height: 1.6;">{{ $banner['body'] }}</p>
        </div>
    @endif

    @if ($renderFullSection)

    {{-- ===== Profile header card (always visible) ===== --}}
    <x-nui-card padding="lg" style="margin-bottom: 16px; background: var(--chimera-50); border-color: var(--chimera-100);">
        <div class="flex flex-wrap gap-6">
            <div class="flex-1" style="min-width: 280px;">
                <p style="font-size: 16px; font-weight: 600; color: var(--text-primary); margin: 0;">
                    {{ $meta['name'] ?? '' }}
                    @if (! empty($meta['is_verified']))
                        <span style="font-size: 10px; font-weight: 600; color: white; background: var(--chimera-500); padding: 2px 8px; border-radius: var(--radius-pill); margin-left: 6px;">✓ Verified</span>
                    @endif
                    @if (! empty($meta['is_business']))
                        <span style="font-size: 10px; font-weight: 600; color: var(--chimera-700); background: var(--chimera-100); padding: 2px 8px; border-radius: var(--radius-pill); margin-left: 4px;">Business</span>
                    @endif
                </p>
                @if (! empty($meta['bio']))
                    <p style="font-size: 13px; color: var(--text-secondary); margin-top: 10px; line-height: 1.6; white-space: pre-line;">{{ $meta['bio'] }}</p>
                @endif
                @if (! empty($meta['external_url']))
                    <p style="font-size: 12px; color: var(--chimera-600); margin-top: 10px; font-family: var(--font-mono); word-break: break-all;">↗ {{ $meta['external_url'] }}</p>
                @endif
            </div>
            {{-- BB139 Fix A — followers / following / posts in one horizontal row (wraps on mobile). --}}
            <div class="ig-stats-row">
                <div class="ig-stat">
                    <span class="ig-stat-value">{{ number_format((int) ($meta['followers'] ?? 0)) }}</span>
                    <span class="ig-stat-label">Followers</span>
                </div>
                <div class="ig-stat">
                    <span class="ig-stat-value">{{ number_format((int) ($meta['following'] ?? 0)) }}</span>
                    <span class="ig-stat-label">Following</span>
                </div>
                <div class="ig-stat">
                    <span class="ig-stat-value">{{ number_format((int) ($meta['posts_count'] ?? 0)) }}</span>
                    <span class="ig-stat-label">Total Post</span>
                </div>
            </div>
        </div>
    </x-nui-card>

    {{-- BB139 Fix B — BB138 engagement-funnel chart removed (low signal: a
         2-bar chart whose second bar rendered as a tiny stub). The
         "Analisis Engagement" accordion below keeps the ER prose summary. --}}

    {{-- ===== BB131 + BB133: Bukti Scrape (multi-section screenshot proof) ===== --}}
    @if ($proofSections !== [])
        <x-nui-card style="margin-bottom: 16px;">
            <div x-data="{ proofOpen: false, proofTab: '{{ $proofDefaultTab }}' }">
                <button type="button" @click="proofOpen = !proofOpen" class="w-full flex items-center justify-between" style="background: none; border: none; cursor: pointer; text-align: left; padding: 0;">
                    <div>
                        <p style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin: 0;">Bukti Scrape</p>
                        <p style="font-size: 12px; color: var(--text-tertiary); margin-top: 2px;">Screenshot Instagram yang diambil saat audit — bukti data ini benar dari profil brand kita.</p>
                    </div>
                    <span x-text="proofOpen ? '−' : '+'" style="font-size: 22px; color: var(--text-tertiary); font-weight: 300; line-height: 1;"></span>
                </button>
                <div x-show="proofOpen" x-cloak style="margin-top: 14px;">
                    @if (count($proofSections) > 1)
                        <div role="tablist" class="flex items-center gap-1" style="border-bottom: 1px solid var(--border-default); margin-bottom: 12px;">
                            @foreach ($proofSections as $section => $info)
                                <button type="button"
                                        role="tab"
                                        @click="proofTab = '{{ $section }}'"
                                        :style="proofTab === '{{ $section }}'
                                            ? 'color: var(--text-primary); border-bottom-color: var(--chimera-500); font-weight: 500;'
                                            : 'color: var(--text-secondary); border-bottom-color: transparent;'"
                                        style="padding: 8px 16px; background: none; border: none; border-bottom: 2px solid transparent; cursor: pointer; font-size: 13px;">
                                    {{ $info['label'] }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                    @foreach ($proofSections as $section => $info)
                        <div x-show="proofTab === '{{ $section }}'" x-cloak>
                            <img src="{{ $info['url'] }}"
                                 alt="Screenshot Instagram {{ $info['label'] }} {{ $meta['username'] ?? '' }}"
                                 loading="lazy"
                                 style="max-width: 100%; border-radius: var(--radius-md); border: 1px solid var(--border-default); display: block;" />
                        </div>
                    @endforeach
                </div>
            </div>
        </x-nui-card>
    @endif

    {{-- Phase 12c.2 BB116 part 2: AI suggestion disclaimer rendered
         once at the top of the Instagram audit body so users know
         the analysis and recommendations below are LLM-generated. --}}
    <div class="bb-ai-disclaimer">
        <span class="bb-ai-disclaimer__icon" aria-hidden="true">💡</span>
        <p>
            <strong>Catatan:</strong> Analisis dan rekomendasi di bawah dibuat oleh AI yang sudah dilatih dengan data audit brand laundry. Hasilnya bisa membantu sebagai bahan pertimbangan, keputusan akhir tetap di tangan kita.
        </p>
    </div>

    {{-- ===== Executive summary (always expanded) — BB131 structured ===== --}}
    @if ($execIsStructured && $hasExecStructured)
        <x-nui-card style="margin-bottom: 16px;">
            <h4 style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 12px;">Ringkasan Eksekutif</h4>

            @if ($execHeadline !== '')
                <div style="background: var(--surface-muted); border-left: 3px solid var(--color-info); padding: 10px 14px; border-radius: var(--radius-sm); margin-bottom: 14px;">
                    <p style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin: 0; line-height: 1.5;">{{ $execHeadline }}</p>
                </div>
            @endif

            @if (! empty($execKekuatan))
                <p style="font-size: 11px; font-weight: 600; color: var(--color-success); margin: 12px 0 4px;">Kekuatan</p>
                <div class="flex flex-col gap-1.5">
                    @foreach ($execKekuatan as $item)
                        <div style="background: var(--chimera-50); border-left: 3px solid var(--color-success); padding: 8px 12px; border-radius: var(--radius-sm); font-size: 12px; color: var(--text-primary); line-height: 1.55;">{{ $item }}</div>
                    @endforeach
                </div>
            @endif

            @if (! empty($execAreaPerbaikan))
                <p style="font-size: 11px; font-weight: 600; color: var(--color-warning); margin: 12px 0 4px;">Area Perbaikan</p>
                <div class="flex flex-col gap-1.5">
                    @foreach ($execAreaPerbaikan as $item)
                        <div style="background: #FEF3DC; border-left: 3px solid var(--color-warning); padding: 8px 12px; border-radius: var(--radius-sm); font-size: 12px; color: var(--text-primary); line-height: 1.55;">{{ $item }}</div>
                    @endforeach
                </div>
            @endif

            @if ($execKonteks !== '')
                <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.7; margin: 14px 0 0; white-space: pre-line;">{{ $execKonteks }}</p>
            @endif
        </x-nui-card>
    @elseif (! $execIsStructured && $execSummaryString !== '')
        {{-- Back-compat: audits created before BB131 stored a flat string. --}}
        <x-nui-card style="margin-bottom: 16px;">
            <h4 style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 6px;">Ringkasan Eksekutif</h4>
            <p style="font-size: 11px; color: var(--text-tertiary); margin: 0 0 10px; font-style: italic;">Audit lama — format ringkasan sebelumnya</p>
            <p style="font-size: 13px; color: var(--text-primary); line-height: 1.75; margin: 0; white-space: pre-line;">{{ $execSummaryString }}</p>
        </x-nui-card>
    @endif

    {{-- ===== Scorecard (always expanded) ===== --}}
    @if (! empty($scorecard))
        <x-nui-card style="margin-bottom: 16px;">
            <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
                <h4 style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin: 0;">Scorecard Audit</h4>
                <div class="flex items-center gap-2">
                    <span style="font-size: 28px; font-weight: 700; color: {{ $gradeClr($overallGrade) }}; line-height: 1;">{{ $overallScore }}</span>
                    <span style="font-size: 12px; color: var(--text-tertiary);">/10</span>
                    <span style="font-size: 12px; font-weight: 700; color: white; background: {{ $gradeClr($overallGrade) }}; padding: 4px 12px; border-radius: var(--radius-pill); margin-left: 4px;">{{ $overallGrade }}</span>
                </div>
            </div>
            <div class="flex flex-col gap-3">
                @foreach ($scorecardLabels as $key => $label)
                    @php
                        $cell  = (array) ($scorecard[$key] ?? []);
                        $score = (int) ($cell['score'] ?? 0);
                        $grade = (string) ($cell['grade'] ?? 'F');
                        $clr   = $gradeClr($grade);
                        $pct   = max(0, min(100, $score * 10));
                    @endphp
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <span style="font-size: 12px; color: var(--text-secondary);">{{ $label }}</span>
                            <span class="flex items-center gap-2">
                                <span style="font-size: 13px; font-weight: 600; color: var(--text-primary);">{{ $score }}/10</span>
                                <span style="font-size: 10px; font-weight: 700; color: white; background: {{ $clr }}; padding: 1px 8px; border-radius: var(--radius-pill); min-width: 16px; text-align: center;">{{ $grade }}</span>
                            </span>
                        </div>
                        <div style="height: 6px; background: var(--chimera-50); border-radius: 999px;">
                            <div style="height: 100%; width: {{ $pct }}%; background: {{ $clr }}; border-radius: 999px;"></div>
                        </div>
                    </div>
                @endforeach
            </div>
            {{-- BB17: scorecard transparency. --}}
            <p style="font-size: 11px; color: var(--text-tertiary); margin-top: 12px; line-height: 1.55; font-style: italic;">
                ▸ <strong style="font-weight: 600;">Cara Perhitungan:</strong> Sumber: hasil scrape worker (profile + 12 post + 6 caption + 6 highlight) plus analisis AI Claude. Rumus: penilaian AI dengan skala 0&ndash;10 berdasarkan rubrik kalibrasi pasar laundry Indonesia (9&ndash;10 terbaik di kelasnya, 7&ndash;8 kuat, 5&ndash;6 standar, 3&ndash;4 ada celah penting, 0&ndash;2 belum ada). Skor keseluruhan dihitung otomatis sebagai rata-rata sederhana dari 7 sub-skor.
            </p>
        </x-nui-card>
    @endif

    {{-- ===== Accordion: Profil & Branding ===== --}}
    @php $hasProfile = ! empty($bioAnalysis) || ! empty($nameFieldSeo) || $highlightsAssessment !== ''; @endphp
    @if ($hasProfile)
        <x-nui-card padding="none" style="margin-bottom: 10px;">
            <button type="button" @click="sections.profile = !sections.profile" class="w-full flex items-center justify-between" style="padding: 16px 20px; background: none; border: none; cursor: pointer; text-align: left;">
                <div>
                    <p style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin: 0;">Profil &amp; Branding</p>
                    <p style="font-size: 12px; color: var(--text-tertiary); margin-top: 2px;">Bio, Name field SEO, dan highlights</p>
                </div>
                <span x-text="sections.profile ? '−' : '+'" style="font-size: 22px; color: var(--text-tertiary); font-weight: 300; line-height: 1;"></span>
            </button>
            <div x-show="sections.profile" x-cloak style="padding: 0 20px 20px; border-top: 1px solid var(--border-default);">
                @if (! empty($bioAnalysis))
                    <h5 style="font-size: 13px; font-weight: 600; color: var(--text-primary); margin: 16px 0 8px;">◆ Analisis Bio</h5>
                    @if (! empty($bioAnalysis['current_bio']))
                        <p style="font-size: 11px; color: var(--text-tertiary); margin: 0 0 4px;">Bio saat ini:</p>
                        <div style="background: var(--surface-muted); border-left: 3px solid var(--text-tertiary); padding: 10px 14px; border-radius: var(--radius-sm); margin-bottom: 12px;">
                            <p style="font-size: 13px; color: var(--text-primary); margin: 0; line-height: 1.6; white-space: pre-line;">{{ $bioAnalysis['current_bio'] }}</p>
                        </div>
                    @endif
                    @if (! empty($bioAnalysis['strengths']))
                        <p style="font-size: 11px; font-weight: 600; color: var(--color-success); margin: 12px 0 4px;">Kekuatan</p>
                        <div class="flex flex-col gap-1.5">
                            @foreach ((array) $bioAnalysis['strengths'] as $s)
                                <div style="background: var(--chimera-50); border-left: 3px solid var(--color-success); padding: 8px 12px; border-radius: var(--radius-sm); font-size: 12px; color: var(--text-primary); line-height: 1.55;">{{ $s }}</div>
                            @endforeach
                        </div>
                    @endif
                    @if (! empty($bioAnalysis['weaknesses']))
                        <p style="font-size: 11px; font-weight: 600; color: var(--color-warning); margin: 12px 0 4px;">Area Perbaikan</p>
                        <div class="flex flex-col gap-1.5">
                            @foreach ((array) $bioAnalysis['weaknesses'] as $w)
                                <div style="background: #FEF3DC; border-left: 3px solid var(--color-warning); padding: 8px 12px; border-radius: var(--radius-sm); font-size: 12px; color: var(--text-primary); line-height: 1.55;">{{ $w }}</div>
                            @endforeach
                        </div>
                    @endif
                    @if (! empty($bioAnalysis['recommended_bio']))
                        <p style="font-size: 11px; font-weight: 600; color: var(--chimera-700); margin: 14px 0 4px;">Rekomendasi Bio</p>
                        <div style="background: var(--chimera-50); border: 1px solid var(--chimera-100); padding: 12px 14px; border-radius: var(--radius-md);">
                            <p style="font-size: 13px; color: var(--text-primary); margin: 0; line-height: 1.6; white-space: pre-line;">{{ $bioAnalysis['recommended_bio'] }}</p>
                        </div>
                    @endif
                @endif

                @if (! empty($nameFieldSeo))
                    <h5 style="font-size: 13px; font-weight: 600; color: var(--text-primary); margin: 20px 0 8px;">◆ Name Field SEO</h5>
                    <div class="flex flex-col gap-2 mb-2">
                        @if (! empty($nameFieldSeo['current']))
                            <div class="flex flex-wrap items-baseline gap-2">
                                <span style="font-size: 11px; color: var(--text-tertiary); min-width: 90px;">Saat ini:</span>
                                <span style="font-size: 12px; color: var(--text-primary); font-family: var(--font-mono);">{{ $nameFieldSeo['current'] }}</span>
                            </div>
                        @endif
                        @if (! empty($nameFieldSeo['recommended']))
                            <div class="flex flex-wrap items-baseline gap-2">
                                <span style="font-size: 11px; font-weight: 600; color: var(--chimera-700); min-width: 90px;">Rekomendasi:</span>
                                <span style="font-size: 12px; color: var(--text-primary); font-family: var(--font-mono); background: var(--chimera-50); padding: 2px 8px; border-radius: var(--radius-sm);">{{ $nameFieldSeo['recommended'] }}</span>
                            </div>
                        @endif
                    </div>
                    @if (! empty($nameFieldSeo['assessment']))
                        <p style="font-size: 13px; color: var(--text-primary); line-height: 1.65; margin: 8px 0 0;">{{ $nameFieldSeo['assessment'] }}</p>
                    @endif
                @endif

                @if ($highlightsAssessment !== '')
                    <h5 style="font-size: 13px; font-weight: 600; color: var(--text-primary); margin: 20px 0 8px;">◆ Penilaian Highlights</h5>
                    <p style="font-size: 13px; color: var(--text-primary); line-height: 1.65; margin: 0;">{{ $highlightsAssessment }}</p>
                @endif
            </div>
        </x-nui-card>
    @endif

    {{-- ===== Accordion: Analisis Konten ===== --}}
    @if (! empty($contentAnalysis))
        <x-nui-card padding="none" style="margin-bottom: 10px;">
            <button type="button" @click="sections.content = !sections.content" class="w-full flex items-center justify-between" style="padding: 16px 20px; background: none; border: none; cursor: pointer; text-align: left;">
                <div>
                    <p style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin: 0;">Analisis Konten</p>
                    <p style="font-size: 12px; color: var(--text-tertiary); margin-top: 2px;">
                        {{ count($contentPillars) }} content pillars · Reels {{ $ctReels }}% · Carousel {{ $ctCarousel }}% · Static {{ $ctStatic }}%
                    </p>
                </div>
                <span x-text="sections.content ? '−' : '+'" style="font-size: 22px; color: var(--text-tertiary); font-weight: 300; line-height: 1;"></span>
            </button>
            <div x-show="sections.content" x-cloak style="padding: 0 20px 20px; border-top: 1px solid var(--border-default);">
                @if ($volumeFreq !== '')
                    <h5 style="font-size: 13px; font-weight: 600; color: var(--text-primary); margin: 16px 0 6px;">◆ Volume &amp; Frekuensi</h5>
                    <p style="font-size: 13px; color: var(--text-primary); line-height: 1.65; margin: 0 0 14px;">{{ $volumeFreq }}</p>
                @endif

                <h5 style="font-size: 13px; font-weight: 600; color: var(--text-primary); margin: 16px 0 8px;">◆ Distribusi Tipe Konten</h5>
                {{-- BB138 Chart 6 — content-mix donut (bars below stay as fallback/detail). --}}
                @if (($ctReels + $ctCarousel + $ctStatic) > 0)
                    <div class="bb-chart-container bb-chart-container--donut" wire:ignore style="margin: 4px 0 14px;">
                        <canvas
                            data-chart-type="content-donut"
                            data-chart-data="{{ json_encode(['reels' => $ctReels, 'carousel' => $ctCarousel, 'static' => $ctStatic], JSON_THROW_ON_ERROR) }}"
                            role="img"
                            aria-label="Distribusi tipe konten: Reels {{ $ctReels }} persen, Carousel {{ $ctCarousel }} persen, Statis {{ $ctStatic }} persen"
                        ></canvas>
                    </div>
                @endif
                <div class="flex flex-col gap-2 mb-4">
                    @foreach ([['Reels', $ctReels, 'var(--chimera-500)'], ['Carousel', $ctCarousel, 'var(--chimera-600)'], ['Static', $ctStatic, 'var(--text-tertiary)']] as [$lbl, $pct, $clr])
                        <div class="flex items-center gap-3">
                            <span style="font-size: 12px; color: var(--text-secondary); width: 80px;">{{ $lbl }}</span>
                            <div style="flex: 1; height: 10px; background: var(--chimera-50); border-radius: 999px;">
                                <div style="height: 100%; width: {{ $pct }}%; background: {{ $clr }}; border-radius: 999px;"></div>
                            </div>
                            <span style="font-size: 12px; font-weight: 600; color: var(--text-primary); width: 36px; text-align: right;">{{ $pct }}%</span>
                        </div>
                    @endforeach
                </div>

                @if (! empty($contentPillars))
                    <h5 style="font-size: 13px; font-weight: 600; color: var(--text-primary); margin: 16px 0 8px;">◆ Content Pillars Terdeteksi</h5>
                    <div class="flex flex-col gap-3">
                        @foreach ($contentPillars as $i => $pillar)
                            <div style="border: 1px solid var(--border-default); border-radius: var(--radius-md); padding: 12px 16px;">
                                <p style="font-size: 13px; font-weight: 600; color: var(--text-primary); margin: 0 0 4px;">{{ $i + 1 }}. {{ $pillar['name'] ?? '' }}</p>
                                @if (! empty($pillar['description']))
                                    <p style="font-size: 12px; color: var(--text-secondary); line-height: 1.6; margin: 0 0 6px;">{{ $pillar['description'] }}</p>
                                @endif
                                @if (! empty($pillar['examples']))
                                    <p style="font-size: 10px; color: var(--text-tertiary); font-weight: 600; margin: 6px 0 2px;">Contoh:</p>
                                    <ul style="margin: 0 0 0 18px; padding: 0; font-size: 11px; color: var(--text-secondary); line-height: 1.55;">
                                        @foreach ((array) $pillar['examples'] as $ex)
                                            <li>{{ $ex }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($captionStyle !== '')
                    <h5 style="font-size: 13px; font-weight: 600; color: var(--text-primary); margin: 16px 0 6px;">◆ Gaya Caption</h5>
                    <p style="font-size: 13px; color: var(--text-primary); line-height: 1.65; margin: 0 0 12px;">{{ $captionStyle }}</p>
                @endif

                @if ($visualStyle !== '')
                    <h5 style="font-size: 13px; font-weight: 600; color: var(--text-primary); margin: 12px 0 6px;">◆ Gaya Visual</h5>
                    <p style="font-size: 13px; color: var(--text-primary); line-height: 1.65; margin: 0;">{{ $visualStyle }}</p>
                @endif
            </div>
        </x-nui-card>
    @endif

    {{-- ===== Accordion: Analisis Engagement ===== --}}
    @if (! empty($engagementAnalysis))
        <x-nui-card padding="none" style="margin-bottom: 10px;">
            <button type="button" @click="sections.engagement = !sections.engagement" class="w-full flex items-center justify-between" style="padding: 16px 20px; background: none; border: none; cursor: pointer; text-align: left;">
                <div>
                    <p style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin: 0;">Analisis Engagement</p>
                    <p style="font-size: 12px; color: var(--text-tertiary); margin-top: 2px;">
                        {{ $followerTier ?: '—' }}@if ($estimatedErRange !== '') · ER {{ $estimatedErRange }}@endif
                    </p>
                </div>
                <span x-text="sections.engagement ? '−' : '+'" style="font-size: 22px; color: var(--text-tertiary); font-weight: 300; line-height: 1;"></span>
            </button>
            <div x-show="sections.engagement" x-cloak style="padding: 0 20px 20px; border-top: 1px solid var(--border-default);">
                <div class="flex flex-wrap gap-4 mt-4 mb-2">
                    @if ($followerTier !== '')
                        <div>
                            <p style="font-size: 11px; color: var(--text-tertiary); margin: 0;">Tier Follower</p>
                            <span style="display: inline-block; margin-top: 4px; font-size: 13px; font-weight: 600; color: var(--chimera-700); background: var(--chimera-50); padding: 4px 12px; border-radius: var(--radius-pill); border: 1px solid var(--chimera-100);">{{ $followerTier }}</span>
                        </div>
                    @endif
                    @if ($estimatedErRange !== '')
                        <div>
                            <p style="font-size: 11px; color: var(--text-tertiary); margin: 0;">Estimasi ER</p>
                            <p style="font-size: 18px; font-weight: 700; color: var(--text-primary); margin-top: 4px;">{{ $estimatedErRange }}</p>
                        </div>
                    @endif
                </div>
                @if ($estimationBasis !== '')
                    <h5 style="font-size: 12px; font-weight: 600; color: var(--text-secondary); margin: 14px 0 4px;">Dasar Estimasi</h5>
                    <p style="font-size: 13px; color: var(--text-primary); line-height: 1.65; margin: 0 0 12px;">{{ $estimationBasis }}</p>
                @endif
                @if ($communityNotes !== '')
                    <h5 style="font-size: 12px; font-weight: 600; color: var(--text-secondary); margin: 12px 0 4px;">Catatan Interaksi Komunitas</h5>
                    <p style="font-size: 13px; color: var(--text-primary); line-height: 1.65; margin: 0;">{{ $communityNotes }}</p>
                @endif
            </div>
        </x-nui-card>
    @endif

    {{-- ===== Accordion: Pertumbuhan & Positioning ===== --}}
    @if (! empty($growthPositioning))
        <x-nui-card padding="none" style="margin-bottom: 10px;">
            <button type="button" @click="sections.growth = !sections.growth" class="w-full flex items-center justify-between" style="padding: 16px 20px; background: none; border: none; cursor: pointer; text-align: left;">
                <div>
                    <p style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin: 0;">Pertumbuhan &amp; Positioning</p>
                    <p style="font-size: 12px; color: var(--text-tertiary); margin-top: 2px;">
                        Niche {{ $nicheClarityScore }}/10 · Brand Clarity {{ $brandClarityScore }}/10 · {{ count($brandPillarStatus) }} pillars
                    </p>
                </div>
                <span x-text="sections.growth ? '−' : '+'" style="font-size: 22px; color: var(--text-tertiary); font-weight: 300; line-height: 1;"></span>
            </button>
            <div x-show="sections.growth" x-cloak style="padding: 0 20px 20px; border-top: 1px solid var(--border-default);">
                <div class="grid grid-cols-2 gap-3 mt-4 mb-4">
                    <div style="background: var(--surface-muted); border-radius: var(--radius-md); padding: 12px 14px;">
                        <p style="font-size: 11px; color: var(--text-tertiary); margin: 0;">Skor Kejelasan Niche</p>
                        <p style="font-size: 22px; font-weight: 700; color: var(--text-primary); margin-top: 4px;">{{ $nicheClarityScore }}<span style="font-size: 12px; color: var(--text-tertiary); font-weight: 400;">/10</span></p>
                    </div>
                    <div style="background: var(--surface-muted); border-radius: var(--radius-md); padding: 12px 14px;">
                        <p style="font-size: 11px; color: var(--text-tertiary); margin: 0;">Skor Brand Clarity</p>
                        <p style="font-size: 22px; font-weight: 700; color: var(--text-primary); margin-top: 4px;">{{ $brandClarityScore }}<span style="font-size: 12px; color: var(--text-tertiary); font-weight: 400;">/10</span></p>
                    </div>
                </div>

                @if (! empty($brandPillarStatus))
                    <h5 style="font-size: 12px; font-weight: 600; color: var(--text-secondary); margin: 12px 0 6px;">Status Pillar Brand</h5>
                    <div class="flex flex-col gap-2">
                        @foreach ($brandPillarStatus as $bp)
                            @php
                                $bpStatusKey = strtolower((string) ($bp['status'] ?? ''));
                                $sLbl = $pillarStatusLabel[$bpStatusKey] ?? ($bp['status'] ?? '');
                                $sClr = $pillarStatusClr[$bpStatusKey] ?? 'var(--text-tertiary)';
                                $sBg  = $pillarStatusBg[$bpStatusKey] ?? 'var(--surface-muted)';
                            @endphp
                            <div style="border: 1px solid var(--border-default); border-radius: var(--radius-md); padding: 10px 14px;">
                                <div class="flex items-center justify-between gap-3 mb-1">
                                    <p style="font-size: 13px; font-weight: 600; color: var(--text-primary); margin: 0;">{{ $bp['pillar'] ?? '' }}</p>
                                    <span style="font-size: 10px; font-weight: 600; color: {{ $sClr }}; background: {{ $sBg }}; padding: 2px 10px; border-radius: var(--radius-pill); border: 1px solid {{ $sClr }};">{{ $sLbl }}</span>
                                </div>
                                @if (! empty($bp['gap']))
                                    <p style="font-size: 12px; color: var(--text-secondary); line-height: 1.55; margin: 0;">{{ $bp['gap'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-nui-card>
    @endif

    {{-- ===== Accordion: Kesenjangan Konten ===== --}}
    @if (! empty($contentGaps))
        <x-nui-card padding="none" style="margin-bottom: 10px;">
            <button type="button" @click="sections.gaps = !sections.gaps" class="w-full flex items-center justify-between" style="padding: 16px 20px; background: none; border: none; cursor: pointer; text-align: left;">
                <div>
                    <p style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin: 0;">Kesenjangan Konten</p>
                    <p style="font-size: 12px; color: var(--text-tertiary); margin-top: 2px;">{{ count($contentGaps) }} kesenjangan teridentifikasi</p>
                </div>
                <span x-text="sections.gaps ? '−' : '+'" style="font-size: 22px; color: var(--text-tertiary); font-weight: 300; line-height: 1;"></span>
            </button>
            <div x-show="sections.gaps" x-cloak style="padding: 0 20px 20px; border-top: 1px solid var(--border-default);">
                <div class="flex flex-col gap-3 mt-4">
                    @foreach ($contentGaps as $i => $gap)
                        <div style="border-left: 4px solid var(--color-warning); background: #FEF3DC; padding: 12px 16px; border-radius: var(--radius-sm);">
                            <p style="font-size: 13px; font-weight: 600; color: var(--text-primary); margin: 0 0 6px;">{{ $i + 1 }}. {{ $gap['category'] ?? '' }}</p>
                            @if (! empty($gap['rationale']))
                                <p style="font-size: 11px; color: var(--text-tertiary); font-weight: 600; margin: 4px 0 2px;">Mengapa penting:</p>
                                <p style="font-size: 12px; color: var(--text-primary); line-height: 1.6; margin: 0 0 6px;">{{ $gap['rationale'] }}</p>
                            @endif
                            @if (! empty($gap['example_content_idea']))
                                <p style="font-size: 11px; color: var(--text-tertiary); font-weight: 600; margin: 4px 0 2px;">Contoh ide konten:</p>
                                <p style="font-size: 12px; color: var(--text-primary); line-height: 1.6; margin: 0; font-style: italic;">{{ $gap['example_content_idea'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </x-nui-card>
    @endif

    {{-- ===== Accordion: Rekomendasi Prioritas ===== --}}
    @if (! empty($priorityRecommendations))
        <x-nui-card padding="none" style="margin-bottom: 10px;">
            <button type="button" @click="sections.recs = !sections.recs" class="w-full flex items-center justify-between" style="padding: 16px 20px; background: none; border: none; cursor: pointer; text-align: left;">
                <div>
                    <p style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin: 0;">Rekomendasi Prioritas (Instagram)</p>
                    <p style="font-size: 12px; color: var(--text-tertiary); margin-top: 2px;">
                        {{ count($priorityRecommendations) }} rekomendasi
                        @if ($prioCounts['tinggi']) · {{ $prioCounts['tinggi'] }} tinggi @endif
                        @if ($prioCounts['sedang']) · {{ $prioCounts['sedang'] }} sedang @endif
                        @if ($prioCounts['rendah']) · {{ $prioCounts['rendah'] }} rendah @endif
                    </p>
                </div>
                <span x-text="sections.recs ? '−' : '+'" style="font-size: 22px; color: var(--text-tertiary); font-weight: 300; line-height: 1;"></span>
            </button>
            <div x-show="sections.recs" x-cloak style="padding: 0 20px 20px; border-top: 1px solid var(--border-default);">
                <div class="flex flex-col gap-3 mt-4">
                    @foreach ($priorityRecommendations as $i => $rec)
                        @php
                            $rPrio = strtolower((string) ($rec['priority'] ?? 'rendah'));
                            $pLbl = $priorityLabel[$rPrio] ?? ucfirst($rPrio);
                            $pClr = $priorityClr[$rPrio] ?? 'var(--text-tertiary)';
                            $pBg  = $priorityBg[$rPrio] ?? 'var(--surface-muted)';
                        @endphp
                        <div style="border: 1px solid var(--border-default); border-radius: var(--radius-md); padding: 14px 16px;">
                            <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
                                <span style="font-size: 11px; font-weight: 600; color: {{ $pClr }}; background: {{ $pBg }}; padding: 2px 10px; border-radius: var(--radius-pill); border: 1px solid {{ $pClr }};">
                                    Prioritas {{ $pLbl }}
                                </span>
                                @if (! empty($rec['effort']) || ! empty($rec['impact']))
                                    <span style="font-size: 11px; color: var(--text-tertiary);">
                                        @if (! empty($rec['effort'])) Effort: <strong style="color: var(--text-secondary);">{{ ucfirst((string) $rec['effort']) }}</strong> @endif
                                        @if (! empty($rec['effort']) && ! empty($rec['impact'])) · @endif
                                        @if (! empty($rec['impact'])) Impact: <strong style="color: var(--text-secondary);">{{ ucfirst((string) $rec['impact']) }}</strong> @endif
                                    </span>
                                @endif
                            </div>
                            <p style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin: 0 0 6px;">{{ $i + 1 }}. {{ $rec['title'] ?? '' }}</p>
                            @if (! empty($rec['description']))
                                <p style="font-size: 12px; color: var(--text-secondary); line-height: 1.65; margin: 0;">{{ $rec['description'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </x-nui-card>
    @endif

    {{-- ===== Accordion: Quick Wins ===== --}}
    @if (! empty($quickWins))
        <x-nui-card padding="none" style="margin-bottom: 10px;">
            <button type="button" @click="sections.quickWins = !sections.quickWins" class="w-full flex items-center justify-between" style="padding: 16px 20px; background: none; border: none; cursor: pointer; text-align: left;">
                <div>
                    <p style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin: 0;">Quick Wins</p>
                    <p style="font-size: 12px; color: var(--text-tertiary); margin-top: 2px;">{{ count($quickWins) }} aksi eksekusi &lt;1 minggu</p>
                </div>
                <span x-text="sections.quickWins ? '−' : '+'" style="font-size: 22px; color: var(--text-tertiary); font-weight: 300; line-height: 1;"></span>
            </button>
            <div x-show="sections.quickWins" x-cloak style="padding: 0 20px 20px; border-top: 1px solid var(--border-default);">
                {{-- BB139 Fix C — numbered action/detail cards. The em-dash
                     heuristic splits "Aksi — penjelasan" into a bold action
                     line + a muted detail line; bullets without an em-dash
                     put everything in the action line. --}}
                <div class="quickwin-list">
                    @foreach ($quickWins as $qw)
                        @php
                            $qwStr = is_array($qw) ? trim((string) ($qw['action'] ?? reset($qw) ?: '')) : trim((string) $qw);
                            $qwParts = explode('—', $qwStr, 2);
                            $qwAction = trim($qwParts[0] ?? $qwStr);
                            $qwDetail = trim($qwParts[1] ?? '');
                        @endphp
                        <div class="quickwin-card">
                            <div class="quickwin-number">{{ $loop->iteration }}</div>
                            <div class="quickwin-body">
                                <p class="quickwin-action">{{ $qwAction }}</p>
                                @if ($qwDetail !== '')
                                    <p class="quickwin-detail">{{ $qwDetail }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </x-nui-card>
    @endif

    {{-- ===== Accordion: Positioning Kompetitif ===== --}}
    @if ($hasPositioning)
        <x-nui-card padding="none" style="margin-bottom: 10px;">
            <button type="button" @click="sections.positioning = !sections.positioning" class="w-full flex items-center justify-between" style="padding: 16px 20px; background: none; border: none; cursor: pointer; text-align: left;">
                <div>
                    <p style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin: 0;">Positioning Kompetitif</p>
                    <p style="font-size: 12px; color: var(--text-tertiary); margin-top: 2px;">Analisis posisi vs kompetitor lokal</p>
                </div>
                <span x-text="sections.positioning ? '−' : '+'" style="font-size: 22px; color: var(--text-tertiary); font-weight: 300; line-height: 1;"></span>
            </button>
            <div x-show="sections.positioning" x-cloak style="padding: 0 20px 20px; border-top: 1px solid var(--border-default);">
                {{-- BB139 Fix C — headline + short paragraphs instead of one wall of text. --}}
                <div class="positioning-card" style="margin-top: 16px;">
                    @if ($positioningHeadline !== '')
                        <p class="positioning-headline">{{ $positioningHeadline }}</p>
                    @endif
                    @foreach ($positioningParagraphs as $para)
                        <p class="positioning-paragraph">{{ $para }}</p>
                    @endforeach
                    @if ($positioningTimeframe !== '')
                        <p class="positioning-timeframe">{{ $positioningTimeframe }}</p>
                    @endif
                </div>
            </div>
        </x-nui-card>
    @endif

    {{-- ===== Accordion: Keterbatasan Analisis ===== --}}
    @if (! empty($limitations))
        <x-nui-card padding="none" style="margin-bottom: 10px;">
            <button type="button" @click="sections.limitations = !sections.limitations" class="w-full flex items-center justify-between" style="padding: 16px 20px; background: none; border: none; cursor: pointer; text-align: left;">
                <div>
                    <p style="font-size: 14px; font-weight: 600; color: var(--text-tertiary); margin: 0;">Keterbatasan Analisis</p>
                    <p style="font-size: 12px; color: var(--text-tertiary); margin-top: 2px;">{{ count($limitations) }} keterbatasan dicatat (transparansi)</p>
                </div>
                <span x-text="sections.limitations ? '−' : '+'" style="font-size: 22px; color: var(--text-tertiary); font-weight: 300; line-height: 1;"></span>
            </button>
            <div x-show="sections.limitations" x-cloak style="padding: 0 20px 20px; border-top: 1px solid var(--border-default);">
                {{-- BB139 Fix C — tag-style cards. Each item is a string today
                     (rendered as the "what"); a future ticket may emit
                     {what, impact} objects, handled here too. --}}
                <div class="limitations-list">
                    @foreach ($limitations as $lim)
                        <div class="limitation-card">
                            <div class="limitation-marker">!</div>
                            <div class="limitation-body">
                                @if (is_array($lim))
                                    <p class="limitation-what">{{ $lim['what'] ?? '' }}</p>
                                    @if (! empty($lim['impact']))
                                        <p class="limitation-impact">{{ $lim['impact'] }}</p>
                                    @endif
                                @else
                                    <p class="limitation-what">{{ $lim }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </x-nui-card>
    @endif

    @endif {{-- /renderFullSection --}}
</div>
