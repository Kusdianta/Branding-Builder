<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BrandAudit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateActivationKit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(public BrandAudit $audit) {}

    public function handle(): void
    {
        try {
            $pdf = Pdf::loadView('pdf.activation-kit', [
                'audit'           => $this->audit,
                'pillarOrder'     => [
                    'brand-konsistensi',
                    'brand-recall',
                    'brand-experience',
                    'digital-presence',
                ],
                'pillarLabels'    => [
                    'brand-konsistensi' => 'Konsistensi Brand',
                    'brand-recall'      => 'Brand Recall',
                    'brand-experience'  => 'Brand Experience',
                    'digital-presence'  => 'Digital Presence',
                ],
                'subBucketLabels' => [
                    'rating_tier'           => 'Rating',
                    'review_count_tier'     => 'Jumlah Review',
                    'keyword_saturation'    => 'Kata Kunci',
                    'sentiment_quality'     => 'Sentimen',
                    'search_recall'         => 'Search Recall',
                    'has_gmaps'             => 'Google Maps',
                    'has_instagram'         => 'Instagram',
                    'has_website'           => 'Website',
                    'has_wa'                => 'WhatsApp Business',
                    'has_tiktok'            => 'TikTok',
                    'review_bonus'          => 'Bonus Review',
                    'kehadiran_digital'     => 'Kehadiran Digital',
                    'konsistensi_visual'    => 'Konsistensi Visual',
                    'kelengkapan_layanan'   => 'Kelengkapan Layanan',
                    'transparansi_harga'    => 'Transparansi Harga',
                    'base'                  => 'Dasar',
                    'bonus_ekspres'         => 'Layanan Ekspres',
                    'bonus_antar_jemput'    => 'Antar Jemput',
                    'bonus_variasi_layanan' => 'Variasi Layanan',
                    'bonus_sop_keluhan'     => 'SOP Keluhan',
                    'bonus_price_list'      => 'Daftar Harga',
                    'penalty_keterlambatan' => 'Penalti Keterlambatan',
                    'penalty_pakaian_hilang' => 'Penalti Pakaian Hilang',
                    'penalty_no_response_wa' => 'Penalti No-Response WA',
                ],
            ])->setPaper('a4');

            $relativePath = "audits/{$this->audit->id}/activation-kit.pdf";
            Storage::disk('local')->put($relativePath, $pdf->output());

            $this->audit->update(['activation_kit_path' => $relativePath]);
        } catch (Throwable $e) {
            Log::error('GenerateActivationKit failed', [
                'audit_id' => $this->audit->id,
                'error'    => $e->getMessage(),
            ]);
            // Leave activation_kit_path null so the UI can show the retry button.
        }
    }
}
