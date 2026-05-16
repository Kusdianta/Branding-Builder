{{-- BB39: Reusable per-pillar section — apikprimadya-style table format.
     Renders ONE pillar; called from the master template inside the
     pillar loop. Variables in scope (set by master loop):
       $pillarIndex (1-based int)
       $slug (string), $pillarLabel (string)
       $pillarScore (?int), $pillarColor (string hex)
       $reasoning (string), $bucketScores (array<string,int>)
       $bdByBucket (array<string,array>)
       $subBucketLabels (array — passed in from controller)

     Layout per page:
       - Section header with "Pilar N · Skor X/100"
       - 2-sentence pillar intro from LLM reasoning
       - Sub-bucket table: Bucket | Skor | Status (✓ ~ ✗) | Catatan
       - 3-bullet key findings with check/cross marks --}}
@php
    $bucketCount = count($bucketScores);

    // BB76 — flat-shape lookups for the BB75 ExperienceScorer breakdown.
    // The Experience pillar stores per-bucket data in sibling maps inside
    // the pillar dict (not in nested per-bucket dicts), so we surface
    // those here:
    //   $bdByBucket['sub_bucket_reasoning'][<bucket>]
    //   $bdByBucket['evidence_sources'][<bucket>]
    //   $bdByBucket['tier_classification'][<bucket>]
    $flatSubReasoning = is_array($bdByBucket['sub_bucket_reasoning'] ?? null)
        ? $bdByBucket['sub_bucket_reasoning'] : [];
    $flatSubCaps = is_array($bdByBucket['sub_bucket_caps'] ?? null)
        ? $bdByBucket['sub_bucket_caps'] : [];
    $flatTiers = is_array($bdByBucket['tier_classification'] ?? null)
        ? $bdByBucket['tier_classification'] : [];

    // Compute status icon from score-vs-cap ratio:
    //   >= 80% of cap  -> ✓
    //   >= 40% of cap  -> ~
    //   <  40% of cap  -> ✗
    $statusFor = function (int $score, ?int $cap): array {
        if (! $cap || $cap <= 0) {
            return ['icon' => '~', 'color' => '#8A9088'];
        }
        $ratio = $score / $cap;
        if ($ratio >= 0.8) return ['icon' => '✓', 'color' => '#3D8948'];
        if ($ratio >= 0.4) return ['icon' => '~', 'color' => '#C97A1B'];
        return ['icon' => '✗', 'color' => '#C24E3A'];
    };

    // BB76 tier pill colors per A/B/C/D classification.
    $tierColorFor = function (?string $tier): string {
        return match ($tier) {
            'A'     => '#3D8948',
            'B'     => '#326D3A',
            'C'     => '#C97A1B',
            default => '#8A9088',
        };
    };

    // Pull per-bucket reasoning. Try the legacy per-bucket dict shape
    // first; fall back to BB75's flat sub_bucket_reasoning map.
    $catatanFor = function (string $bucketKey, ?array $bd) use ($flatSubReasoning): string {
        if ($bd !== null) {
            $r = trim((string) ($bd['llm_reasoning'] ?? ''));
            if ($r !== '') {
                return mb_strlen($r) > 140 ? mb_substr($r, 0, 139) . '…' : $r;
            }
            // Deterministic pillars carry tier_table or signals.
            $formula = (string) ($bd['formula'] ?? '');
            if ($formula === 'deterministic_threshold') {
                $tt = (array) ($bd['tier_table'] ?? []);
                foreach ($tt as $tier) {
                    if (! empty($tier['matched'])) {
                        return sprintf('Tier "%s" (%s pt)', (string) ($tier['range'] ?? ''), (string) ($tier['points'] ?? '—'));
                    }
                }
            }
        }
        $r2 = trim((string) ($flatSubReasoning[$bucketKey] ?? ''));
        if ($r2 !== '') {
            return mb_strlen($r2) > 140 ? mb_substr($r2, 0, 139) . '…' : $r2;
        }
        return '—';
    };
@endphp

<div style="page-break-before: always;"></div>

<table style="margin-bottom: 12px;">
    <tr>
        <td style="width: 70%;">
            <p style="font-size: 8px; color: #8A9088; margin: 0; letter-spacing: 0.5px; text-transform: uppercase;">Pilar {{ $pillarIndex }} dari {{ count($pillarOrder) }}</p>
            <h2 style="font-size: 18px; color: #0F1411; margin: 4px 0 0 0;">{{ $pillarIndex }}. {{ $pillarLabel }}</h2>
        </td>
        <td style="width: 30%; text-align: right; vertical-align: middle;">
            <p style="font-size: 32px; font-weight: bold; color: {{ $pillarColor }}; margin: 0; line-height: 1;">{{ $pillarScore ?? '—' }}<span style="font-size: 14px; color: #8A9088;"> / 100</span></p>
        </td>
    </tr>
</table>

<div style="height: 5px; background: #E8F1E5; border-radius: 3px; margin-bottom: 16px;">
    <div style="height: 5px; background: {{ $pillarColor }}; width: {{ $pillarScore ?? 0 }}%; border-radius: 3px;"></div>
</div>

@if ($reasoning !== '')
    <p style="font-size: 11px; color: #0F1411; line-height: 1.65; margin: 0 0 18px 0;">{{ $reasoning }}</p>
@endif

@if ($bucketCount > 0)
    <h3 style="font-size: 10px; color: #5A6259; margin: 0 0 8px 0; letter-spacing: 0.4px; text-transform: uppercase;">Rincian Sub-Bucket</h3>
    <table style="border: 1px solid rgba(15,20,17,0.08); margin-bottom: 18px;">
        <thead>
            <tr style="background: #F7F9F5; border-bottom: 1px solid rgba(15,20,17,0.08);">
                <td style="padding: 6px 10px; font-size: 8px; font-weight: bold; color: #5A6259; letter-spacing: 0.3px; text-transform: uppercase; width: 30%;">Sub-Bucket</td>
                <td style="padding: 6px 10px; font-size: 8px; font-weight: bold; color: #5A6259; letter-spacing: 0.3px; text-transform: uppercase; width: 12%; text-align: center;">Skor</td>
                <td style="padding: 6px 10px; font-size: 8px; font-weight: bold; color: #5A6259; letter-spacing: 0.3px; text-transform: uppercase; width: 8%; text-align: center;">Status</td>
                <td style="padding: 6px 10px; font-size: 8px; font-weight: bold; color: #5A6259; letter-spacing: 0.3px; text-transform: uppercase; width: 50%;">Catatan</td>
            </tr>
        </thead>
        <tbody>
            @foreach ($bucketScores as $bucketKey => $bucketScore)
                @php
                    $bd       = is_array($bdByBucket[$bucketKey] ?? null) ? $bdByBucket[$bucketKey] : null;
                    $cap      = is_int($bd['cap'] ?? null) ? (int) $bd['cap']
                                : (is_int($flatSubCaps[$bucketKey] ?? null) ? (int) $flatSubCaps[$bucketKey] : null);
                    $status   = $statusFor((int) $bucketScore, $cap);
                    $catatan  = $catatanFor((string) $bucketKey, $bd);
                    // BB76 — tier pill from BB75 flat shape (only meaningful
                    // for the Experience pillar). Renders next to status when
                    // present so readers can see WHY a bonus fired/didn't.
                    $tier = is_string($flatTiers[$bucketKey] ?? null) ? $flatTiers[$bucketKey] : null;
                @endphp
                <tr style="border-bottom: 1px solid rgba(15,20,17,0.05); page-break-inside: avoid;">
                    <td style="padding: 6px 10px; font-size: 9px; color: #0F1411;">{{ $subBucketLabels[$bucketKey] ?? $bucketKey }}</td>
                    <td style="padding: 6px 10px; font-size: 9px; font-weight: bold; color: #0F1411; text-align: center;">{{ $bucketScore }}{{ $cap !== null ? '/' . $cap : '' }}</td>
                    <td style="padding: 6px 10px; font-size: 12px; font-weight: bold; color: {{ $status['color'] }}; text-align: center;">
                        {{ $status['icon'] }}@if ($tier !== null)<span style="font-size: 7px; font-weight: bold; color: {{ $tierColorFor($tier) }}; margin-left: 4px; padding: 1px 4px; border: 1px solid {{ $tierColorFor($tier) }}; border-radius: 3px;">T{{ $tier }}</span>@endif
                    </td>
                    <td style="padding: 6px 10px; font-size: 8px; color: #5A6259; line-height: 1.5;">{{ $catatan }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if (count($evidence) > 0)
    <h3 style="font-size: 10px; color: #5A6259; margin: 0 0 8px 0; letter-spacing: 0.4px; text-transform: uppercase;">Temuan Kunci</h3>
    @foreach (array_slice($evidence, 0, 3) as $f)
        @php
            $impact = is_array($f) ? (string) ($f['impact'] ?? 'neutral') : 'neutral';
            $obs    = is_array($f) ? (string) ($f['observation'] ?? '') : (string) $f;
            $tp     = is_array($f) ? (string) ($f['touchpoint'] ?? '') : '';
            $mark   = match ($impact) {
                'positive' => ['icon' => '✓', 'color' => '#3D8948'],
                'negative' => ['icon' => '✗', 'color' => '#C24E3A'],
                default    => ['icon' => '~', 'color' => '#8A9088'],
            };
        @endphp
        <table style="margin-bottom: 6px; page-break-inside: avoid;">
            <tr>
                <td style="width: 16px; vertical-align: top; padding-right: 6px;">
                    <span style="font-size: 11px; font-weight: bold; color: {{ $mark['color'] }};">{{ $mark['icon'] }}</span>
                </td>
                <td style="vertical-align: top;">
                    <p style="font-size: 10px; color: #0F1411; line-height: 1.55; margin: 0;">{{ $obs }}</p>
                    @if ($tp !== '')
                        <p style="font-size: 8px; color: #8A9088; margin: 2px 0 0 0;">{{ $tp }}</p>
                    @endif
                </td>
            </tr>
        </table>
    @endforeach
@endif
