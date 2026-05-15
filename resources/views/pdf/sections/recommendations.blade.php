{{-- BB40: 5 Rekomendasi Tindakan Utama — apikprimadya-style cards.
     Each card: rank + title, then a Prioritas/Usaha/Dampak pill row,
     then the body description. Source: brand_audits.recommendations
     (BB36 column, BB37 generator, BB38 wiring). --}}
@php
    $recs = (array) ($audit->recommendations ?? []);
    // Filter to only the new BB37 shape (rank/priority/effort/impact/title/description).
    // Legacy shape (pre-Phase-9) lacks 'priority' so we hide it here — those audits
    // need a regen to surface in this section.
    $recs = array_values(array_filter($recs, fn ($r) => is_array($r) && isset($r['priority'], $r['effort'], $r['impact'])));

    $pillColor = function (string $value, string $kind): array {
        // Color map: priority TINGGI = chimera, SEDANG = warning amber, RENDAH = info blue
        // effort RENDAH = chimera, SEDANG = warning, TINGGI = danger
        // impact SANGAT TINGGI = chimera deep, TINGGI = chimera, SEDANG = warning, RENDAH = grey
        return match (true) {
            $kind === 'priority' && $value === 'TINGGI'        => ['bg' => '#3D8948', 'fg' => '#FFFFFF'],
            $kind === 'priority' && $value === 'SEDANG'        => ['bg' => '#C97A1B', 'fg' => '#FFFFFF'],
            $kind === 'priority' && $value === 'RENDAH'        => ['bg' => '#3D6E89', 'fg' => '#FFFFFF'],
            $kind === 'effort'   && $value === 'RENDAH'        => ['bg' => '#3D8948', 'fg' => '#FFFFFF'],
            $kind === 'effort'   && $value === 'SEDANG'        => ['bg' => '#C97A1B', 'fg' => '#FFFFFF'],
            $kind === 'effort'   && $value === 'TINGGI'        => ['bg' => '#C24E3A', 'fg' => '#FFFFFF'],
            $kind === 'impact'   && $value === 'SANGAT TINGGI' => ['bg' => '#285730', 'fg' => '#FFFFFF'],
            $kind === 'impact'   && $value === 'TINGGI'        => ['bg' => '#3D8948', 'fg' => '#FFFFFF'],
            $kind === 'impact'   && $value === 'SEDANG'        => ['bg' => '#C97A1B', 'fg' => '#FFFFFF'],
            $kind === 'impact'   && $value === 'RENDAH'        => ['bg' => '#8A9088', 'fg' => '#FFFFFF'],
            default                                            => ['bg' => '#5A6259', 'fg' => '#FFFFFF'],
        };
    };
@endphp

@if (count($recs) > 0)
    <div style="page-break-before: always;"></div>

    <h2 style="font-size: 18px; color: #0F1411; margin: 0 0 6px 0;">{{ $sectionNumber }}. Rekomendasi Utama</h2>
    <p style="font-size: 10px; color: #5A6259; margin: 0 0 18px 0; line-height: 1.55;">
        Lima tindakan paling impactful, diurutkan dari prioritas tertinggi. Setiap rekomendasi disertai pill Prioritas, Usaha, dan Dampak — gunakan sebagai panduan urutan eksekusi.
    </p>

    @foreach ($recs as $rec)
        @php
            $rank        = (int) ($rec['rank'] ?? 0);
            $title       = (string) ($rec['title'] ?? '');
            $description = (string) ($rec['description'] ?? '');
            $pPriority   = $pillColor((string) $rec['priority'], 'priority');
            $pEffort     = $pillColor((string) $rec['effort'],   'effort');
            $pImpact     = $pillColor((string) $rec['impact'],   'impact');
        @endphp
        <table style="margin-bottom: 14px; border: 1px solid rgba(15,20,17,0.08); border-radius: 6px; page-break-inside: avoid;">
            <tr>
                <td style="padding: 12px 14px;">
                    <p style="font-size: 13px; font-weight: bold; color: #0F1411; margin: 0;">
                        <span style="color: #3D8948;">#{{ $rank }}</span> &mdash; {{ $title }}
                    </p>
                    <p style="margin: 8px 0 8px 0;">
                        <span style="display: inline-block; font-size: 8px; font-weight: bold; color: {{ $pPriority['fg'] }}; background: {{ $pPriority['bg'] }}; padding: 3px 9px; border-radius: 999px; letter-spacing: 0.4px; text-transform: uppercase;">Prioritas: {{ $rec['priority'] }}</span>
                        <span style="display: inline-block; font-size: 8px; font-weight: bold; color: {{ $pEffort['fg'] }}; background: {{ $pEffort['bg'] }}; padding: 3px 9px; border-radius: 999px; letter-spacing: 0.4px; text-transform: uppercase; margin-left: 4px;">Usaha: {{ $rec['effort'] }}</span>
                        <span style="display: inline-block; font-size: 8px; font-weight: bold; color: {{ $pImpact['fg'] }}; background: {{ $pImpact['bg'] }}; padding: 3px 9px; border-radius: 999px; letter-spacing: 0.4px; text-transform: uppercase; margin-left: 4px;">Dampak: {{ $rec['impact'] }}</span>
                    </p>
                    <p style="font-size: 10px; color: #5A6259; line-height: 1.65; margin: 0;">{{ $description }}</p>
                </td>
            </tr>
        </table>
    @endforeach
@endif
