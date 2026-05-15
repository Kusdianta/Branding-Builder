{{-- BB41: Kemenangan Cepat (Quick Wins) — bullet list with time badges.
     Source: brand_audits.quick_wins (BB36 column, BB37 generator). --}}
@php
    $quickWins = (array) ($audit->quick_wins ?? []);
    $quickWins = array_values(array_filter($quickWins, fn ($w) => is_array($w) && ! empty($w['action'])));

    $minutesLabel = function (int $m): string {
        return match (true) {
            $m <= 5  => '5 menit',
            $m <= 10 => '10 menit',
            $m <= 15 => '15 menit',
            $m <= 30 => '30 menit',
            $m <= 60 => '1 jam',
            $m <= 90 => '1,5 jam',
            default  => '~2 jam',
        };
    };
@endphp

@if (count($quickWins) > 0)
    <h2 style="font-size: 16px; color: #0F1411; margin: 24px 0 6px 0;">{{ $sectionNumber }}. Kemenangan Cepat</h2>
    <p style="font-size: 10px; color: #5A6259; margin: 0 0 14px 0; line-height: 1.55; font-style: italic;">
        Tindakan kecil dengan dampak terukur — bisa diselesaikan minggu ini.
    </p>

    <table style="margin-bottom: 18px; border: 1px solid rgba(15,20,17,0.08); border-radius: 6px;">
        @foreach ($quickWins as $i => $w)
            @php
                $action = (string) $w['action'];
                $mins   = (int) ($w['estimated_minutes'] ?? 0);
            @endphp
            <tr style="border-bottom: {{ $i === count($quickWins) - 1 ? 'none' : '1px solid rgba(15,20,17,0.05)' }};">
                <td style="padding: 9px 14px; vertical-align: top; width: 80%;">
                    <table>
                        <tr>
                            <td style="width: 14px; vertical-align: top; padding-right: 6px;">
                                <span style="font-size: 12px; color: #3D8948; font-weight: bold;">▸</span>
                            </td>
                            <td>
                                <p style="font-size: 10px; color: #0F1411; line-height: 1.55; margin: 0;">{{ $action }}</p>
                            </td>
                        </tr>
                    </table>
                </td>
                <td style="padding: 9px 14px; vertical-align: top; width: 20%; text-align: right; white-space: nowrap;">
                    @if ($mins > 0)
                        <span style="display: inline-block; font-size: 8px; font-weight: bold; color: #326D3A; background: #E8F1E5; padding: 3px 9px; border-radius: 999px; letter-spacing: 0.3px;">{{ $minutesLabel($mins) }}</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </table>
@endif
