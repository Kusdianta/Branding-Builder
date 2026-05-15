{{-- BB39: Executive Summary — apikprimadya-style narrative.
     Replaces the binary positive/negative two-column "Temuan Utama"
     block with a 2-3 paragraph synthesis weaving the data, ending
     with a one-line tier conclusion. --}}
@php
    // Build a single paragraph stitching together the strongest pillar
    // signal, the weakest pillar gap, and the touchpoint posture. Where
    // CompetitivePositioningGenerator output exists, it carries the more
    // bespoke narrative; otherwise we synthesise a deterministic one.
    $positioning = (array) ($audit->competitive_positioning ?? []);
    $useGenNarrative = ! empty($positioning['narrative']);

    $pillarRanked = [];
    foreach ($pillarOrder as $slug) {
        $s = $pillarScores[$slug]['score'] ?? null;
        if (is_numeric($s)) {
            $pillarRanked[$slug] = (int) $s;
        }
    }
    arsort($pillarRanked);
    $strongestSlug = array_key_first($pillarRanked) ?? null;
    $weakestSlug   = array_key_last($pillarRanked) ?? null;

    $tierConclusion = sprintf(
        'Skor %s/100 menempatkan brand di tier "%s" — %s',
        (string) ($overallScore ?? '—'),
        $overallLabel,
        match (true) {
            ($overallScore ?? 0) >= 80 => 'pertahankan momentum dengan polish berkelanjutan.',
            ($overallScore ?? 0) >= 70 => 'satu siklus perbaikan terfokus bisa membawa brand ke tier teratas.',
            ($overallScore ?? 0) >= 50 => 'potensi pertumbuhan signifikan jika gap pillar terlemah ditutup sistematis.',
            default                    => 'mulai dari rekomendasi prioritas tinggi untuk membangun fondasi.',
        },
    );
@endphp

<h2 style="font-size: 14px; color: #0F1411; margin: 0 0 12px 0; letter-spacing: -0.2px;">Ringkasan Eksekutif</h2>

@if ($useGenNarrative)
    <p style="font-size: 11px; color: #0F1411; line-height: 1.7; margin: 0 0 12px 0;">
        {{ $positioning['narrative'] }}
    </p>
@else
    <p style="font-size: 11px; color: #0F1411; line-height: 1.7; margin: 0 0 12px 0;">
        {{ $brandName }} adalah brand laundry @if (! empty($audit->city)) di {{ $audit->city }} @endif dengan skor brand health
        keseluruhan {{ $overallScore ?? '—' }}/100.
        @if ($strongestSlug && $weakestSlug && $strongestSlug !== $weakestSlug)
            Kekuatan terbesar berada di pilar <strong>{{ $pillarLabels[$strongestSlug] ?? $strongestSlug }}</strong>
            (skor {{ $pillarRanked[$strongestSlug] }}/100), sementara area terlemah ada di pilar
            <strong>{{ $pillarLabels[$weakestSlug] ?? $weakestSlug }}</strong>
            (skor {{ $pillarRanked[$weakestSlug] }}/100) — fokus utama untuk peningkatan brand health berikutnya.
        @endif
    </p>
@endif

<p style="font-size: 11px; color: #5A6259; line-height: 1.7; margin: 0 0 24px 0; font-style: italic;">
    {{ $tierConclusion }}
</p>
