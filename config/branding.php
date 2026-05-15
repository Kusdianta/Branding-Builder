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
            'rating_tier'        => ['cap' => 25, 'type' => 'deterministic'],
            'review_count_tier'  => ['cap' => 15, 'type' => 'deterministic'],
            'review_keyword_quality' => ['cap' => 15, 'type' => 'deterministic'],
            'sentiment_quality'  => ['cap' => 10, 'type' => 'deterministic'],
            'search_recall'      => ['cap' => 35, 'type' => 'deterministic'],
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

    /*
    | Keyword clusters for Brand Recall scanning.
    | GoogleMapsReviewsFetcher reads this — never hardcode phrases in the class.
    | Each cluster name becomes a key in keyword_hits['positive'|'negative'].
    | Phrases are substring-matched (case-insensitive) against review text.
    */
    /*
    | Substring-matched case-insensitively against each review's text.
    | Shorter root forms (e.g. "bersih") intentionally hit derivatives
    | ("kebersihan", "bersih banget") without needing word boundaries.
    */
    'recall_keyword_clusters' => [
        'positive' => [
            'cleanliness'    => [
                'harum', 'bersih', 'wangi', 'rapi', 'kinclong', 'fresh', 'segar',
                'bebas noda', 'noda hilang', 'cucian bersih', 'hasil bersih',
                'kebersihan', 'keharuman', 'kewangian', 'cemerlang',
            ],
            'speed'          => [
                'cepat', 'tepat waktu', 'tepat', 'kilat', 'ekspres', 'express',
                'same day', 'on time', 'nggak lama', 'tidak lama', 'sesuai jadwal',
                'selesai tepat', 'kecepatan', 'fast',
            ],
            'service'        => [
                'ramah', 'sopan', 'baik', 'friendly', 'senyum', 'pelayanan',
                'responsif', 'fast respon', 'fast response', 'sigap', 'membantu',
                'profesional', 'helpful',
            ],
            'recommendation' => [
                'rekomen', 'rekomendasi', 'recommend', 'puas', 'senang', 'mantap',
                'terbaik', 'terpercaya', 'worth it', 'balik lagi', 'bakal balik',
                'langganan', 'favorit', 'highly recommended', 'suka', '5 bintang',
            ],
            'quality'        => [
                'kualitas', 'berkualitas', 'memuaskan', 'tidak kecewa',
                'sesuai harapan', 'sesuai ekspektasi', 'tidak mengecewakan',
                'bagus', 'top', 'luar biasa', 'oke',
            ],
        ],
        'negative' => [
            'late'        => [
                'telat', 'lambat', 'lama banget', 'kelamaan', 'nungguin', 'molor',
                'ngaret', 'terlambat', 'delay', 'tidak tepat waktu', 'lewat jadwal',
                'ditunda',
                // BB50: English additions
                'late', 'delayed', 'overdue', 'past deadline',
                'took forever', 'way too slow', 'still not done',
            ],
            'lost'        => [
                'tertukar', 'hilang', 'baju hilang', 'pakaian hilang', 'barang hilang',
                'kehilangan',
                // BB50: English additions
                'lost', 'missing', 'missing item',
            ],
            'unresponsive' => [
                'tidak direspons', 'tidak balas', 'no respon', 'susah dihubungi',
                'ga dibalas', 'gak dibalas', 'tidak ada respon', 'slow respon',
                'slow response', 'wa tidak dibalas',
                // BB50: English additions
                'no reply', 'no response', "didn't reply", "didn't answer",
                'ignored my message', 'never got back to me',
                'no follow-up', 'unresponsive', 'ghosted',
            ],
            'damage'      => [
                'rusak', 'robek', 'cacat', 'luntur', 'pudar', 'menyusut', 'melar',
                'sobek', 'kelunturan', 'bau aneh', 'bau tidak sedap', 'bau apek',
                'masih kotor', 'tidak bersih',
                // BB50: English additions
                'damaged', 'ruined', 'destroyed', 'torn', 'shrunk',
                'discolored', 'stained', 'no compensation',
                "won't replace", "didn't replace",
            ],
        ],
    ],

    'audit_retention_days' => 30,

    'photo_limits' => [
        'max_per_group' => 3,
        'max_size_kb'   => 4096,
        'mime_allow'    => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    /*
    | Generic suffixes stripped by BrandSearchQuery::normalizeBrandStem() before
    | the brand name is sent to Google Autocomplete. Also used by
    | SearchRecallScorer to disqualify residual tokens from being treated as
    | "variants" (e.g. "less worry laundry" — "laundry" is not a variant signal).
    | Match is case-insensitive, word-boundary, longest-first.
    */
    'brand_stems' => [
        'generic_suffixes' => [
            'dry cleaning',
            'dry clean',
            'wash and wear',
            'wash & wear',
            'laundry',
            'laundromat',
            'wash',
            'washery',
            'cuci',
            'kiloan',
            'express',
            'premium',
            'professional',
            'service',
            'services',
            'kring',
            'lounge',
            'shop',
            'store',
        ],
    ],

    /*
    | Seed list of Indonesian location indicators used by LocationDetector.
    | Compounds (multi-word) are matched as substrings, longest-first, so they
    | take precedence over their unigram parts.
    | Singles are matched with word boundaries.
    | Coverage rule of thumb: Jabodetabek-and-around at the neighborhood level,
    | plus all 38 provincial capitals, plus unambiguous single-word provinces.
    | Ambiguous province stems ("jawa", "kalimantan", "sumatera", "sulawesi",
    | "nusa") are intentionally absent — only their full bigrams are included.
    |
    | Compound locations: extend as new audits surface multi-word venue names
    | (e.g. "park serpong", "gading serpong") that would otherwise tokenize
    | incorrectly and leak fragments into variant_coverage as false positives.
    */
    'location_tokens' => [
        'compounds' => [
            // Jakarta sub-neighborhoods
            'lebak bulus',
            'pondok indah',
            'pondok kelapa',
            'pondok kopi',
            'pondok labu',
            'pondok bambu',
            'blok m',
            'kebayoran baru',
            'kebayoran lama',
            'kelapa gading',
            'pulo gadung',
            'taman mini',
            'kebon jeruk',
            'duren tiga',
            'menteng dalam',
            'mangga dua',
            'tanjung priok',
            'sunter agung',
            'kuningan timur',
            'kuningan barat',
            // Jakarta cardinal districts
            'jakarta selatan',
            'jakarta utara',
            'jakarta barat',
            'jakarta timur',
            'jakarta pusat',
            // Tangerang dev clusters that show up in laundry autocomplete
            'park serpong',
            'gading serpong',
            'bsd city',
            'alam sutera',
            'bintaro jaya',
            // Provinces (full bigrams only)
            'jawa timur',
            'jawa barat',
            'jawa tengah',
            'kalimantan barat',
            'kalimantan timur',
            'kalimantan selatan',
            'kalimantan utara',
            'kalimantan tengah',
            'sumatera barat',
            'sumatera utara',
            'sumatera selatan',
            'sulawesi tenggara',
            'sulawesi tengah',
            'sulawesi utara',
            'sulawesi selatan',
            'sulawesi barat',
            'nusa tenggara timur',
            'nusa tenggara barat',
            'maluku utara',
            'papua barat',
            'papua tengah',
            'papua selatan',
            'papua pegunungan',
            'kepulauan riau',
            'kepulauan bangka belitung',
            'daerah istimewa yogyakarta',
            'dki jakarta',
        ],
        'singles' => [
            // Jakarta neighborhoods (high signal for laundry context)
            'kemang', 'tebet', 'jagakarsa', 'cilandak', 'cipete',
            'menteng', 'sudirman', 'thamrin', 'kuningan', 'senayan',
            'gading', 'pluit', 'ancol', 'kemayoran', 'cipinang',
            'matraman', 'cengkareng', 'kembangan', 'meruya', 'puri',
            'fatmawati', 'mampang', 'pancoran', 'tendean', 'casablanca',
            'rasuna', 'cawang',
            // Greater-Jakarta cities
            'bekasi', 'depok', 'tangerang', 'serpong', 'cibinong',
            'cibubur', 'cinere', 'sawangan', 'cimanggis', 'ciputat',
            // Provincial capitals
            'jakarta', 'bandung', 'semarang', 'yogyakarta', 'surabaya',
            'denpasar', 'medan', 'padang', 'pekanbaru', 'palembang',
            'jambi', 'bengkulu', 'lampung', 'pangkalpinang',
            'serang', 'mataram', 'kupang', 'pontianak',
            'palangkaraya', 'banjarmasin', 'samarinda',
            'manado', 'palu', 'makassar', 'kendari', 'gorontalo', 'mamuju',
            'ambon', 'sofifi', 'jayapura', 'manokwari',
            'sorong', 'merauke',
            // Unambiguous single-word province names
            'aceh', 'riau', 'banten', 'bali',
            // Generic administrative-unit indicators
            'kota', 'kabupaten', 'kelurahan', 'kecamatan',
        ],
    ],

];
