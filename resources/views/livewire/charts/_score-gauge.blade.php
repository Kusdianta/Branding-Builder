@php
    /**
     * BB138 Chart 1 — Overall brand-health score gauge.
     *
     * A 180° arc split into 5 colour zones with a needle pointing at the
     * score. Pure inline SVG; the needle swings from 0 to the score via a
     * CSS animation (graceful: the presentation-attribute rotate() holds the
     * correct final position when CSS/animation is unavailable).
     *
     * Expects: $overallScore (?int 0-100), $overallLabel (?string).
     */
    $gs = (int) ($overallScore ?? 0);

    // Zone boundaries map score -> arc angle linearly (0 = left, 100 = right).
    $gaugeZones = [
        [0, 35, '--bb-gauge-critical'],
        [35, 55, '--bb-gauge-below'],
        [55, 70, '--bb-gauge-average'],
        [70, 85, '--bb-gauge-good'],
        [85, 100, '--bb-gauge-excellent'],
    ];

    $gPoint = static function (float $score): array {
        $r = 80.0; $cx = 100.0; $cy = 100.0;
        $t = M_PI * (1 - max(0.0, min(100.0, $score)) / 100);
        return [round($cx + $r * cos($t), 2), round($cy - $r * sin($t), 2)];
    };

    $needleDeg = round(180 * max(0, min(100, $gs)) / 100, 2);
    $gaugeLabel = $overallLabel ?? null;
@endphp
<div class="bb-score-gauge-wrapper" role="img"
     aria-label="Skor Brand Health: {{ $overallScore ?? 'tidak tersedia' }} dari 100{{ $gaugeLabel ? ', ' . $gaugeLabel : '' }}">
    <svg class="bb-score-gauge" viewBox="0 0 200 140" xmlns="http://www.w3.org/2000/svg">
        @foreach ($gaugeZones as [$z0, $z1, $zvar])
            @php [$x1, $y1] = $gPoint($z0); [$x2, $y2] = $gPoint($z1); @endphp
            <path d="M {{ $x1 }} {{ $y1 }} A 80 80 0 0 1 {{ $x2 }} {{ $y2 }}"
                  fill="none" stroke="var({{ $zvar }})" stroke-width="13" />
        @endforeach
        <g class="bb-gauge-needle" style="--bb-needle-deg: {{ $needleDeg }}deg;"
           transform="rotate({{ $needleDeg }} 100 100)">
            <line x1="100" y1="100" x2="32" y2="100" stroke="var(--text-primary)" stroke-width="3" stroke-linecap="round" />
        </g>
        <circle cx="100" cy="100" r="6" fill="var(--text-primary)" />
        <text x="100" y="130" text-anchor="middle"
              style="font-size: 30px; font-weight: 700; fill: var(--text-primary); font-family: var(--font-display);">{{ $overallScore ?? '—' }}</text>
    </svg>
</div>
