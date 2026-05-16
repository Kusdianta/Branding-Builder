@php
    /**
     * Phase 9 BB39-BB43: apikprimadya-style PDF.
     *
     * DomPDF constraints applied throughout this template + sections:
     *   - Inline styles only; no external CSS, no @import.
     *   - HEX color literals only; no var(--token).
     *   - 'DejaVu Sans' family for full Indonesian unicode support.
     *   - No flexbox / grid; <table> for any column layout.
     *   - Page breaks via <div style="page-break-before: always;">.
     *
     * Section partials live in resources/views/pdf/sections/ — each
     * is a self-contained chunk that consumes the variables this
     * file pre-computes. Sections in render order:
     *
     *   1. cover                     (overall score circle, brand header)
     *   2. executive-summary         (2-paragraph synthesis + tier conclusion)
     *   3. pillar (looped × N)       (per-pillar table + status icons + 3 findings)
     *   4. _instagram-audit          (existing Phase 7-C scorecard, unchanged)
     *   5. recommendations           (5 ranked cards w/ priority/effort/impact pills)
     *   6. quick-wins                (5-7 micro-actions w/ time badges)
     *   7. competitive-positioning   (narrative + Peluang Pertumbuhan callout)
     *   8. scorecard                 (final A-F table)
     *   9. methodology               (appendix footer note)
     */

    $brandName     = $audit->brand_name ?: '—';
    $generatedAt   = now()->locale('id')->translatedFormat('d F Y H:i');
    $overallScore  = $audit->overall_score;
    $overallLabel  = $audit->overall_label ?: '—';
    $pillarScores  = (array) ($audit->pillar_scores ?? []);
    $subBuckets    = (array) ($audit->sub_bucket_scores ?? []);
    $scoreBreakdown = (array) ($audit->score_breakdown ?? []);

    // BB60: validation warning surface for the PDF banner.
    $validation = (array) ($audit->audit_evidence['validation'] ?? []);
    $hasValidationWarning = $audit->hasValidationWarning()
        || ((float) ($validation['confidence'] ?? 1.0)) < 0.5;
    $validationWarnings = (array) ($validation['warnings'] ?? []);
    $validationConfidence = (float) ($validation['confidence'] ?? 1.0);

    $tierColor = static fn (?int $s): string => match (true) {
        ($s ?? 0) >= 70 => '#3D8948', // chimera-500
        ($s ?? 0) >= 50 => '#C97A1B', // warning amber
        default         => '#C24E3A', // danger
    };
    $overallColor = $tierColor($overallScore);

    // Pillar 1-4 occupy section numbers 1-4. The IG audit doesn't get a
    // numbered slot (it's a Phase 7-C scorecard with its own structure).
    // Recommendations / Quick Wins / Positioning / Scorecard get section
    // numbers 5-8. Methodology is an unnumbered appendix.
    $sectionAfterPillars = count($pillarOrder) + 1;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Brand Health Check — {{ $brandName }}</title>
    <style>
        @page { margin: 36px 32px 56px 32px; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            line-height: 1.5;
            color: #0F1411;
            margin: 0;
            padding: 0;
            background: #FFFFFF;
        }
        h1, h2, h3, h4 { margin: 0; font-weight: bold; color: #0F1411; }
        p { margin: 0 0 6px 0; }
        table { border-collapse: collapse; width: 100%; }
        td { vertical-align: top; }
        .footer-line {
            position: fixed; bottom: -38px; left: 0; right: 0;
            font-size: 8px; color: #8A9088;
            border-top: 1px solid rgba(15,20,17,0.08);
            padding-top: 8px;
        }
        .footer-line .pagenum:before { content: counter(page); }
    </style>
</head>
<body>

<div class="footer-line">
    <table>
        <tr>
            <td style="text-align: left; font-size: 8px; color: #8A9088;">
                Nema Brand Health Check &middot; {{ $generatedAt }}
            </td>
            <td style="text-align: right; font-size: 8px; color: #8A9088;">
                Halaman <span class="pagenum"></span>
            </td>
        </tr>
    </table>
</div>

{{-- ========================== 1. Cover ========================== --}}
@include('pdf.sections.cover')

{{-- BB60: validation warning banner — sits between Cover and
     Executive Summary so the reader sees it before any scores. --}}
@if ($hasValidationWarning)
    <div style="page-break-before: always; padding: 20px; margin-bottom: 16px; background: rgba(201, 122, 27, 0.08); border: 1px solid #C97A1B; border-radius: 12px;">
        <table style="width: 100%;">
            <tr>
                <td style="width: 28px; vertical-align: top; font-size: 18px; color: #C97A1B; line-height: 1;">⚠</td>
                <td style="vertical-align: top;">
                    <p style="font-size: 12px; font-weight: bold; color: #0F1411; margin: 0 0 6px 0;">
                        Peringatan Validasi
                    </p>
                    <p style="font-size: 10px; color: #5A6259; margin: 0 0 8px 0;">
                        Sistem mendeteksi kemungkinan ketidakcocokan antara brand input dan URL yang discrap. Skor di bawah dihitung dari data yang discrap — jika brand/lokasi tidak cocok, hasil audit mungkin tidak akurat. Confidence: {{ number_format($validationConfidence * 100, 0) }}/100.
                    </p>
                    @if (! empty($validationWarnings))
                        <ul style="font-size: 10px; color: #5A6259; margin: 0; padding-left: 14px;">
                            @foreach ($validationWarnings as $warn)
                                <li style="margin-bottom: 3px;">{{ $warn }}</li>
                            @endforeach
                        </ul>
                    @endif
                </td>
            </tr>
        </table>
    </div>
@endif

{{-- ========================== 2. Executive Summary ========================== --}}
@include('pdf.sections.executive-summary')

{{-- ========================== 3-N. Pillar sections ========================== --}}
@foreach ($pillarOrder as $loopIdx => $slug)
    @php
        $data         = $pillarScores[$slug] ?? null;
        $pillarIndex  = $loopIdx + 1;
        $pillarScore  = is_array($data) ? ($data['score'] ?? null) : null;
        $pillarColor  = $tierColor($pillarScore);
        $evidence     = is_array($data) ? (array) ($data['evidence'] ?? []) : [];
        $reasoning    = is_array($data) ? (string) ($data['reasoning'] ?? '') : '';
        $bucketScores = (array) ($subBuckets[$slug] ?? []);
        $bdByBucket   = (array) ($scoreBreakdown[$slug] ?? []);
        $pillarLabel  = $pillarLabels[$slug] ?? $slug;
    @endphp
    @include('pdf.sections.pillar')
@endforeach

{{-- ========================== Instagram Audit (Phase 7-C, existing) ========================== --}}
@if (! empty($audit->instagram_audit) && ($audit->instagram_audit_status ?? '') === 'done')
    <div style="page-break-before: always;"></div>
    @include('pdf._instagram-audit', ['audit' => $audit])
@endif

{{-- ========================== Recommendations + Quick Wins ========================== --}}
@include('pdf.sections.recommendations', ['sectionNumber' => $sectionAfterPillars])
@include('pdf.sections.quick-wins',      ['sectionNumber' => $sectionAfterPillars + 1])

{{-- ========================== Positioning + Scorecard ========================== --}}
@include('pdf.sections.competitive-positioning', ['sectionNumber' => $sectionAfterPillars + 2])
@include('pdf.sections.scorecard',                ['sectionNumber' => $sectionAfterPillars + 3])

{{-- ========================== Methodology Appendix ========================== --}}
@include('pdf.sections.methodology')

</body>
</html>
