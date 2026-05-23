@php
    /**
     * BB138 Chart 7 — Owner reply-rate gauge (270° speedometer).
     *
     * Compact circular gauge for the Manajemen Ulasan sub-bucket. Fill colour
     * tiers on the reply rate. Pure SVG; the fill arc animates in via CSS.
     *
     * Expects: $replyRatePct (float|int 0-100).
     */
    $rr = max(0.0, min(100.0, (float) ($replyRatePct ?? 0)));
    $circ = 201.06;     // 2πr, r=32
    $arc270 = 150.80;   // 270° of circ
    $fill = round($arc270 * $rr / 100, 2);
    $tierVar = $rr >= 95 ? '--bb-reply-high' : ($rr >= 50 ? '--bb-reply-mid' : '--bb-reply-low');
    $rrLabel = $rr >= 95 ? 'Sangat responsif' : ($rr >= 50 ? 'Cukup responsif' : 'Jarang membalas');
@endphp
<div class="bb-reply-gauge" role="img" aria-label="Tingkat balasan pemilik {{ round($rr) }} persen — {{ $rrLabel }}">
    <svg viewBox="0 0 80 80" width="80" height="80" aria-hidden="true">
        <g transform="rotate(135 40 40)">
            <circle cx="40" cy="40" r="32" fill="none" stroke="var(--border-default)" stroke-width="7"
                    stroke-dasharray="150.80 201.06" stroke-linecap="round" />
            <circle class="bb-reply-gauge__fill" cx="40" cy="40" r="32" fill="none" stroke="var({{ $tierVar }})"
                    stroke-width="7" stroke-dasharray="{{ $fill }} {{ $circ }}" stroke-linecap="round"
                    style="--bb-reply-fill: {{ $fill }};" />
        </g>
        <text x="40" y="38" text-anchor="middle" style="font-size: 16px; font-weight: 700; fill: var(--text-primary);">{{ round($rr) }}%</text>
        <text x="40" y="51" text-anchor="middle" style="font-size: 7px; fill: var(--text-tertiary);">balasan</text>
    </svg>
    <span class="bb-reply-gauge__caption">{{ $rrLabel }}</span>
</div>
