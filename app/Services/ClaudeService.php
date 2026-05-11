<?php

declare(strict_types=1);

namespace App\Services;

use Anthropic\Client;
use App\DTO\EvidenceItem;
use App\DTO\PillarScore;
use App\Exceptions\MalformedScoringResponseException;
use App\Exceptions\MissingAnthropicKeyException;
use App\Exceptions\UnknownPillarException;
use App\Models\ScoringRubric;
use App\Services\Scoring\DigitalPresenceScorer;
use App\Services\Scoring\RecallScorer;
use App\Services\Scoring\SearchRecallScorer;
use Nema\WorkerClient\DTO\InstagramProfileAudit;
use RuntimeException;

class ClaudeService
{
    private const DEFAULT_MODEL = 'claude-sonnet-4-6';

    /** Spec-locked: 8 image blocks max per analysis call.
     *  profile_pic (1) + screenshot (1) + first 6 post thumbnails (6) = 8. */
    private const ANALYSIS_THUMBNAIL_LIMIT = 6;

    private const ANALYSIS_MAX_TOKENS = 6144;

    private const ANALYSIS_TEMPERATURE = 0.2;

    private const ANALYSIS_RETRY_ATTEMPTS = 2;

    /** @var array<string,array<string,int|null>> Sub-bucket caps for LLM-judged pillars */
    private const LLM_BUCKET_CAPS = [
        'brand-konsistensi' => [
            'kehadiran_digital'   => 40,
            'konsistensi_visual'  => 35,
            'kelengkapan_layanan' => 15,
            'transparansi_harga'  => 10,
        ],
        'brand-experience' => [
            'base'                   => 30,
            'bonus_ekspres'          => 10,
            'bonus_antar_jemput'     => 12,
            'bonus_variasi_layanan'  => 15,
            'bonus_sop_keluhan'      => 15,
            'bonus_price_list'       => 10,
            'penalty_keterlambatan'  => 8,
            'penalty_pakaian_hilang' => 10,
            'penalty_no_response_wa' => 8,
        ],
    ];

    private const SCORING_MAX_TOKENS = 2048;

    private const KIT_MAX_TOKENS = 4096;

    private const SCORING_TEMPERATURE = 0.1;

    private const KIT_TEMPERATURE = 0.7;

    private Client $client;

    private string $model;

    public function __construct(
        private readonly SearchRecallScorer $searchRecallScorer,
    ) {
        $apiKey = (string) config('services.anthropic.key', '');
        if ($apiKey === '') {
            throw MissingAnthropicKeyException::create();
        }

        $this->client = new Client(apiKey: $apiKey);
        $this->model  = (string) config('services.anthropic.model', self::DEFAULT_MODEL);
    }

    /**
     * Score a single pillar.
     *
     * Recall and Digital Presence are deterministic — no LLM scoring call.
     * Konsistensi and Experience go through the LLM with sub-bucket prompts.
     * Recall and Digital receive an optional LLM call for evidence narrative only.
     *
     * @param  array<string,mixed>  $inputs  touchpoint data matching the rubric's input_schema
     */
    public function scorePillar(string $pillarSlug, array $inputs): PillarScore
    {
        return match ($pillarSlug) {
            ScoringRubric::PILLAR_RECALL   => $this->scoreRecall($inputs),
            ScoringRubric::PILLAR_DIGITAL  => $this->scoreDigital($inputs),
            default                         => $this->scoreLlmPillar($pillarSlug, $inputs),
        };
    }

    /**
     * Phase 7-B: structured apikprimadya-style analysis of an Instagram
     * profile audit captured by the worker.
     *
     * Sends a multi-modal prompt: textual brand context + profile data +
     * post captions + highlight names + embedded laundry-industry ER
     * benchmarks + locked output schema, PLUS 8 image blocks (profile pic,
     * full-grid screenshot, first 6 post thumbnails). Highlight covers
     * and post thumbnails 7-12 are intentionally NOT sent — they cost
     * tokens for diminishing analytical return.
     *
     * Resilience: on parse failure OR schema validation failure, retries
     * once. On second failure, returns a minimal fallback structure with
     * the raw Claude text in executive_summary and a `limitations` entry
     * flagging the schema mismatch — so callers always get a persistable
     * payload, never an exception.
     *
     * @return array<string,mixed>
     */
    public function analyzeInstagramProfile(
        InstagramProfileAudit $auditData,
        string $brandName,
        string $serviceType,
        ?string $city = null,
    ): array {
        $model         = (string) config('services.anthropic.model_analysis', $this->model);
        $userContent   = $this->buildInstagramAnalysisContent($auditData, $brandName, $serviceType, $city);
        $systemPrompt  = $this->instagramAnalysisSystemPrompt();

        $lastRaw = '';
        for ($attempt = 1; $attempt <= self::ANALYSIS_RETRY_ATTEMPTS; $attempt++) {
            try {
                $response = $this->client->messages->create(
                    maxTokens: self::ANALYSIS_MAX_TOKENS,
                    messages: [['role' => 'user', 'content' => $userContent]],
                    model: $model,
                    system: $systemPrompt,
                    temperature: self::ANALYSIS_TEMPERATURE,
                );
            } catch (\Throwable $e) {
                if ($attempt >= self::ANALYSIS_RETRY_ATTEMPTS) {
                    return $this->fallbackAnalysis(
                        'Anthropic API call failed twice: ' . $e->getMessage(),
                        '',
                    );
                }
                continue;
            }

            $raw     = $this->extractText($response);
            $lastRaw = $raw;
            $cleaned = $this->stripFences($raw);
            $decoded = json_decode($cleaned, true);

            if (! is_array($decoded)) {
                continue; // retry — parse failure (only retry trigger after W7.1 item 8)
            }

            // W7.1 item 8: normalize before validation. Unwrap nested-
            // wrapping (Claude sometimes puts everything under 'analysis'
            // or similar) + backfill any missing top-level keys with
            // shape-appropriate empty defaults and an auto-backfill note
            // appended to limitations[]. This drops the retry rate on
            // schema-key omissions to zero — only genuine parse failures
            // (caught above) trigger retry.
            $decoded = $this->normalizeAnalysisResponse($decoded);

            $missing = $this->missingAnalysisKeys($decoded);
            if ($missing !== []) {
                // Should be unreachable post-normalize; defensive guard
                // for future schema additions that miss a defaults entry.
                continue;
            }

            $decoded               = $this->computeOverallScore($decoded);
            $decoded['analyzed_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            return $decoded;
        }

        return $this->fallbackAnalysis(
            'Output failed schema validation after ' . self::ANALYSIS_RETRY_ATTEMPTS . ' attempts.',
            $lastRaw,
        );
    }

    /**
     * Build the multi-modal user content blocks: 1 text block + 8 image blocks
     * (profile pic, grid screenshot, first 6 post thumbnails). Highlight
     * covers and posts 7-12 thumbnails are dropped — sent as text references
     * only inside the text block.
     *
     * @return list<array<string,mixed>>
     */
    private function buildInstagramAnalysisContent(
        InstagramProfileAudit $audit,
        string $brandName,
        string $serviceType,
        ?string $city,
    ): array {
        $blocks   = [];
        $blocks[] = [
            'type' => 'text',
            'text' => $this->renderInstagramAnalysisText($audit, $brandName, $serviceType, $city),
        ];

        $profilePic = $audit->profile->profilePicBase64;
        if ($profilePic !== '') {
            $blocks[] = $this->base64ImageBlock($profilePic, 'image/jpeg');
        }

        if ($audit->screenshotBase64 !== '') {
            $blocks[] = $this->base64ImageBlock($audit->screenshotBase64, 'image/png');
        }

        $thumbsAttached = 0;
        foreach ($audit->recentPosts as $post) {
            if ($thumbsAttached >= self::ANALYSIS_THUMBNAIL_LIMIT) {
                break;
            }
            if ($post->thumbnailBase64 === '') {
                continue;
            }
            $blocks[] = $this->base64ImageBlock($post->thumbnailBase64, 'image/png');
            $thumbsAttached++;
        }

        return $blocks;
    }

    private function renderInstagramAnalysisText(
        InstagramProfileAudit $audit,
        string $brandName,
        string $serviceType,
        ?string $city,
    ): string {
        $p              = $audit->profile;
        $capturedAt     = $audit->capturedAt->format(\DateTimeInterface::ATOM);
        $isPrivateText  = $audit->isPrivate ? 'ya' : 'tidak';
        $isVerifiedText = $p->isVerified ? 'ya' : 'tidak';
        $isBusinessText = $p->isBusiness ? 'ya' : 'tidak';
        $externalUrl    = $p->externalUrl !== '' ? $p->externalUrl : '(tidak ada)';
        $bio            = $p->bio !== '' ? $p->bio : '(bio kosong)';
        $cityLine       = $city !== null && trim($city) !== ''
            ? "- Kota / Region: {$city}\n"
            : '';

        $postsBlock = $this->renderPostsBlock($audit->recentPosts);
        $highlightsBlock = $this->renderHighlightsBlock($audit->highlights);
        $benchmarksBlock = $this->renderBenchmarksBlock();
        $schemaBlock = $this->renderOutputSchemaBlock();

        return <<<TEXT
KONTEKS BRAND
- Nama Brand: {$brandName}
- Jenis Layanan: {$serviceType}
{$cityLine}- Username Instagram: @{$audit->username}
- Diaudit pada: {$capturedAt}
- Akun private: {$isPrivateText}

METADATA PROFIL (di-scrape dari profil IG dengan sesi terautentikasi)
- Nama profil (Name field IG): {$p->name}
- Bio: {$bio}
- Tautan eksternal (link in bio): {$externalUrl}
- Followers: {$p->followers}
- Following: {$p->following}
- Total post: {$p->postsCount}
- Akun terverifikasi: {$isVerifiedText}
- Akun bisnis/professional: {$isBusinessText}

LAMPIRAN VISUAL (terlampir sebagai gambar di pesan ini, urutan di bawah)
- [Gambar 1] Foto profil (avatar bulat di header)
- [Gambar 2] Screenshot grid IG penuh (viewport mobile 375x812)
- [Gambar 3-8] Thumbnail 6 post terbaru dari grid (urut paling baru ke lebih lama)

Highlight covers dan thumbnail post ke-7 hingga ke-12 TIDAK dilampirkan secara visual demi efisiensi token. Gunakan deskripsi tekstual di bawah untuk konteks struktural; analisis visual hanya untuk 8 gambar yang dilampirkan.

12 POST TERBARU DI GRID (dari paling baru)
{$postsBlock}

HIGHLIGHTS (cover tidak dilampirkan sebagai gambar — nama saja)
{$highlightsBlock}

BENCHMARK ENGAGEMENT RATE INDUSTRI LAUNDRY INDONESIA
Gunakan tabel ini untuk kalibrasi `estimated_er_range` dan `follower_tier` di section `engagement_analysis`. Benchmark ini SUDAH disesuaikan untuk vertikal laundry — JANGAN gunakan benchmark lifestyle/fashion umum.

{$benchmarksBlock}

INSTRUKSI ANALISIS

Lakukan audit menyeluruh dengan kedalaman setara konsultan brand strategist senior (gaya apikprimadya):
- Setiap penilaian harus terikat pada data yang TERAMATI di profil ini (bio aktual, tipe post nyata, jumlah, screenshot). Hindari klaim umum seperti "Instagram penting untuk bisnis" — itu noise, bukan insight.
- Untuk `engagement_analysis.estimated_er_range`: pilih tier berdasarkan jumlah followers, lalu pertimbangkan apakah positioning brand condong B2B/entrepreneur (turunkan 0.3 dari kedua bound) atau B2C/lifestyle (gunakan range default). Jelaskan basis pilihan di `estimation_basis`.
- Untuk `content_pillars`: identifikasi 3-5 tema/pillar konten DARI POST YANG TERLAMPIR. Jangan tebak — kalau tidak terlihat pattern, katakan demikian dan flag di `limitations`.
- Untuk `content_type_breakdown`: angka persentase (0-100), TOTAL 100 (terhadap 12 post yang diaudit, atau total post yang ter-scrape jika kurang dari 12). Contoh: `{"reels": 58, "carousel": 25, "static": 17}` berarti ~58% Reels, ~25% Carousel, ~17% Static. Bulatkan ke integer terdekat; jika total tidak persis 100 karena pembulatan, sesuaikan tipe dominan (selisih ±1 ditambahkan ke kategori terbesar).
- Untuk `priority_recommendations`: TEPAT 5 rekomendasi. Urutkan dari priority tertinggi (distribusi tipikal: tinggi/tinggi/sedang/sedang/rendah — sesuaikan ke kondisi nyata brand). Title harus action-oriented (mulai dengan kata kerja); description harus konkret (langkah/contoh), bukan generik.
- Untuk `quick_wins`: 5-7 aksi (TIDAK BOLEH KURANG DARI 5) yang bisa dieksekusi <1 minggu dengan effort rendah. Contoh: "Tambahkan call-to-action 'WA via link bio' di setiap caption reels", "Pin 3 post terbaik ke top grid". JANGAN copy dari `priority_recommendations`.
- Untuk `growth_positioning.brand_pillar_status[].status`: enum 3 nilai —
    * "ada"        : pillar dieksekusi konsisten di profil
    * "sebagian"   : pillar dieksekusi sesekali atau lemah
    * "tidak_ada"  : pillar absen sama sekali atau tidak terdeteksi di post/bio/highlight
- Untuk `scorecard`: skor integer 0-10 per dimensi; grade A/B/C/D/F sesuai rentang anchor di system prompt. Untuk `scorecard.overall`: kembalikan placeholder `{"score": 0, "grade": "F"}` sebagai value-nya. Field ini akan dihitung ulang server-side sebagai rata-rata sederhana dari 7 sub-score (round 1 desimal) dengan grade berdasarkan rentang yang sama. JANGAN coba hitung sendiri — output Anda untuk `overall` akan diabaikan.
- Untuk `limitations`: list keterbatasan analisis ini SECARA SPESIFIK. Contoh: "ER diestimasi dari benchmark industri; data like/comment per post tidak ditangkap di Phase 7-B". JANGAN umum.

CARA OUTPUT
- HANYA JSON valid. Tanpa markdown wrapper, tanpa ```json fences, tanpa kalimat penjelas di luar JSON.
- Semua string Bahasa Indonesia, register profesional konsultatif (saya/kita, BUKAN gue/lo).
- Semua skor integer 0-10. Semua grade salah satu dari "A"|"B"|"C"|"D"|"F".
- Array boleh kosong [] kalau tidak ada data; JANGAN gunakan null untuk array.
- IKUTI schema persis di bawah — JANGAN tambah field di luar schema, JANGAN hilangkan field yang ada di schema.
- JANGAN sertakan `analyzed_at` di output Anda; field itu akan diisi server.

CRITICAL OUTPUT CONSTRAINT: Output MUST contain ALL 11 top-level keys as direct siblings of the root object: executive_summary, profile_branding, content_analysis, engagement_analysis, growth_positioning, content_gaps, priority_recommendations, quick_wins, competitive_positioning, scorecard, limitations. NONE of these may be nested under another key (e.g. do NOT wrap them inside `{"analysis": {...}}` or `{"result": {...}}`). Even if a section has no data to populate, output the key with its appropriate empty value (string "" or empty array []) and add a specific limitations[] entry naming what was missing. The 11 keys are non-negotiable — output that omits any one of them will be rejected.

SCHEMA OUTPUT (wajib persis — tipe per field di sini hanya petunjuk, jangan literal disalin):

{$schemaBlock}

ANTI-POLA YANG HARUS DIHINDARI:
- Membungkus seluruh struktur di dalam satu key (mis. semua isi di dalam 'analysis' atau 'result' atau 'data')
- Menambahkan analyzed_at di output Anda (server yang mengisi)
- Menghilangkan key wajib karena data tidak ada — wajib pakai struktur kosong + limitations note (lihat Prinsip 7)
- Menambah key di luar 11 yang ditentukan
- Menyalin literal pattern `<string>` atau `<...>` dari schema sebagai nilai — schema hanya panduan tipe, bukan nilai literal
TEXT;
    }

    /** @param list<\Nema\WorkerClient\DTO\RecentPost> $posts */
    private function renderPostsBlock(array $posts): string
    {
        if ($posts === []) {
            return '(tidak ada post yang ter-scrape)';
        }
        $lines = [];
        foreach ($posts as $idx => $post) {
            $n        = $idx + 1;
            $type     = $post->type;
            $age      = $post->approximateAge ?? '(tidak diketahui)';
            $caption  = $post->caption;
            $hasThumb = $post->thumbnailBase64 !== '' && $idx < self::ANALYSIS_THUMBNAIL_LIMIT
                ? sprintf(' [thumbnail terlampir sebagai Gambar %d]', $idx + 3)
                : '';
            $captionText = $caption !== null && $caption !== ''
                ? '"' . str_replace(["\r", "\n"], [' ', ' '], $caption) . '"'
                : '(caption tidak ter-scrape)';

            $lines[] = sprintf(
                "Post #%d (%s, %s)%s\n  URL: %s\n  Caption: %s",
                $n,
                $type,
                $age,
                $hasThumb,
                $post->url,
                $captionText,
            );
        }
        return implode("\n\n", $lines);
    }

    /** @param list<\Nema\WorkerClient\DTO\Highlight> $highlights */
    private function renderHighlightsBlock(array $highlights): string
    {
        if ($highlights === []) {
            return '(tidak ada highlight di profil ini)';
        }
        $names = [];
        foreach ($highlights as $h) {
            $names[] = '- ' . ($h->name !== '' ? $h->name : '(tanpa nama)');
        }
        return implode("\n", $names);
    }

    private function renderBenchmarksBlock(): string
    {
        $config = (array) config('branding-builder.ig_benchmarks');
        $tiers  = (array) ($config['engagement_rate_by_tier'] ?? []);
        $names  = (array) ($config['tier_display_names'] ?? []);
        $adj    = (float) ($config['niche_adjustment_b2b_entrepreneur'] ?? -0.3);

        $lines = [];
        foreach ($tiers as $key => $tier) {
            $followerRange = (array) ($tier['follower_range'] ?? [0, 0]);
            $erRange       = (array) ($tier['er_range_pct'] ?? [0, 0]);
            $display       = (string) ($names[$key] ?? $key);

            $lowerFollowers = (int) ($followerRange[0] ?? 0);
            $upperFollowers = (int) ($followerRange[1] ?? 0);
            $upperLabel = $upperFollowers >= PHP_INT_MAX ? '∞' : number_format($upperFollowers);
            $rangeLabel = sprintf('%s - %s', number_format($lowerFollowers), $upperLabel);

            $lines[] = sprintf(
                '- Tier "%s" (followers %s): ER baseline %.1f%% – %.1f%%',
                $display,
                $rangeLabel,
                (float) ($erRange[0] ?? 0),
                (float) ($erRange[1] ?? 0),
            );
        }

        $lines[] = sprintf(
            "\nPenyesuaian niche: untuk brand B2B / entrepreneur-focused, kurangi %.1f%% dari kedua bound (ER cenderung lebih rendah dibanding B2C lifestyle).",
            abs($adj),
        );

        return implode("\n", $lines);
    }

    private function renderOutputSchemaBlock(): string
    {
        $schema = [
            'executive_summary' => '<string, 2-3 paragraf, sebut fakta spesifik dari profil ini>',
            'profile_branding' => [
                'bio_analysis' => [
                    'current_bio'      => '<string, bio aktual saat ini>',
                    'strengths'        => ['<string>', '...'],
                    'weaknesses'       => ['<string>', '...'],
                    'recommended_bio'  => '<string, bio yang direkomendasikan; max ~150 char karena IG cap 150>',
                ],
                'name_field_seo' => [
                    'current'     => '<string, isi Name field saat ini>',
                    'assessment'  => '<string, evaluasi SEO/keyword>',
                    'recommended' => '<string, rekomendasi Name field>',
                ],
                'highlights_assessment' => '<string, evaluasi struktur + label highlight; kalau tidak ada highlight, jelaskan impact gap-nya>',
            ],
            'content_analysis' => [
                'volume_frequency_summary' => '<string>',
                'content_type_breakdown' => [
                    'reels'    => 0,
                    'carousel' => 0,
                    'static'   => 0,
                ],
                'content_pillars' => [
                    ['name' => '<string>', 'description' => '<string>', 'examples' => ['<string>']],
                ],
                'caption_style' => '<string>',
                'visual_style'  => '<string>',
            ],
            'engagement_analysis' => [
                'follower_tier'                 => '<string, salah satu dari nano-influencer/micro-influencer/mid-tier influencer/macro-influencer>',
                'estimated_er_range'            => '<string, contoh "1.2-2.5%">',
                'estimation_basis'              => '<string, jelaskan tier dan penyesuaian niche>',
                'community_interaction_notes'   => '<string>',
            ],
            'growth_positioning' => [
                'niche_clarity_score'          => 0,
                'personal_brand_clarity_score' => 0,
                'brand_pillar_status' => [
                    ['pillar' => '<string>', 'status' => 'ada|sebagian|tidak_ada', 'gap' => '<string>'],
                ],
            ],
            'content_gaps' => [
                ['category' => '<string>', 'rationale' => '<string>', 'example_content_idea' => '<string>'],
            ],
            'priority_recommendations' => [
                [
                    'priority'    => 'tinggi|sedang|rendah',
                    'title'       => '<string, action-oriented>',
                    'description' => '<string, konkret>',
                    'effort'      => 'rendah|sedang|tinggi',
                    'impact'      => 'rendah|sedang|tinggi',
                ],
            ],
            'quick_wins'              => ['<string>', '...'],
            'competitive_positioning' => '<string>',
            'scorecard' => [
                'profile_bio_optimization'     => ['score' => 0, 'grade' => 'A|B|C|D|F'],
                'content_quality_variety'      => ['score' => 0, 'grade' => 'A|B|C|D|F'],
                'visual_consistency_aesthetics' => ['score' => 0, 'grade' => 'A|B|C|D|F'],
                'niche_clarity_positioning'    => ['score' => 0, 'grade' => 'A|B|C|D|F'],
                'engagement_strategy'          => ['score' => 0, 'grade' => 'A|B|C|D|F'],
                'personal_brand_storytelling'  => ['score' => 0, 'grade' => 'A|B|C|D|F'],
                'growth_potential'             => ['score' => 0, 'grade' => 'A|B|C|D|F'],
                'overall'                      => ['score' => 0, 'grade' => 'A|B|C|D|F'],
            ],
            'limitations' => ['<string, spesifik untuk audit ini>', '...'],
        ];

        return json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function instagramAnalysisSystemPrompt(): string
    {
        return <<<SYS
Anda adalah Senior Instagram Brand Strategist yang berspesialisasi dalam audit profil bisnis laundry di Indonesia. Pendekatan Anda mengikuti gaya apikprimadya: diagnosa berbasis bukti, spesifik, dan langsung actionable — bukan kerangka umum yang bisa ditempel ke brand mana pun.

Prinsip kerja:
1. SETIAP penilaian harus terikat pada data yang teramati di profil ini (bio aktual, jenis post yang dilampirkan, jumlah follower/post, highlight yang tersedia, visual screenshot). Klaim generik = nilai rendah.
2. Gunakan benchmark engagement rate industri LAUNDRY yang disisipkan di prompt, BUKAN benchmark lifestyle/fashion/wellness umum dari pengetahuan latar Anda.
3. Register bahasa: profesional konsultatif Indonesia. Gunakan "saya"/"kita"; HINDARI "gue"/"lo".
4. Disiplin output: HANYA JSON valid yang match schema persis. TANPA markdown wrapper, TANPA ```json fences, TANPA komentar atau kalimat penjelas di luar JSON. JSON harus parse-able oleh json_decode() PHP.
5. Semua skor integer 0-10. Anchor (kalibrasi terhadap pasar laundry Indonesia, BUKAN brand global):
   - 9-10 (A): best-in-class untuk vertikal laundry Indonesia; jarang. Contoh: bio yang langsung mengonversi (CTA + USP + lokasi jelas), content pillar yang sangat distinct dengan eksekusi konsisten 6+ bulan, visual identity setingkat brand premium nasional.
   - 7-8 (B): solid execution dengan minor gap. Mayoritas elemen sudah terjalankan, satu-dua area masih bisa di-polish.
   - 5-6 (C): average / industry baseline. Cukup untuk operasional dasar tapi tidak menonjol di antara kompetitor sekota.
   - 3-4 (D): significant gap. Beberapa elemen kunci absen atau salah eksekusi (mis. bio tanpa CTA, content random tanpa pillar yang jelas).
   - 0-2 (F): absent atau detrimental terhadap brand (mis. profil kosong/inactive, atau konten yang justru merusak persepsi).
6. JANGAN tambah field di luar schema. JANGAN hilangkan field yang ada di schema. Untuk array yang tidak ada datanya, gunakan [], BUKAN null.
7. Penanganan data tidak tersedia: bila field tidak dapat dievaluasi karena data tidak ter-scrape (mis. bio kosong, tidak ada highlights, semua caption "(caption tidak ter-scrape)"), kembalikan nilai kosong yang sesuai tipe (string `""` atau array `[]`) DAN tambahkan entry spesifik di `limitations` yang menyebutkan apa yang hilang. JANGAN buat-buat data. JANGAN tulis "tidak tersedia" sebagai value — gunakan struktur kosong + limitation note.

Anda akan menerima: konteks brand + metadata profil + 12 post terbaru (caption + tipe + umur) + nama highlights + tabel benchmark industri + schema output + 8 gambar (foto profil + screenshot grid + 6 thumbnail post terbaru). Analisis penuh, kembalikan SATU objek JSON tunggal.
SYS;
    }

    /**
     * Recompute scorecard.overall as simple average of the 7 sub-scores,
     * rounded to 1 decimal. Per apikprimadya convention — defensible to
     * clients and avoids LLM arithmetic errors. Claude is instructed to
     * return placeholder {score:0,grade:'F'} for overall; we overwrite it.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function computeOverallScore(array $payload): array
    {
        $subKeys = [
            'profile_bio_optimization',
            'content_quality_variety',
            'visual_consistency_aesthetics',
            'niche_clarity_positioning',
            'engagement_strategy',
            'personal_brand_storytelling',
            'growth_potential',
        ];

        $scorecard = is_array($payload['scorecard'] ?? null) ? $payload['scorecard'] : [];
        $scores    = [];
        foreach ($subKeys as $key) {
            $bucket = $scorecard[$key] ?? null;
            if (is_array($bucket) && isset($bucket['score']) && is_numeric($bucket['score'])) {
                $scores[] = (float) $bucket['score'];
            }
        }

        if ($scores === []) {
            $scorecard['overall'] = ['score' => 0, 'grade' => 'F'];
        } else {
            $avg                  = round(array_sum($scores) / count($scores), 1);
            $scorecard['overall'] = [
                'score' => $avg,
                'grade' => $this->gradeForScore($avg),
            ];
        }

        $payload['scorecard'] = $scorecard;
        return $payload;
    }

    private function gradeForScore(float $score): string
    {
        return match (true) {
            $score >= 9.0 => 'A',
            $score >= 7.0 => 'B',
            $score >= 5.0 => 'C',
            $score >= 3.0 => 'D',
            default       => 'F',
        };
    }

    /** @param array<string,mixed> $payload */
    private function missingAnalysisKeys(array $payload): array
    {
        $required = [
            'executive_summary',
            'profile_branding',
            'content_analysis',
            'engagement_analysis',
            'growth_positioning',
            'content_gaps',
            'priority_recommendations',
            'quick_wins',
            'competitive_positioning',
            'scorecard',
            'limitations',
        ];
        $missing = [];
        foreach ($required as $key) {
            if (! array_key_exists($key, $payload)) {
                $missing[] = $key;
            }
        }

        // Minimum scorecard shape — overall is required for any UI later.
        if (
            isset($payload['scorecard'])
            && is_array($payload['scorecard'])
            && ! isset($payload['scorecard']['overall'])
        ) {
            $missing[] = 'scorecard.overall';
        }

        return $missing;
    }

    /**
     * Shape-appropriate empty defaults for each top-level analysis key.
     * Single source of truth for the 11-key schema — referenced by both
     * the normalizer (W7.1 item 8, fills in missing keys) and the
     * terminal fallback (when both LLM attempts fail).
     *
     * Keep in sync with renderOutputSchemaBlock() above. Adding a new
     * top-level analysis key requires updating: the schema block, this
     * defaults map, and missingAnalysisKeys()'s required-list.
     *
     * @return array<string,mixed>
     */
    private function emptyAnalysisDefaults(): array
    {
        return [
            'executive_summary' => '',
            'profile_branding' => [
                'bio_analysis'          => ['current_bio' => '', 'strengths' => [], 'weaknesses' => [], 'recommended_bio' => ''],
                'name_field_seo'        => ['current' => '', 'assessment' => '', 'recommended' => ''],
                'highlights_assessment' => '',
            ],
            'content_analysis' => [
                'volume_frequency_summary' => '',
                'content_type_breakdown'   => ['reels' => 0, 'carousel' => 0, 'static' => 0],
                'content_pillars'          => [],
                'caption_style'            => '',
                'visual_style'             => '',
            ],
            'engagement_analysis' => [
                'follower_tier'                => '',
                'estimated_er_range'           => '',
                'estimation_basis'             => '',
                'community_interaction_notes'  => '',
            ],
            'growth_positioning' => [
                'niche_clarity_score'          => 0,
                'personal_brand_clarity_score' => 0,
                'brand_pillar_status'          => [],
            ],
            'content_gaps'             => [],
            'priority_recommendations' => [],
            'quick_wins'               => [],
            'competitive_positioning'  => '',
            'scorecard' => [
                'profile_bio_optimization'      => ['score' => 0, 'grade' => 'F'],
                'content_quality_variety'       => ['score' => 0, 'grade' => 'F'],
                'visual_consistency_aesthetics' => ['score' => 0, 'grade' => 'F'],
                'niche_clarity_positioning'     => ['score' => 0, 'grade' => 'F'],
                'engagement_strategy'           => ['score' => 0, 'grade' => 'F'],
                'personal_brand_storytelling'   => ['score' => 0, 'grade' => 'F'],
                'growth_potential'              => ['score' => 0, 'grade' => 'F'],
                'overall'                       => ['score' => 0, 'grade' => 'F'],
            ],
            'limitations' => [],
        ];
    }

    /**
     * W7.1 item 8: normalize Claude's response before schema validation.
     *
     * Two recovery passes are layered:
     *
     * 1. **Unwrap nested wrapping.** Claude sometimes returns the entire
     *    analysis structure wrapped under a single root key (commonly
     *    "analysis" / "result" / "data"). :func:`maybeUnwrapNestedAnalysis`
     *    detects this when 5+ of the 11 expected top-level keys appear at
     *    the inner level and lifts the inner object to the root.
     *
     * 2. **Backfill missing top-level keys.** For each of the 11 required
     *    keys absent from the (post-unwrap) payload, fill in the shape-
     *    appropriate empty value from :func:`emptyAnalysisDefaults` and
     *    append a specific entry to ``limitations[]`` so the caller can
     *    surface "auto-backfilled" diagnostics to the audit operator.
     *
     * After this normalizer, ``missingAnalysisKeys()`` returns ``[]`` on
     * any input that successfully passed ``json_decode``. The retry path
     * in :func:`analyzeInstagramProfile` is therefore reserved for genuine
     * parse failures, not schema-key omissions.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function normalizeAnalysisResponse(array $payload): array
    {
        $payload = $this->maybeUnwrapNestedAnalysis($payload);

        $defaults       = $this->emptyAnalysisDefaults();
        $autoBackfilled = [];

        foreach ($defaults as $key => $defaultValue) {
            if (! array_key_exists($key, $payload)) {
                $payload[$key]    = $defaultValue;
                $autoBackfilled[] = $key;
            }
        }

        // Defensive: scorecard.overall is a UI-required leaf; if Claude
        // omitted it from an otherwise-present scorecard, ensure the
        // placeholder is present so the server-side recompute has a
        // target slot to overwrite.
        if (
            is_array($payload['scorecard'] ?? null)
            && ! isset($payload['scorecard']['overall'])
        ) {
            $payload['scorecard']['overall'] = ['score' => 0, 'grade' => 'F'];
        }

        if ($autoBackfilled !== []) {
            $limitations = is_array($payload['limitations'] ?? null) ? $payload['limitations'] : [];
            foreach ($autoBackfilled as $key) {
                $limitations[] = sprintf(
                    "Auto-backfilled missing top-level key '%s' — Claude output omitted this section.",
                    $key,
                );
            }
            $payload['limitations'] = $limitations;
        }

        return $payload;
    }

    /**
     * Detect the "single root key wrapping the analysis structure"
     * deviation (e.g. ``{"analysis": {...}}`` or ``{"result": {...}}``)
     * and lift the inner object to the root.
     *
     * The match threshold is intentionally 5 of the 11 expected keys —
     * lower would risk false-positive unwrapping of a legitimate nested
     * shape (e.g. a payload that only happens to have one top-level key
     * matching "executive_summary"), higher would miss the case where
     * Claude wrapped the structure but omitted some inner keys itself
     * (the backfill pass below handles those after unwrap).
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function maybeUnwrapNestedAnalysis(array $payload): array
    {
        if (count($payload) !== 1) {
            return $payload;
        }

        $inner = reset($payload);
        if (! is_array($inner)) {
            return $payload;
        }

        $expectedKeys = [
            'executive_summary',
            'profile_branding',
            'content_analysis',
            'engagement_analysis',
            'growth_positioning',
            'content_gaps',
            'priority_recommendations',
            'quick_wins',
            'competitive_positioning',
            'scorecard',
            'limitations',
        ];

        $matchCount = 0;
        foreach ($expectedKeys as $key) {
            if (array_key_exists($key, $inner)) {
                $matchCount++;
            }
        }

        return $matchCount >= 5 ? $inner : $payload;
    }

    /** @return array<string,mixed> */
    private function fallbackAnalysis(string $reason, string $rawText): array
    {
        $payload = $this->emptyAnalysisDefaults();
        $payload['executive_summary'] = $rawText !== ''
            ? $rawText
            : 'Analisis IG belum dapat dijalankan karena ' . $reason;
        $payload['limitations']       = [$reason];
        $payload['analyzed_at']       = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        return $payload;
    }

    /** @return array<string,mixed> */
    private function base64ImageBlock(string $base64, string $mediaType): array
    {
        return [
            'type'   => 'image',
            'source' => [
                'type'       => 'base64',
                'media_type' => $mediaType,
                'data'       => $base64,
            ],
        ];
    }

    /**
     * Generate the activation kit JSON from audit results.
     *
     * @param  array<string,mixed>  $auditData
     * @return array<string,mixed>
     */
    public function generateActivationKit(array $auditData): array
    {
        $prompt = $this->buildKitPrompt($auditData);

        $response = $this->client->messages->create(
            maxTokens: self::KIT_MAX_TOKENS,
            messages: [
                ['role' => 'user', 'content' => $prompt],
            ],
            model: $this->model,
            temperature: self::KIT_TEMPERATURE,
        );

        $raw     = $this->extractText($response);
        $cleaned = $this->stripFences($raw);
        $decoded = json_decode($cleaned, true);

        if (! is_array($decoded)) {
            throw new MalformedScoringResponseException(
                'Activation kit response was not valid JSON: '.json_last_error_msg(),
            );
        }

        return $decoded;
    }

    // -------------------------------------------------------------------------
    // Deterministic pillars (score via math, narrative via LLM)
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $inputs */
    private function scoreRecall(array $inputs): PillarScore
    {
        // Review-based sub-buckets (caps 25 + 15 + 15 + 10 = 65)
        $base = (new RecallScorer())->score($inputs);

        // Autocomplete-based search_recall sub-bucket (cap 35) layered on top.
        $brandName    = (string) ($inputs['brand_name'] ?? '');
        $searchResult = $this->searchRecallScorer->score($brandName);

        $subBuckets                  = $base->subBucketScores;
        $subBuckets['search_recall'] = $searchResult['score'];

        $breakdown                  = $base->scoreBreakdown;
        $breakdown['search_recall'] = $searchResult['breakdown'];

        $totalScore = max(0, min(100, array_sum($subBuckets)));

        $narrative = $this->fetchNarrative(ScoringRubric::PILLAR_RECALL, $inputs, $subBuckets);

        return new PillarScore(
            pillarSlug:      $base->pillarSlug,
            score:           $totalScore,
            evidence:        $narrative['evidence'],
            reasoning:       $narrative['reasoning'],
            subBucketScores: $subBuckets,
            scoreBreakdown:  $breakdown,
        );
    }

    /** @param array<string,mixed> $inputs */
    private function scoreDigital(array $inputs): PillarScore
    {
        $base      = (new DigitalPresenceScorer())->score($inputs);
        $narrative = $this->fetchNarrative(ScoringRubric::PILLAR_DIGITAL, $inputs, $base->subBucketScores);

        return new PillarScore(
            pillarSlug:      $base->pillarSlug,
            score:           $base->score,
            evidence:        $narrative['evidence'],
            reasoning:       $narrative['reasoning'],
            subBucketScores: $base->subBucketScores,
            scoreBreakdown:  $base->scoreBreakdown,
        );
    }

    /**
     * Call the evidence-narrative rubric for a deterministic pillar.
     *
     * @param  array<string,mixed>  $inputs
     * @param  array<string,mixed>  $subBucketScores
     * @return array{evidence:list<EvidenceItem>,reasoning:string}
     */
    private function fetchNarrative(string $pillarSlug, array $inputs, array $subBucketScores): array
    {
        $rubric = ScoringRubric::query()
            ->forPillar($pillarSlug)
            ->active()
            ->orderByDesc('version')
            ->first();

        if ($rubric === null) {
            return ['evidence' => [], 'reasoning' => ''];
        }

        $inputsWithBuckets = array_merge($inputs, ['sub_bucket_scores' => $subBucketScores]);
        $prompt            = $this->renderInputsAsText($pillarSlug, $inputsWithBuckets);

        $response = $this->client->messages->create(
            maxTokens: self::SCORING_MAX_TOKENS,
            messages: [['role' => 'user', 'content' => $prompt]],
            model: $this->model,
            system: $rubric->system_prompt,
            temperature: self::SCORING_TEMPERATURE,
        );

        $raw  = $this->extractText($response);
        return $this->parseNarrativeJson($pillarSlug, $raw);
    }

    // -------------------------------------------------------------------------
    // LLM pillars (Konsistensi, Experience)
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $inputs */
    private function scoreLlmPillar(string $pillarSlug, array $inputs): PillarScore
    {
        $rubric = ScoringRubric::query()
            ->forPillar($pillarSlug)
            ->active()
            ->orderByDesc('version')
            ->first();

        if ($rubric === null) {
            throw UnknownPillarException::forSlug($pillarSlug);
        }

        $userContent = $this->buildUserContent($pillarSlug, $inputs);

        $response = $this->client->messages->create(
            maxTokens: self::SCORING_MAX_TOKENS,
            messages: [
                ['role' => 'user', 'content' => $userContent],
            ],
            model: $this->model,
            system: $rubric->system_prompt,
            temperature: self::SCORING_TEMPERATURE,
        );

        $raw    = $this->extractText($response);
        $parsed = $this->parseLlmPillarJson($pillarSlug, $raw);

        return $this->hydrateLlmScore($pillarSlug, $parsed, $inputs);
    }

    // -------------------------------------------------------------------------
    // Content builders
    // -------------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $inputs
     * @return list<array<string,mixed>>
     */
    private function buildUserContent(string $pillarSlug, array $inputs): array
    {
        $blocks   = [];
        $blocks[] = ['type' => 'text', 'text' => $this->renderInputsAsText($pillarSlug, $inputs)];

        $photoPaths = $inputs['outlet_photo_paths'] ?? [];
        if (is_array($photoPaths) && $pillarSlug === ScoringRubric::PILLAR_KONSISTENSI) {
            foreach ($photoPaths as $path) {
                $block = $this->buildImageBlock((string) $path);
                if ($block !== null) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }

    /** @param array<string,mixed> $inputs */
    private function renderInputsAsText(string $pillarSlug, array $inputs): string
    {
        $lines = ['Data touchpoint untuk audit pilar '.$pillarSlug.':', ''];

        foreach ($inputs as $key => $value) {
            if ($key === 'outlet_photo_paths') {
                $count   = is_array($value) ? count($value) : 0;
                $lines[] = sprintf('- outlet_photo_count: %d (terlampir sebagai gambar di bawah)', $count);
                continue;
            }

            if (is_array($value) || is_object($value)) {
                $lines[] = sprintf('- %s: %s', $key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                continue;
            }

            if ($value === null || $value === '') {
                $lines[] = sprintf('- %s: (tidak tersedia)', $key);
                continue;
            }

            $lines[] = sprintf('- %s: %s', $key, (string) $value);
        }

        $lines[] = '';
        $lines[] = 'Berikan skor sesuai rubrik dan kembalikan dalam format JSON yang sudah ditentukan.';

        return implode("\n", $lines);
    }

    /** @return array<string,mixed>|null */
    private function buildImageBlock(string $path): ?array
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $mime = $this->detectMime($path);
        if ($mime === null) {
            return null;
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        return [
            'type'   => 'image',
            'source' => [
                'type'       => 'base64',
                'media_type' => $mime,
                'data'       => base64_encode($bytes),
            ],
        ];
    }

    private function detectMime(string $path): ?string
    {
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && in_array($mime, $allowed, true)) {
                    return $mime;
                }
            }
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            default       => null,
        };
    }

    // -------------------------------------------------------------------------
    // Response parsing
    // -------------------------------------------------------------------------

    private function extractText(mixed $response): string
    {
        $content = $response->content ?? [];
        if (! is_array($content) && ! is_iterable($content)) {
            return '';
        }

        foreach ($content as $block) {
            $text = $block->text ?? null;
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * Parse LLM response for Konsistensi / Experience (includes sub_bucket_scores).
     *
     * @return array{score:int,sub_bucket_scores:array<string,mixed>,evidence:list<array<string,mixed>>,reasoning:string}
     */
    private function parseLlmPillarJson(string $pillarSlug, string $raw): array
    {
        if ($raw === '') {
            throw MalformedScoringResponseException::fromReason($pillarSlug, 'response was empty');
        }

        $cleaned = $this->stripFences($raw);
        $decoded = json_decode($cleaned, true);

        if (! is_array($decoded)) {
            throw MalformedScoringResponseException::fromReason(
                $pillarSlug,
                'invalid JSON: '.json_last_error_msg(),
            );
        }

        if (! array_key_exists('score', $decoded) || ! is_numeric($decoded['score'])) {
            throw MalformedScoringResponseException::fromReason($pillarSlug, 'missing or non-numeric score');
        }

        if (! isset($decoded['evidence']) || ! is_array($decoded['evidence'])) {
            throw MalformedScoringResponseException::fromReason($pillarSlug, 'missing evidence array');
        }

        if (! isset($decoded['reasoning']) || ! is_string($decoded['reasoning'])) {
            $decoded['reasoning'] = '';
        }

        return [
            'score'             => (int) $decoded['score'],
            'sub_bucket_scores' => is_array($decoded['sub_bucket_scores'] ?? null) ? $decoded['sub_bucket_scores'] : [],
            'evidence'          => array_values($decoded['evidence']),
            'reasoning'         => (string) $decoded['reasoning'],
        ];
    }

    /**
     * Parse LLM response for Recall / Digital (evidence + reasoning only).
     *
     * @return array{evidence:list<EvidenceItem>,reasoning:string}
     */
    private function parseNarrativeJson(string $pillarSlug, string $raw): array
    {
        if ($raw === '') {
            return ['evidence' => [], 'reasoning' => ''];
        }

        $cleaned = $this->stripFences($raw);
        $decoded = json_decode($cleaned, true);

        if (! is_array($decoded)) {
            return ['evidence' => [], 'reasoning' => ''];
        }

        $evidence = [];
        foreach ((array) ($decoded['evidence'] ?? []) as $row) {
            if (is_array($row)) {
                $evidence[] = EvidenceItem::fromArray($row);
            }
        }

        return [
            'evidence'  => $evidence,
            'reasoning' => (string) ($decoded['reasoning'] ?? ''),
        ];
    }

    /**
     * @param array{score:int,sub_bucket_scores:array<string,mixed>,evidence:list<array<string,mixed>>,reasoning:string} $parsed
     * @param array<string,mixed> $inputs
     */
    private function hydrateLlmScore(string $pillarSlug, array $parsed, array $inputs): PillarScore
    {
        $score    = max(0, min(100, $parsed['score']));
        $evidence = [];
        foreach ($parsed['evidence'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $evidence[] = EvidenceItem::fromArray($row);
        }

        $breakdown = $this->buildLlmBreakdown($pillarSlug, $inputs, $parsed['sub_bucket_scores'], $parsed['reasoning']);

        return new PillarScore(
            pillarSlug:      $pillarSlug,
            score:           $score,
            evidence:        $evidence,
            reasoning:       $parsed['reasoning'],
            subBucketScores: $parsed['sub_bucket_scores'],
            scoreBreakdown:  $breakdown,
        );
    }

    /**
     * Build per-sub-bucket breakdown for LLM-judged pillars.
     *
     * @param  array<string,mixed>  $inputs
     * @param  array<string,mixed>  $subBucketScores
     * @return array<string,array<string,mixed>>
     */
    private function buildLlmBreakdown(string $pillarSlug, array $inputs, array $subBucketScores, string $reasoning): array
    {
        $caps        = self::LLM_BUCKET_CAPS[$pillarSlug] ?? [];
        $limitations = $this->inferLimitations($inputs);
        $context     = $this->buildContextList($inputs);

        $breakdown = [];
        foreach ($subBucketScores as $bucketKey => $score) {
            $breakdown[$bucketKey] = [
                'score'          => is_numeric($score) ? (int) $score : 0,
                'cap'            => $caps[$bucketKey] ?? null,
                'raw_inputs'     => ['context_provided' => $context],
                'formula'        => 'llm_judgment',
                'llm_reasoning'  => $reasoning,
                'limitations'    => $limitations,
                'explanation_id' => $bucketKey . '_llm_v1',
            ];
        }

        return $breakdown;
    }

    /** @return list<string> */
    private function inferLimitations(array $inputs): array
    {
        $limitations = [];

        if (! empty($inputs['instagram_url'])) {
            $limitations[] = 'Instagram content not fetched in v0 — judgment based on URL presence only';
        }
        if (! empty($inputs['tiktok_url'])) {
            $limitations[] = 'TikTok content not fetched in v0 — judgment based on URL presence only';
        }
        if (array_key_exists('outlet_photo_paths', $inputs) && empty($inputs['outlet_photo_paths'])) {
            $limitations[] = 'No outlet photos provided';
        }
        if (! empty($inputs['website_url']) && empty($inputs['website_excerpt'])) {
            $limitations[] = 'Website content not fully extracted in v0';
        }

        return $limitations;
    }

    /** @return list<string> */
    private function buildContextList(array $inputs): array
    {
        $context = [];
        foreach ($inputs as $key => $value) {
            if ($key === 'brand_name') {
                continue;
            }
            if ($key === 'outlet_photo_paths') {
                $context[] = 'outlet_photos_count: ' . (is_array($value) ? count($value) : 0);
            } elseif (is_bool($value)) {
                $context[] = $key . ': ' . ($value ? 'yes' : 'no');
            } elseif (is_string($value) && $value !== '') {
                $context[] = $key . ': yes';
            } elseif (is_string($value)) {
                $context[] = $key . ': (tidak tersedia)';
            } elseif (is_array($value)) {
                $context[] = $key . ': ' . (count($value) > 0 ? 'present' : 'empty');
            }
        }
        return $context;
    }

    private function stripFences(string $raw): string
    {
        $trimmed = trim($raw);
        $trimmed = (string) preg_replace('/^```(?:json)?\s*/i', '', $trimmed);
        $trimmed = (string) preg_replace('/\s*```$/', '', $trimmed);

        return trim($trimmed);
    }

    // -------------------------------------------------------------------------
    // Activation kit
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $audit */
    private function buildKitPrompt(array $audit): string
    {
        $brand    = (string) ($audit['brand_name'] ?? '');
        $city     = (string) ($audit['city'] ?? 'Tidak disebutkan');
        $service  = (string) ($audit['service_type'] ?? '');
        $overall  = (int) ($audit['overall_score'] ?? 0);
        $label    = (string) ($audit['overall_label'] ?? '');
        $pillars  = json_encode($audit['pillar_scores'] ?? [], JSON_UNESCAPED_UNICODE);
        $findings = json_encode($audit['key_findings'] ?? [], JSON_UNESCAPED_UNICODE);
        $recs     = json_encode($audit['recommendations'] ?? [], JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
Kamu adalah brand strategist & copywriter untuk bisnis laundry di Indonesia. Susun "Social Media Brand Activation Kit" untuk klien Chimera Creative berdasarkan hasil audit di bawah ini.

HASIL AUDIT:
- Nama Brand: {$brand}
- Kota: {$city}
- Jenis Layanan: {$service}
- Overall Score: {$overall}/100 ({$label})
- Pillar Scores (JSON): {$pillars}
- Key Findings (JSON): {$findings}
- Rekomendasi Prioritas (JSON): {$recs}

Output HANYA JSON valid berikut, tanpa markdown, tanpa penjelasan:

{
  "brand_narrative": {
    "tagline": "satu kalimat tagline yang sejalan dengan temuan audit",
    "big_narrative_title": "judul naratif besar (3-5 kata)",
    "big_narrative_body": "2-3 paragraf naratif brand yang membahas mengapa brand ini ada, apa yang mereka percaya, dan bagaimana mereka berbeda berdasarkan temuan audit"
  },
  "narrative_pillars": {
    "problem_layer": {
      "world_title": "judul kondisi dunia target pelanggan (3-5 kata)",
      "problems": ["masalah 1", "masalah 2", "masalah 3", "masalah 4", "masalah 5"],
      "content_tone": "3 kata sifat untuk tone konten pillar ini"
    },
    "belief_layer": {
      "mindset_title": "judul mindset shift (3-5 kata)",
      "old_belief": "kepercayaan lama yang salah",
      "new_belief": "kepercayaan baru yang brand tawarkan",
      "key_message": "kalimat kunci satu baris"
    },
    "action_layer": {
      "ritual_title": "nama ritual / aksi (3-5 kata)",
      "trigger_moments": ["momen 1", "momen 2", "momen 3"],
      "ritual_steps": ["langkah 1", "langkah 2", "langkah 3"],
      "cta": "call to action satu kalimat"
    }
  },
  "content_story_mapping": {
    "macro_story": {
      "umbrella_narrative": "satu kalimat besar payung konten",
      "brand_beliefs": ["belief 1", "belief 2", "belief 3"],
      "brand_stands_against": ["anti 1", "anti 2", "anti 3"]
    },
    "micro_story": {
      "chapter_1": {"title":"...","theme":"...","content_ideas":["...","...","..."],"message":"..."},
      "chapter_2": {"title":"...","theme":"...","content_ideas":["...","...","..."],"message":"..."},
      "chapter_3": {"title":"...","theme":"...","content_ideas":["...","...","..."],"message":"..."}
    }
  },
  "brand_voice": {
    "tone_description": "deskripsi 2 kalimat",
    "personality_words": ["kata 1","kata 2","kata 3","kata 4"],
    "dos": ["lakukan 1","lakukan 2","lakukan 3","lakukan 4"],
    "donts": ["jangan 1","jangan 2","jangan 3","jangan 4"]
  },
  "content_pillars": [
    {"name":"Pilar 1","description":"deskripsi","example_hook":"hook caption"},
    {"name":"Pilar 2","description":"deskripsi","example_hook":"hook caption"},
    {"name":"Pilar 3","description":"deskripsi","example_hook":"hook caption"},
    {"name":"Pilar 4","description":"deskripsi","example_hook":"hook caption"},
    {"name":"Pilar 5","description":"deskripsi","example_hook":"hook caption"}
  ],
  "caption_examples": [
    {"type":"Soft Selling","caption":"caption 3-5 baris"},
    {"type":"Edukasi","caption":"caption 3-5 baris"},
    {"type":"Behind the Scenes","caption":"caption 3-5 baris"}
  ]
}

Gunakan register saya/kita. Jangan pakai gue/lo. Return ONLY JSON.
PROMPT;
    }
}
