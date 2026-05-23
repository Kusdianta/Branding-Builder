@php
    /**
     * BB138 Chart 3 — Brand Experience waterfall.
     *
     * Base 30 -> bonuses -> penalties -> final score, as a horizontal
     * running-total bar chart. Bonuses come from the brand-experience
     * sub_bucket_scores; penalties are read from score_breakdown's
     * penalties.per_type (the sub_bucket_scores penalty rows are always 0 —
     * the real applied penalties live in the breakdown). Pure HTML/CSS.
     *
     * Expects: $sbs (brand-experience sub_bucket_scores), $scoreBreakdown, $ps.
     */
    $bePenalties = (array) ($scoreBreakdown['brand-experience']['penalties']['per_type'] ?? []);
    $beFinal = (int) ($ps ?? 0);

    $wfItems = [
        ['label' => 'Dasar',            'value' => (int) ($sbs['base'] ?? 30),                 'type' => 'base'],
        ['label' => 'Ekspres',          'value' => (int) ($sbs['bonus_ekspres'] ?? 0),         'type' => 'bonus'],
        ['label' => 'Antar-Jemput',     'value' => (int) ($sbs['bonus_antar_jemput'] ?? 0),    'type' => 'bonus'],
        ['label' => 'Variasi Layanan',  'value' => (int) ($sbs['bonus_variasi_layanan'] ?? 0), 'type' => 'bonus'],
        ['label' => 'SOP Keluhan',      'value' => (int) ($sbs['bonus_sop_keluhan'] ?? 0),     'type' => 'bonus'],
        ['label' => 'Daftar Harga',     'value' => (int) ($sbs['bonus_price_list'] ?? 0),      'type' => 'bonus'],
        ['label' => 'Keterlambatan',    'value' => (int) ($bePenalties['penalty_keterlambatan'] ?? 0),  'type' => 'penalty'],
        ['label' => 'Pakaian Hilang',   'value' => (int) ($bePenalties['penalty_pakaian_hilang'] ?? 0), 'type' => 'penalty'],
        ['label' => 'Tidak Respons WA', 'value' => (int) ($bePenalties['penalty_no_response_wa'] ?? 0),  'type' => 'penalty'],
        ['label' => 'Skor Akhir',       'value' => $beFinal,                                   'type' => 'total'],
    ];

    // Resolve each step into a positioned segment on a 0-100 scale.
    $scale = 100;
    $run = 0;
    $wfRows = [];
    foreach ($wfItems as $it) {
        $v = $it['value'];
        switch ($it['type']) {
            case 'base':
                $left = 0; $width = $v; $run = $v; break;
            case 'bonus':
                $left = $run; $width = $v; $run += $v; break;
            case 'penalty':
                $run += $v; $left = $run; $width = abs($v); break; // red span [new run, old run]
            default: // total
                $left = 0; $width = $v; break;
        }
        // Hide zero-value bonus/penalty steps — they add visual noise.
        if (($it['type'] === 'bonus' || $it['type'] === 'penalty') && $v === 0) {
            continue;
        }
        $wfRows[] = $it + [
            'leftPct'  => round(max(0, $left) / $scale * 100, 2),
            'widthPct' => round(max(0, $width) / $scale * 100, 2),
        ];
    }
@endphp
<div class="bb-waterfall" role="img" aria-label="Rincian skor Brand Experience: dasar plus bonus dikurangi penalti, skor akhir {{ $beFinal }}">
    <p class="bb-chart-card__label">Cara Skor Terbentuk</p>
    @foreach ($wfRows as $row)
        @php
            $sign = $row['type'] === 'penalty' ? '−' : ($row['type'] === 'bonus' ? '+' : '');
            $valShown = $sign . abs($row['value']);
        @endphp
        <div class="bb-waterfall__row">
            <span class="bb-waterfall__label">{{ $row['label'] }}</span>
            <div class="bb-waterfall__track">
                <span class="bb-waterfall__seg bb-waterfall__seg--{{ $row['type'] }}"
                      style="left: {{ $row['leftPct'] }}%; width: {{ $row['widthPct'] }}%;"></span>
            </div>
            <span class="bb-waterfall__value bb-waterfall__value--{{ $row['type'] }}">{{ $valShown }}</span>
        </div>
    @endforeach
</div>
