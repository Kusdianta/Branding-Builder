@php
    /**
     * BB138 Chart 4 — Digital Presence touchpoint grid.
     *
     * Six geometric tiles (the real digital-presence sub-buckets) lit/dim by
     * whether they scored points. Augments the ✓/✗ list below it (which
     * keeps the per-touchpoint descriptions). Points reflect the actual
     * scored value — no fabricated caps.
     *
     * Expects: $sbs (digital-presence sub_bucket_scores).
     */
    $tpOrder = [
        'has_gmaps'     => 'GM',
        'has_instagram' => 'IG',
        'has_website'   => 'WEB',
        'has_wa'        => 'WA',
        'has_tiktok'    => 'TT',
        'review_bonus'  => '★',
    ];
    $tpTiles = [];
    foreach ($tpOrder as $key => $abbr) {
        if (! array_key_exists($key, (array) $sbs)) {
            continue;
        }
        $pts = (int) ($sbs[$key] ?? 0);
        $tpTiles[] = [
            'abbr'  => $abbr,
            'label' => \App\Support\AuditLabels::subBucket($key),
            'pts'   => $pts,
            'on'    => $pts > 0,
        ];
    }
@endphp
@if (count($tpTiles) > 0)
    <div class="bb-touchpoint-grid" role="img" aria-label="Kehadiran touchpoint digital">
        @foreach ($tpTiles as $tile)
            <div class="bb-touchpoint {{ $tile['on'] ? 'bb-touchpoint--on' : 'bb-touchpoint--off' }}">
                <span class="bb-touchpoint__disc">{{ $tile['abbr'] }}</span>
                <span class="bb-touchpoint__name">{{ $tile['label'] }}</span>
                <span class="bb-touchpoint__pts">{{ $tile['pts'] }} pt</span>
            </div>
        @endforeach
    </div>
@endif
