{{-- BB39: PDF cover page — apikprimadya-style.
     Brand mark + brand name + city + audit date + overall score circle
     + tier label + 2-sentence brand description. --}}
@php
    $tierDescription = match (true) {
        ($overallScore ?? 0) >= 80 => 'Brand Anda sudah sangat kuat di pasarnya. Fokus pada area terkecil untuk mempertahankan posisi premium.',
        ($overallScore ?? 0) >= 70 => 'Brand Anda di posisi baik dengan potensi tinggi. Beberapa area perlu penguatan untuk mencapai tier teratas.',
        ($overallScore ?? 0) >= 50 => 'Brand Anda memiliki fondasi yang baik namun beberapa pilar membutuhkan perhatian sistematis.',
        default                    => 'Brand Anda memiliki ruang pertumbuhan yang signifikan. Mulai dari rekomendasi prioritas tinggi untuk dampak tercepat.',
    };
@endphp

<table style="margin-bottom: 24px; border-bottom: 2px solid #3D8948; padding-bottom: 14px;">
    <tr>
        <td style="width: 60%;">
            <table>
                <tr>
                    <td style="width: 36px; vertical-align: middle;">
                        <svg width="32" height="32" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="16" cy="16" r="16" fill="#3D8948"/>
                            <path d="M9 22 C 12 12, 22 10, 23 9 C 22 18, 18 22, 9 22 Z" fill="#FFFFFF"/>
                            <path d="M9 22 L 16 14" stroke="#3D8948" stroke-width="1.4" fill="none"/>
                        </svg>
                    </td>
                    <td style="vertical-align: middle; padding-left: 8px;">
                        <p style="font-size: 14px; font-weight: bold; color: #3D8948; margin: 0;">Nema</p>
                        <p style="font-size: 9px; color: #5A6259; margin: 0;">Brand Health Check Report</p>
                    </td>
                </tr>
            </table>
        </td>
        <td style="width: 40%; text-align: right;">
            <p style="font-size: 8px; color: #8A9088; margin: 0; letter-spacing: 0.5px; text-transform: uppercase;">Diterbitkan</p>
            <p style="font-size: 10px; color: #5A6259; margin: 2px 0 0 0;">{{ $generatedAt }}</p>
        </td>
    </tr>
</table>

<div style="text-align: center; margin: 28px 0 32px 0;">
    <p style="font-size: 9px; color: #8A9088; margin: 0; letter-spacing: 1px; text-transform: uppercase;">AUDIT BRAND</p>
    <h1 style="font-size: 28px; color: #0F1411; margin: 8px 0 4px 0; letter-spacing: -0.5px;">{{ $brandName }}</h1>
    @if (! empty($audit->city))
        <p style="font-size: 12px; color: #5A6259; margin: 0;">{{ $audit->city }} · {{ ucfirst((string) $audit->service_type) }}</p>
    @endif
</div>

<div style="background: #F0F4EE; border: 1px solid rgba(15,20,17,0.08); border-radius: 12px; padding: 24px; margin-bottom: 24px;">
    <table>
        <tr>
            <td style="width: 32%; text-align: center; vertical-align: middle;">
                <div style="display: inline-block; width: 130px; height: 130px; border: 8px solid {{ $overallColor }}; border-radius: 65px; text-align: center; padding-top: 30px; box-sizing: border-box;">
                    <p style="font-size: 38px; font-weight: bold; color: {{ $overallColor }}; margin: 0; line-height: 1;">{{ $overallScore ?? '—' }}</p>
                    <p style="font-size: 9px; color: #8A9088; margin: 4px 0 0 0;">dari 100</p>
                </div>
            </td>
            <td style="width: 68%; vertical-align: middle; padding-left: 28px;">
                <p style="font-size: 8px; color: #8A9088; margin: 0; letter-spacing: 0.5px; text-transform: uppercase;">Skor Keseluruhan</p>
                <p style="font-size: 18px; font-weight: bold; color: #0F1411; margin: 6px 0 12px 0;">{{ $overallLabel }}</p>
                <p style="font-size: 11px; color: #5A6259; line-height: 1.6; margin: 0;">{{ $tierDescription }}</p>
            </td>
        </tr>
    </table>
</div>
