<?php

namespace App\Services;

use Anthropic\Client;
use Exception;

class ClaudeService
{
    private Client $client;

    public function __construct()
    {
        $apiKey = config('services.anthropic.key');

        if (empty($apiKey)) {
            throw new Exception('Anthropic API key belum diset. Tambahkan ANTHROPIC_API_KEY di file .env kamu.');
        }

        $this->client = new Client(apiKey: $apiKey);
    }

    public function generateBrandKit(array $data): array
    {
        $prompt = $this->buildPrompt($data);

        $response = $this->client->messages->create(
            model: 'claude-sonnet-4-6',
            maxTokens: 4096,
            messages: [
                ['role' => 'user', 'content' => $prompt],
            ],
        );

        $content = $response->content[0]->text ?? '';

        return $this->parseResponse($content);
    }

    private function buildPrompt(array $d): string
    {
        $competitors = !empty($d['competitors']) ? $d['competitors'] : 'Tidak disebutkan';

        return <<<PROMPT
Kamu adalah brand strategist dan copywriter ahli untuk bisnis laundry di Indonesia. Tugasmu adalah membuat "Social Media Brand Activation Kit" untuk klien Chimera Creative berdasarkan data bisnis di bawah.

DATA BISNIS:
- Nama Bisnis: {$d['business_name']}
- Lokasi / Kota: {$d['location']}
- Jenis Layanan: {$d['service_type']}
- Target Pelanggan: {$d['target_customer']}
- Keunggulan Utama: {$d['differentiator']}
- Kepribadian Brand: {$d['brand_personality']}
- Segmen Harga: {$d['price_segment']}
- Kompetitor: {$competitors}

Hasilkan output HANYA dalam format JSON valid berikut (tanpa markdown, tanpa penjelasan, langsung JSON):

{
  "brand_narrative": {
    "tagline": "Satu kalimat tagline kuat yang menggambarkan brand ini",
    "big_narrative_title": "Judul naratif besar (3-5 kata, impactful)",
    "big_narrative_body": "2-3 paragraf narasi brand yang menggambarkan mengapa bisnis ini ada, apa yang mereka percaya, dan bagaimana mereka berbeda. Gaya penulisan: kuat, human, tidak korporat."
  },
  "narrative_pillars": {
    "problem_layer": {
      "world_title": "Judul kondisi dunia target pelanggan (3-5 kata)",
      "problems": ["masalah 1", "masalah 2", "masalah 3", "masalah 4", "masalah 5"],
      "content_tone": "3 kata sifat yang menggambarkan tone konten untuk pillar ini"
    },
    "belief_layer": {
      "mindset_title": "Judul mindset shift (3-5 kata)",
      "old_belief": "Kepercayaan lama yang salah tentang laundry",
      "new_belief": "Kepercayaan baru yang brand ini tawarkan",
      "key_message": "Satu kalimat kunci yang merangkum belief layer ini"
    },
    "action_layer": {
      "ritual_title": "Nama ritual / aksi (3-5 kata)",
      "trigger_moments": ["momen 1 ketika pelanggan butuh layanan ini", "momen 2", "momen 3"],
      "ritual_steps": ["langkah 1", "langkah 2", "langkah 3"],
      "cta": "Call to action satu kalimat"
    }
  },
  "content_story_mapping": {
    "macro_story": {
      "umbrella_narrative": "Satu kalimat besar yang menjadi payung semua konten (seperti tagline strategi konten)",
      "brand_beliefs": ["Apa yang brand percaya 1", "Apa yang brand percaya 2", "Apa yang brand percaya 3"],
      "brand_stands_against": ["Anti ini 1", "Anti ini 2", "Anti ini 3"]
    },
    "micro_story": {
      "chapter_1": {
        "title": "Nama chapter 1",
        "theme": "Tema singkat",
        "content_ideas": ["ide konten 1", "ide konten 2", "ide konten 3"],
        "message": "Pesan utama chapter ini dalam 1 kalimat"
      },
      "chapter_2": {
        "title": "Nama chapter 2",
        "theme": "Tema singkat",
        "content_ideas": ["ide konten 1", "ide konten 2", "ide konten 3"],
        "message": "Pesan utama chapter ini dalam 1 kalimat"
      },
      "chapter_3": {
        "title": "Nama chapter 3",
        "theme": "Tema singkat",
        "content_ideas": ["ide konten 1", "ide konten 2", "ide konten 3"],
        "message": "Pesan utama chapter ini dalam 1 kalimat"
      }
    }
  },
  "brand_voice": {
    "tone_description": "Deskripsi 2 kalimat tentang tone brand",
    "personality_words": ["kata 1", "kata 2", "kata 3", "kata 4"],
    "dos": ["Lakukan ini 1", "Lakukan ini 2", "Lakukan ini 3", "Lakukan ini 4"],
    "donts": ["Jangan ini 1", "Jangan ini 2", "Jangan ini 3", "Jangan ini 4"]
  },
  "content_pillars": [
    {"name": "Nama Pilar 1", "description": "Deskripsi pilar ini dan jenis konten yang masuk", "example_hook": "Contoh hook caption untuk pilar ini"},
    {"name": "Nama Pilar 2", "description": "Deskripsi pilar ini dan jenis konten yang masuk", "example_hook": "Contoh hook caption untuk pilar ini"},
    {"name": "Nama Pilar 3", "description": "Deskripsi pilar ini dan jenis konten yang masuk", "example_hook": "Contoh hook caption untuk pilar ini"},
    {"name": "Nama Pilar 4", "description": "Deskripsi pilar ini dan jenis konten yang masuk", "example_hook": "Contoh hook caption untuk pilar ini"},
    {"name": "Nama Pilar 5", "description": "Deskripsi pilar ini dan jenis konten yang masuk", "example_hook": "Contoh hook caption untuk pilar ini"}
  ],
  "caption_examples": [
    {"type": "Soft Selling", "caption": "Caption lengkap 3-5 baris dengan hook + body + CTA, gaya sesuai brand personality"},
    {"type": "Edukasi", "caption": "Caption lengkap 3-5 baris dengan hook + body + CTA, gaya sesuai brand personality"},
    {"type": "Behind the Scenes", "caption": "Caption lengkap 3-5 baris dengan hook + body + CTA, gaya sesuai brand personality"}
  ]
}

Return ONLY the JSON. Tidak ada teks lain.
PROMPT;
    }

    private function parseResponse(string $content): array
    {
        $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $content = preg_replace('/\s*```$/', '', $content);

        $data = json_decode(trim($content), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Gagal memproses respons dari Claude: ' . json_last_error_msg());
        }

        return $data;
    }
}
