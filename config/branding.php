<?php

declare(strict_types=1);

use App\Models\ScoringRubric;

return [

    'pillar_weights' => [
        ScoringRubric::PILLAR_KONSISTENSI => 0.35,
        ScoringRubric::PILLAR_RECALL      => 0.35,
        ScoringRubric::PILLAR_EXPERIENCE  => 0.20,
        ScoringRubric::PILLAR_DIGITAL     => 0.10,
    ],

    'pillar_labels' => [
        ScoringRubric::PILLAR_KONSISTENSI => 'Brand Konsistensi',
        ScoringRubric::PILLAR_RECALL      => 'Brand Recall',
        ScoringRubric::PILLAR_EXPERIENCE  => 'Brand Experience',
        ScoringRubric::PILLAR_DIGITAL     => 'Digital Presence',
    ],

    /*
    | Inclusive lower bounds — score >= bound maps to label.
    | Evaluated top-down; first match wins.
    */
    'label_thresholds' => [
        85 => 'EXCELLENT — Brand Kuat & Terpercaya',
        70 => 'GOOD — Potensi Tinggi',
        55 => 'AVERAGE — Perlu Perbaikan Sistematis',
        35 => 'BELOW AVG — Risiko Kehilangan Pelanggan',
         0 => 'CRITICAL — Brand Belum Terbangun',
    ],

    /*
    | Per-pillar sub-bucket definitions.
    | 'cap'  = maximum points this bucket contributes.
    | 'type' = llm | deterministic | base | bonus | penalty
    */
    'pillar_sub_buckets' => [
        ScoringRubric::PILLAR_KONSISTENSI => [
            'kehadiran_digital'   => ['cap' => 40, 'type' => 'llm'],
            'konsistensi_visual'  => ['cap' => 35, 'type' => 'llm'],
            'kelengkapan_layanan' => ['cap' => 15, 'type' => 'llm'],
            'transparansi_harga'  => ['cap' => 10, 'type' => 'llm'],
        ],
        ScoringRubric::PILLAR_RECALL => [
            'rating'            => ['cap' => 35, 'type' => 'deterministic'],
            'review_count'      => ['cap' => 25, 'type' => 'deterministic'],
            'keyword_quality'   => ['cap' => 20, 'type' => 'deterministic'],
            'review_management' => ['cap' => 20, 'type' => 'deterministic'],
        ],
        ScoringRubric::PILLAR_EXPERIENCE => [
            'base'                   => ['cap' => 30, 'type' => 'base'],
            'bonus_ekspres'          => ['cap' => 10, 'type' => 'bonus'],
            'bonus_antar_jemput'     => ['cap' => 12, 'type' => 'bonus'],
            'bonus_variasi_layanan'  => ['cap' => 15, 'type' => 'bonus'],
            'bonus_sop_keluhan'      => ['cap' => 15, 'type' => 'bonus'],
            'bonus_price_list'       => ['cap' => 10, 'type' => 'bonus'],
            'penalty_keterlambatan'  => ['cap' => 8,  'type' => 'penalty'],
            'penalty_pakaian_hilang' => ['cap' => 10, 'type' => 'penalty'],
            'penalty_no_response_wa' => ['cap' => 8,  'type' => 'penalty'],
        ],
        ScoringRubric::PILLAR_DIGITAL => [
            'has_gmaps'     => ['cap' => 25, 'type' => 'deterministic'],
            'has_instagram' => ['cap' => 20, 'type' => 'deterministic'],
            'has_website'   => ['cap' => 20, 'type' => 'deterministic'],
            'has_wa'        => ['cap' => 15, 'type' => 'deterministic'],
            'has_tiktok'    => ['cap' => 10, 'type' => 'deterministic'],
            'review_bonus'  => ['cap' => 15, 'type' => 'deterministic'],
        ],
    ],

    'audit_retention_days' => 30,

    'photo_limits' => [
        'max_per_group' => 3,
        'max_size_kb'   => 4096,
        'mime_allow'    => ['image/jpeg', 'image/png', 'image/webp'],
    ],

];
