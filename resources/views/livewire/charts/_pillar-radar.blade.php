@php
    /**
     * BB138 Chart 2 — Pillar radar (Chart.js).
     *
     * Renders the 4 pillar scores on a 0-100 radar against an "ideal" ring.
     * Augments (does not replace) the pillar breakdown table above it.
     *
     * Expects: $pillarMeta (slug => ['label' => ...]), $pillarScoreInts.
     */
    $radarLabels = [];
    $radarScores = [];
    foreach ($pillarMeta as $slug => $meta) {
        // Split two-word labels onto two lines for a cleaner radar axis.
        $label = $meta['label'] ?? $slug;
        $parts = explode(' ', $label, 2);
        $radarLabels[] = count($parts) === 2 ? $parts : $label;
        $radarScores[] = (int) ($pillarScoreInts[$slug] ?? 0);
    }
    $radarData = ['labels' => $radarLabels, 'scores' => $radarScores];
@endphp
@if (count($radarScores) > 0)
    <div class="bb-chart-card max-w-3xl mx-auto mb-12">
        <p class="bb-chart-card__label">Profil Skor Pilar</p>
        <div class="bb-chart-container bb-chart-container--radar" wire:ignore>
            <canvas
                data-chart-type="pillar-radar"
                data-chart-data="{{ json_encode($radarData, JSON_THROW_ON_ERROR) }}"
                role="img"
                aria-label="Radar chart skor 4 pilar brand health"
            ></canvas>
        </div>
    </div>
@endif
