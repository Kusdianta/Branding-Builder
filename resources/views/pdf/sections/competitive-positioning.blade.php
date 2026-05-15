{{-- BB42: Positioning Kompetitif + Peluang Pertumbuhan callout —
     apikprimadya-style. Source: brand_audits.competitive_positioning
     (BB36 column, BB37 CompetitivePositioningGenerator). --}}
@php
    $positioning      = (array) ($audit->competitive_positioning ?? []);
    $narrative        = trim((string) ($positioning['narrative'] ?? ''));
    $growthOpportunity = trim((string) ($positioning['growth_opportunity'] ?? ''));
    $hasContent       = $narrative !== '' || $growthOpportunity !== '';
@endphp

@if ($hasContent)
    <div style="page-break-before: always;"></div>

    <h2 style="font-size: 18px; color: #0F1411; margin: 0 0 8px 0;">{{ $sectionNumber }}. Positioning Kompetitif</h2>

    @if ($narrative !== '')
        <p style="font-size: 11px; color: #0F1411; line-height: 1.7; margin: 0 0 18px 0;">{{ $narrative }}</p>
    @endif

    @if ($growthOpportunity !== '')
        <table style="margin-bottom: 24px; background: #E8F1E5; border-left: 4px solid #3D8948; border-radius: 4px; page-break-inside: avoid;">
            <tr>
                <td style="padding: 14px 18px;">
                    <p style="font-size: 9px; font-weight: bold; color: #326D3A; margin: 0 0 6px 0; letter-spacing: 0.5px; text-transform: uppercase;">★ Peluang Pertumbuhan</p>
                    <p style="font-size: 12px; font-weight: 600; color: #142D18; line-height: 1.6; margin: 0;">{{ $growthOpportunity }}</p>
                </td>
            </tr>
        </table>
    @endif
@endif
