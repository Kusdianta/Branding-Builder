{{-- BB42: Kartu Skor Ringkasan — final summary table with grades A-F.
     Mirrors apikprimadya page-9 scorecard. --}}
@php
    // Grade lookup matching the apikprimadya thresholds:
    //  85-100 = A, 70-84 = A-/B+, 55-69 = B/B-/C+, 35-54 = C/C-/D, 0-34 = D-/F
    $gradeFor = function (?int $score): string {
        if ($score === null) return '—';
        return match (true) {
            $score >= 85 => 'A',
            $score >= 80 => 'A-',
            $score >= 75 => 'B+',
            $score >= 70 => 'B',
            $score >= 65 => 'B-',
            $score >= 60 => 'C+',
            $score >= 55 => 'C',
            $score >= 50 => 'C-',
            $score >= 45 => 'D+',
            $score >= 35 => 'D',
            default      => 'F',
        };
    };

    $rows = [];
    foreach ($pillarOrder as $slug) {
        $score = $pillarScores[$slug]['score'] ?? null;
        $rows[] = [
            'label' => $pillarLabels[$slug] ?? $slug,
            'score' => is_numeric($score) ? (int) $score : null,
            'grade' => $gradeFor(is_numeric($score) ? (int) $score : null),
        ];
    }
@endphp

<h2 style="font-size: 16px; color: #0F1411; margin: 24px 0 8px 0;">{{ $sectionNumber }}. Kartu Skor Ringkasan</h2>
<p style="font-size: 10px; color: #5A6259; margin: 0 0 14px 0; line-height: 1.55; font-style: italic;">
    Konversi skor angka ke grade huruf untuk perbandingan cepat antar pillar dan terhadap benchmark target (A-).
</p>

<table style="border: 1px solid rgba(15,20,17,0.08); margin-bottom: 24px;">
    <thead>
        <tr style="background: #F7F9F5; border-bottom: 1px solid rgba(15,20,17,0.08);">
            <td style="padding: 8px 12px; font-size: 9px; font-weight: bold; color: #5A6259; letter-spacing: 0.4px; text-transform: uppercase; width: 60%;">Kategori</td>
            <td style="padding: 8px 12px; font-size: 9px; font-weight: bold; color: #5A6259; letter-spacing: 0.4px; text-transform: uppercase; width: 20%; text-align: center;">Skor</td>
            <td style="padding: 8px 12px; font-size: 9px; font-weight: bold; color: #5A6259; letter-spacing: 0.4px; text-transform: uppercase; width: 20%; text-align: center;">Nilai</td>
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $row)
            <tr style="border-bottom: 1px solid rgba(15,20,17,0.05);">
                <td style="padding: 8px 12px; font-size: 11px; color: #0F1411;">{{ $row['label'] }}</td>
                <td style="padding: 8px 12px; font-size: 11px; color: #0F1411; text-align: center;">{{ $row['score'] ?? '—' }} / 100</td>
                <td style="padding: 8px 12px; font-size: 12px; font-weight: bold; color: #3D8948; text-align: center;">{{ $row['grade'] }}</td>
            </tr>
        @endforeach
        <tr style="background: #E8F1E5; border-top: 2px solid #3D8948;">
            <td style="padding: 10px 12px; font-size: 12px; font-weight: bold; color: #142D18;">Keseluruhan</td>
            <td style="padding: 10px 12px; font-size: 12px; font-weight: bold; color: #142D18; text-align: center;">{{ $overallScore ?? '—' }} / 100</td>
            <td style="padding: 10px 12px; font-size: 14px; font-weight: bold; color: #142D18; text-align: center;">{{ $gradeFor(is_numeric($overallScore) ? (int) $overallScore : null) }}</td>
        </tr>
    </tbody>
</table>
