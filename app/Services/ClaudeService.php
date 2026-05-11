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

class ClaudeService
{
    private const DEFAULT_MODEL = 'claude-sonnet-4-6';

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
