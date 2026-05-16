<?php

declare(strict_types=1);

use App\Jobs\AnalyzeBrand;
use App\Models\AuditStep;
use App\Models\BrandAudit;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use function Livewire\Volt\layout;

layout('layouts.audit');

new class extends Component {
    use WithFileUploads;

    public string $step = 'touchpoint_inputs';

    // Form fields
    public string $brandName        = '';
    public string $city             = '';
    public string $serviceType      = 'kiloan';
    public string $instagramUrl     = '';
    public string $websiteUrl       = '';
    public string $tiktokUrl        = '';
    public string $gmapsUrl         = '';
    public bool   $whatsappBusiness = false;

    // File uploads (multi-file, image, max 3 each, 5MB each)
    public array $outletPhotosOuter = [];
    public array $outletPhotosInner = [];

    // Audit results
    public ?string $auditId            = null;
    public ?string $sessionToken       = null;
    public string  $auditStatus        = '';
    public ?int    $overallScore       = null;
    public ?string $overallLabel       = null;
    public array   $pillarScores       = [];
    public array   $subBucketScores    = [];
    public array   $keyFindings        = [];
    public array   $recommendations    = [];
    public ?string $errorMessage       = null;
    public ?string $activationKitPath  = null;
    public bool    $kitGenerating      = false;
    public array   $scoreBreakdown     = [];

    // Phase 7-C BB15: Instagram audit data for dashboard rendering.
    public array   $instagramAudit       = [];
    public ?string $instagramAuditStatus = null;

    // Phase 8 BB29: GMaps reviews data for dashboard rendering.
    public array   $gmapsReviews         = [];
    public ?string $gmapsReviewsStatus   = null;

    // BB21: per-step progress for the live loading view. Each entry:
    // ['key', 'track', 'status', 'order', 'elapsed_s', 'detail']
    public array   $auditSteps = [];

    // Modal
    public bool    $showModal   = false;
    public ?string $modalPillar = null;

    protected array $rules = [
        'brandName'              => 'required|string|max:100',
        'city'                   => 'nullable|string|max:100',
        'serviceType'            => 'required|string|in:kiloan,satuan,express,premium,mixed',
        'instagramUrl'           => 'nullable|url|max:500',
        'websiteUrl'             => 'nullable|url|max:500',
        'tiktokUrl'              => 'nullable|url|max:500',
        'gmapsUrl'               => 'nullable|url|max:500',
        'whatsappBusiness'       => 'boolean',
        'outletPhotosOuter'      => 'nullable|array|max:3',
        'outletPhotosOuter.*'    => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        'outletPhotosInner'      => 'nullable|array|max:3',
        'outletPhotosInner.*'    => 'image|mimes:jpg,jpeg,png,webp|max:5120',
    ];

    protected array $messages = [
        'brandName.required'     => 'Nama brand wajib diisi.',
        'instagramUrl.url'       => 'Format URL Instagram tidak valid.',
        'websiteUrl.url'         => 'Format URL website tidak valid.',
        'tiktokUrl.url'          => 'Format URL TikTok tidak valid.',
        'gmapsUrl.url'           => 'Format URL Google Maps tidak valid.',
        'outletPhotosOuter.max'  => 'Maksimal 3 foto outlet luar.',
        'outletPhotosOuter.*.image' => 'File harus berupa gambar.',
        'outletPhotosOuter.*.mimes' => 'Format harus JPG, PNG, atau WEBP.',
        'outletPhotosOuter.*.max'   => 'Ukuran maksimal 5 MB.',
        'outletPhotosInner.max'  => 'Maksimal 3 foto outlet dalam.',
        'outletPhotosInner.*.image' => 'File harus berupa gambar.',
        'outletPhotosInner.*.mimes' => 'Format harus JPG, PNG, atau WEBP.',
        'outletPhotosInner.*.max'   => 'Ukuran maksimal 5 MB.',
    ];

    public function mount(string $token = ''): void
    {
        if (! $token) {
            return;
        }

        $audit = BrandAudit::where('session_token', $token)->first();
        if (! $audit) {
            abort(404);
        }

        $this->loadAudit($audit);
    }

    private function loadAudit(BrandAudit $audit): void
    {
        $this->auditId           = $audit->id;
        $this->sessionToken      = $audit->session_token;
        $this->auditStatus       = $audit->status;
        $this->brandName         = $audit->brand_name ?? '';
        $this->overallScore      = $audit->overall_score;
        $this->overallLabel      = $audit->overall_label;
        $this->pillarScores      = $audit->pillar_scores ?? [];
        $this->subBucketScores   = $audit->sub_bucket_scores ?? [];
        $this->keyFindings       = $audit->key_findings ?? [];
        $this->recommendations   = $audit->recommendations ?? [];
        $this->errorMessage      = $audit->error_message;
        $this->activationKitPath = $audit->activation_kit_path;
        $this->scoreBreakdown    = $audit->score_breakdown ?? [];

        $this->instagramAudit       = (array) ($audit->instagram_audit ?? []);
        $this->instagramAuditStatus = $audit->instagram_audit_status;

        // Phase 8 BB29: GMaps reviews data for the new "Ulasan
        // Pelanggan" dashboard section.
        $this->gmapsReviews         = (array) ($audit->gmaps_reviews ?? []);
        $this->gmapsReviewsStatus   = $audit->gmaps_reviews_status;

        // BB21: load audit_steps for live loading view.
        $this->auditSteps = AuditStep::where('brand_audit_id', $audit->id)
            ->orderBy('order')
            ->get()
            ->map(fn ($s) => [
                'key'       => $s->step_key,
                'track'     => $s->track,
                'status'    => $s->status,
                'order'     => $s->order,
                'elapsed_s' => $s->elapsedSeconds(),
                'detail'    => $s->detail,
            ])
            ->all();

        // Stop showing the spinner once the file has actually landed.
        if ($this->activationKitPath) {
            $this->kitGenerating = false;
        }

        $this->step = match ($audit->status) {
            'done', 'failed' => 'dashboard',
            default          => 'analyzing',
        };
    }

    public function removePhoto(string $bucket, int $index): void
    {
        $prop = $bucket === 'outer' ? 'outletPhotosOuter' : 'outletPhotosInner';
        $arr = $this->{$prop};
        if (isset($arr[$index])) {
            array_splice($arr, $index, 1);
            $this->{$prop} = $arr;
        }
    }

    public function submit(): void
    {
        $this->validate();

        // "At least 2 of 5 touchpoint signals" rule from Phase 4 spec
        $signals = 0;
        $signals += $this->gmapsUrl     ? 1 : 0;
        $signals += $this->instagramUrl ? 1 : 0;
        $signals += $this->websiteUrl   ? 1 : 0;
        $signals += $this->tiktokUrl    ? 1 : 0;
        $signals += $this->whatsappBusiness ? 1 : 0;

        if ($signals < 2) {
            $this->addError('gmapsUrl', 'Minimal 2 dari 5 touchpoint harus diisi (Google Maps, Instagram, website, TikTok, atau WhatsApp Business).');
            return;
        }

        $outerPaths = [];
        foreach ($this->outletPhotosOuter as $photo) {
            $outerPaths[] = $photo->store('outlet-photos', 'public');
        }

        $innerPaths = [];
        foreach ($this->outletPhotosInner as $photo) {
            $innerPaths[] = $photo->store('outlet-photos', 'public');
        }

        $token = Str::random(64);

        $audit = BrandAudit::create([
            'session_token' => $token,
            'ip_address'    => request()->ip(),
            'brand_name'    => $this->brandName,
            'city'          => $this->city,
            'service_type'  => $this->serviceType,
            'touchpoints'   => [
                'instagram_url'            => $this->instagramUrl,
                'website_url'              => $this->websiteUrl,
                'tiktok_url'               => $this->tiktokUrl,
                'gmaps_url'                => $this->gmapsUrl,
                'whatsapp_business_active' => $this->whatsappBusiness,
                'outlet_photo_paths'       => array_merge($outerPaths, $innerPaths),
                'outlet_photo_outer_paths' => $outerPaths,
                'outlet_photo_inner_paths' => $innerPaths,
            ],
            'status'        => BrandAudit::STATUS_PENDING,
            'expires_at'    => now()->addDays(30),
        ]);

        AnalyzeBrand::dispatch($audit->id);

        $this->redirect(route('audit.show', ['token' => $token]), navigate: true);
    }

    public function pollStatus(): void
    {
        if (in_array($this->auditStatus, ['done', 'failed'], true) || ! $this->auditId) {
            return;
        }

        $audit = BrandAudit::find($this->auditId);
        if ($audit) {
            $this->loadAudit($audit);
        }
    }

    public function openModal(string $pillar): void
    {
        $this->modalPillar = $pillar;
        $this->dispatch('open-modal-recommendations');
    }

    public function closeModal(): void
    {
        $this->modalPillar = null;
        $this->dispatch('close-modal-recommendations');
    }

    public function generateKit(): void
    {
        if (! $this->auditId) {
            return;
        }

        $audit = BrandAudit::find($this->auditId);
        if (! $audit || ! $audit->isComplete()) {
            return;
        }

        $this->kitGenerating = true;
        \App\Jobs\GenerateActivationKit::dispatch($audit);
    }

    public function checkKit(): void
    {
        if (! $this->kitGenerating || ! $this->auditId) {
            return;
        }

        $audit = BrandAudit::find($this->auditId);
        if ($audit && $audit->activation_kit_path) {
            $this->activationKitPath = $audit->activation_kit_path;
            $this->kitGenerating     = false;
        }
    }

    public function with(): array
    {
        // 2x2 grid order: Konsistensi TL, Recall TR, Experience BL, Digital BR
        $pillarMeta = [
            'brand-konsistensi' => ['label' => 'Konsistensi Brand', 'icon' => 'ti-layers-intersect'],
            'brand-recall'      => ['label' => 'Brand Recall',      'icon' => 'ti-message-star'],
            'brand-experience'  => ['label' => 'Brand Experience',  'icon' => 'ti-users'],
            'digital-presence'  => ['label' => 'Digital Presence',  'icon' => 'ti-world'],
        ];

        $subBucketLabels = [
            // brand-recall
            'rating_tier'         => 'Rating',
            'review_count_tier'   => 'Jumlah Review',
            'keyword_saturation'  => 'Kata Kunci Positif di Ulasan', // BB18 alias for old rows
            'review_keyword_quality' => 'Kata Kunci Positif di Ulasan',
            'sentiment_quality'   => 'Sentimen',
            'search_recall'       => 'Search Recall',
            // digital-presence
            'has_gmaps'           => 'Google Maps',
            'has_instagram'       => 'Instagram',
            'has_website'         => 'Website',
            'has_wa'              => 'WhatsApp Business',
            'has_tiktok'          => 'TikTok',
            'review_bonus'        => 'Bonus Review',
            // brand-konsistensi
            'kehadiran_digital'   => 'Kehadiran Digital',
            'konsistensi_visual'  => 'Konsistensi Visual',
            'kelengkapan_layanan' => 'Kelengkapan Layanan',
            'transparansi_harga'  => 'Transparansi Harga',
            // brand-experience
            'base'                  => 'Dasar',
            'bonus_ekspres'         => 'Layanan Ekspres',
            'bonus_antar_jemput'    => 'Antar Jemput',
            'bonus_variasi_layanan' => 'Variasi Layanan',
            'bonus_sop_keluhan'     => 'SOP Keluhan',
            'bonus_price_list'      => 'Daftar Harga',
            'penalty_keterlambatan' => 'Penalti Keterlambatan',
            'penalty_pakaian_hilang' => 'Penalti Pakaian Hilang',
            'penalty_no_response_wa' => 'Penalti No-Response WA',
        ];

        // Normalize pillar scores: {pillar => {score, evidence, ...}} → {pillar => int}
        $pillarScoreInts = [];
        foreach ($this->pillarScores as $slug => $data) {
            $pillarScoreInts[$slug] = is_array($data) ? ($data['score'] ?? null) : $data;
        }

        // Build bucket → pillar reverse lookup so we can group recommendations by pillar
        $bucketToPillar = [];
        foreach ($this->subBucketScores as $pillarSlug => $buckets) {
            foreach (array_keys((array) $buckets) as $bucket) {
                $bucketToPillar[$bucket] = $pillarSlug;
            }
        }

        $modalRecs = $this->modalPillar
            ? array_values(array_filter(
                $this->recommendations,
                fn ($r) => ($bucketToPillar[$r['bucket'] ?? ''] ?? '') === $this->modalPillar,
            ))
            : [];

        // Count recs per pillar so cards know whether to show the link
        $recsByPillar = [];
        foreach ($this->recommendations as $r) {
            $p = $bucketToPillar[$r['bucket'] ?? ''] ?? null;
            if ($p) {
                $recsByPillar[$p] = ($recsByPillar[$p] ?? 0) + 1;
            }
        }

        return compact(
            'pillarMeta',
            'subBucketLabels',
            'modalRecs',
            'pillarScoreInts',
            'recsByPillar',
        ) + [
            'serviceTypes' => [
                'kiloan'  => 'Kiloan (per kg)',
                'satuan'  => 'Satuan (per pakaian)',
                'express' => 'Express',
                'premium' => 'Premium',
                'mixed'   => 'Campuran',
            ],
        ];
    }
};
?>

<div class="relative">
    <style>
        @keyframes baw-spin { to { transform: rotate(360deg); } }
        @keyframes baw-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.25; } }
        .baw-spinner { width: 48px; height: 48px; border: 3px solid var(--chimera-100); border-top-color: var(--chimera-500); border-radius: 50%; animation: baw-spin .8s linear infinite; }
        .baw-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--chimera-300); flex-shrink: 0; animation: baw-pulse 1.4s ease-in-out infinite; }
        .baw-err { font-size: 12px; color: var(--color-danger); margin-top: 4px; }
        .baw-photo-btn { display: inline-flex; align-items: center; gap: 6px; border: 1px dashed var(--border-strong); border-radius: var(--radius-md); padding: 10px 14px; background: var(--surface-muted); color: var(--text-secondary); font-size: 13px; cursor: pointer; transition: border-color .15s; }
        .baw-photo-btn:hover { border-color: var(--chimera-500); color: var(--chimera-600); }
        .baw-photo-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 12px; background: var(--surface-muted); border: 1px solid var(--border-default); border-radius: var(--radius-sm); font-size: 12px; }
        .baw-photo-remove { background: none; border: none; color: var(--color-danger); cursor: pointer; font-size: 14px; padding: 0 4px; }
    </style>

    {{-- ===== STEP 1: FORM ===== --}}
    @if ($step === 'touchpoint_inputs')
        <section class="max-w-3xl mx-auto">
            <div class="mb-8">
                <h1 style="font-size: 30px; font-weight: 600; color: var(--text-primary); letter-spacing: -0.01em;">Brand Health Check</h1>
                <p style="font-size: 15px; color: var(--text-secondary); margin-top: 8px; line-height: 1.6;">
                    Isi informasi brand laundry Anda. Saya akan menganalisis 4 pilar kekuatan brand dalam 30–60 detik.
                </p>
            </div>

            <div class="nui-card p-8">
                <form wire:submit="submit" class="flex flex-col gap-6">

                    {{-- Brand info --}}
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-nui-form-input
                            name="brandName"
                            label="Nama Brand"
                            wire:model="brandName"
                            placeholder="Contoh: Less Worry Laundry"
                            autocomplete="organization"
                            :required="true"
                            :error="$errors->first('brandName')"
                        />
                        <x-nui-form-input
                            name="city"
                            label="Kota / Area"
                            wire:model="city"
                            placeholder="Contoh: Jakarta Selatan"
                            autocomplete="address-level2"
                            :error="$errors->first('city')"
                        />
                    </div>

                    {{-- Service type --}}
                    <x-nui-form-select
                        name="serviceType"
                        label="Jenis Layanan Utama"
                        wire:model="serviceType"
                        :options="$serviceTypes"
                        :selected="$serviceType"
                        :required="true"
                    />

                    {{-- Touchpoints heading --}}
                    <div>
                        <p style="font-size: 14px; font-weight: 600; color: var(--text-primary);">Touchpoint Digital</p>
                        <p style="font-size: 13px; color: var(--text-secondary); margin-top: 2px;">
                            Minimal 2 dari 5 touchpoint harus diisi (Google Maps, Instagram, Website, TikTok, atau WhatsApp Business).
                        </p>
                    </div>

                    {{-- URL inputs --}}
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <x-nui-form-input
                            name="gmapsUrl"
                            label="Google Maps"
                            type="url"
                            wire:model="gmapsUrl"
                            placeholder="https://maps.app.goo.gl/..."
                            :error="$errors->first('gmapsUrl')"
                        />
                        <x-nui-form-input
                            name="instagramUrl"
                            label="Instagram"
                            type="url"
                            wire:model="instagramUrl"
                            placeholder="https://instagram.com/laundry_anda"
                            :error="$errors->first('instagramUrl')"
                        />
                        <x-nui-form-input
                            name="websiteUrl"
                            label="Website"
                            type="url"
                            wire:model="websiteUrl"
                            placeholder="https://laundryanda.com"
                            :error="$errors->first('websiteUrl')"
                        />
                        <x-nui-form-input
                            name="tiktokUrl"
                            label="TikTok"
                            type="url"
                            wire:model="tiktokUrl"
                            placeholder="https://tiktok.com/@laundry_anda"
                            :error="$errors->first('tiktokUrl')"
                        />
                    </div>

                    {{-- WhatsApp Business checkbox --}}
                    <label
                        for="whatsappBusiness"
                        class="flex items-start gap-3 p-4 rounded-lg cursor-pointer"
                        style="background: var(--surface-muted); border: 1px solid var(--border-default);"
                    >
                        <input
                            type="checkbox"
                            id="whatsappBusiness"
                            wire:model="whatsappBusiness"
                            style="width: 18px; height: 18px; margin-top: 2px; accent-color: var(--chimera-500);"
                        />
                        <div>
                            <p style="font-size: 14px; font-weight: 500; color: var(--text-primary);">
                                <i class="ti ti-brand-whatsapp" style="color: var(--color-success); margin-right: 4px;"></i>
                                Saya menggunakan WhatsApp Business untuk pelanggan
                            </p>
                            <p style="font-size: 12px; color: var(--text-secondary); margin-top: 2px;">
                                Centang jika brand Anda aktif menggunakan WhatsApp Business (bukan WhatsApp pribadi).
                            </p>
                        </div>
                    </label>

                    {{-- File uploads --}}
                    <div>
                        <p style="font-size: 14px; font-weight: 600; color: var(--text-primary);">Foto Outlet (Opsional)</p>
                        <p style="font-size: 13px; color: var(--text-secondary); margin-top: 2px; margin-bottom: 16px;">
                            Maksimal 3 foto per kategori. Format JPG, PNG, atau WEBP. Ukuran maksimal 5 MB per foto.
                        </p>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            {{-- Outer photos --}}
                            <div class="flex flex-col gap-2">
                                <label style="font-size: 13px; font-weight: 500; color: var(--text-primary);">
                                    <i class="ti ti-building-store" style="color: var(--chimera-500); margin-right: 4px;"></i>
                                    Foto Outlet Luar
                                </label>
                                <label for="outerPhotos" class="baw-photo-btn">
                                    <i class="ti ti-upload"></i>
                                    Pilih foto ({{ count($outletPhotosOuter) }}/3)
                                </label>
                                <input
                                    id="outerPhotos"
                                    type="file"
                                    wire:model="outletPhotosOuter"
                                    multiple
                                    accept="image/jpeg,image/png,image/webp"
                                    style="display: none;"
                                />
                                <div wire:loading wire:target="outletPhotosOuter" style="font-size: 12px; color: var(--text-tertiary);">
                                    Mengunggah...
                                </div>
                                @if (count($outletPhotosOuter) > 0)
                                    <div class="flex flex-col gap-1.5 mt-1">
                                        @foreach ($outletPhotosOuter as $idx => $photo)
                                            <div class="baw-photo-row">
                                                <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                    <i class="ti ti-photo" style="color: var(--chimera-500);"></i>
                                                    {{ method_exists($photo, 'getClientOriginalName') ? $photo->getClientOriginalName() : 'foto-' . ($idx + 1) }}
                                                </span>
                                                <button type="button" wire:click="removePhoto('outer', {{ $idx }})" class="baw-photo-remove" title="Hapus">
                                                    <i class="ti ti-x"></i>
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                @error('outletPhotosOuter') <p class="baw-err">{{ $message }}</p> @enderror
                                @error('outletPhotosOuter.*') <p class="baw-err">{{ $message }}</p> @enderror
                            </div>

                            {{-- Inner photos --}}
                            <div class="flex flex-col gap-2">
                                <label style="font-size: 13px; font-weight: 500; color: var(--text-primary);">
                                    <i class="ti ti-armchair" style="color: var(--chimera-500); margin-right: 4px;"></i>
                                    Foto Outlet Dalam
                                </label>
                                <label for="innerPhotos" class="baw-photo-btn">
                                    <i class="ti ti-upload"></i>
                                    Pilih foto ({{ count($outletPhotosInner) }}/3)
                                </label>
                                <input
                                    id="innerPhotos"
                                    type="file"
                                    wire:model="outletPhotosInner"
                                    multiple
                                    accept="image/jpeg,image/png,image/webp"
                                    style="display: none;"
                                />
                                <div wire:loading wire:target="outletPhotosInner" style="font-size: 12px; color: var(--text-tertiary);">
                                    Mengunggah...
                                </div>
                                @if (count($outletPhotosInner) > 0)
                                    <div class="flex flex-col gap-1.5 mt-1">
                                        @foreach ($outletPhotosInner as $idx => $photo)
                                            <div class="baw-photo-row">
                                                <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                    <i class="ti ti-photo" style="color: var(--chimera-500);"></i>
                                                    {{ method_exists($photo, 'getClientOriginalName') ? $photo->getClientOriginalName() : 'foto-' . ($idx + 1) }}
                                                </span>
                                                <button type="button" wire:click="removePhoto('inner', {{ $idx }})" class="baw-photo-remove" title="Hapus">
                                                    <i class="ti ti-x"></i>
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                @error('outletPhotosInner') <p class="baw-err">{{ $message }}</p> @enderror
                                @error('outletPhotosInner.*') <p class="baw-err">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>

                    {{-- Privacy note --}}
                    <div class="flex items-start gap-3 p-4 rounded-lg" style="background: var(--chimera-50); border: 1px solid var(--chimera-100);">
                        <i class="ti ti-info-circle" style="color: var(--chimera-600); font-size: 16px; margin-top: 2px; flex-shrink: 0;"></i>
                        <p style="font-size: 13px; color: var(--chimera-700);">
                            Saya menggunakan data publik dari Google Maps, Instagram, dan TikTok. Foto outlet hanya dipakai untuk audit visual brand. Tidak ada data sensitif yang disimpan.
                        </p>
                    </div>

                    {{-- Submit (inside card, full width) --}}
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="submit"
                        class="nui-btn-primary rounded-pill"
                        style="font-size: 15px; font-weight: 500; padding: 12px 24px; align-self: stretch;"
                    >
                        <span wire:loading.remove wire:target="submit">
                            Analisis Brand <i class="ti ti-sparkles"></i>
                        </span>
                        <span wire:loading wire:target="submit">Memulai analisis...</span>
                    </button>

                </form>
            </div>
        </section>
    @endif

    {{-- ===== STEP 2: ANALYZING (BB21 live progress) ===== --}}
    @if ($step === 'analyzing')
        @php
            $stepLabels = [
                // Phase 1 — gather
                'gather_places'              => 'Mengambil data Google Places',
                'gather_gmaps'               => 'Scraping ulasan Google Maps',
                'gather_instagram'           => 'Scraping profil Instagram',
                // Phase 2 — validate
                'validate_evidence'          => 'Validasi kecocokan brand',
                // Phase 3 — score
                'score_recall'               => 'Skoring Brand Recall',
                'score_digital'              => 'Skoring Digital Presence',
                'score_konsistensi'          => 'Skoring Brand Konsistensi',
                'score_experience'           => 'Skoring Brand Experience',
                // Phase 3 (cont.) — insights + PDF
                'generate_recommendations'   => 'Generate 5 rekomendasi prioritas',
                'generate_quick_wins'        => 'Generate quick wins',
                'generate_positioning'       => 'Generate posisi kompetitif',
                'generate_pdf'               => 'Generate activation kit PDF',
            ];
            $trackLabels = [
                'gather'    => 'Fase 1 · Kumpulkan data',
                'validate'  => 'Fase 2 · Validasi',
                'score'     => 'Fase 3 · Skoring pilar',
                'final'     => 'Fase 3 · Insight + PDF',
            ];
            $groupedSteps = [];
            foreach ($auditSteps as $s) {
                $groupedSteps[$s['track']][] = $s;
            }
            $stepIcon = static fn (string $st): string => match ($st) {
                'done'    => '✓',
                'running' => '⏳',
                'failed'  => '✗',
                default   => '○',
            };
            $stepClr = static fn (string $st): string => match ($st) {
                'done'    => 'var(--color-success)',
                'running' => 'var(--chimera-600)',
                'failed'  => 'var(--color-danger)',
                default   => 'var(--text-tertiary)',
            };
        @endphp
        <div @if (! in_array($auditStatus, ['done', 'failed'])) wire:poll.2000ms="pollStatus" @endif class="max-w-3xl mx-auto py-12">
            <div class="text-center mb-10">
                <h2 style="font-size: 24px; font-weight: 600; color: var(--text-primary);">
                    Menganalisis brand <em>{{ $brandName }}</em>
                </h2>
                <p style="font-size: 14px; color: var(--text-secondary); margin-top: 8px;">
                    Track A (pilar brand) dan Track B (Instagram) berjalan paralel. Halaman ini otomatis update setiap 2 detik.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                @foreach (['a', 'b'] as $trackKey)
                    <x-nui-card>
                        <p style="font-size: 11px; font-weight: 600; color: var(--text-tertiary); letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 12px;">
                            {{ $trackLabels[$trackKey] ?? $trackKey }}
                        </p>
                        @forelse ($groupedSteps[$trackKey] ?? [] as $s)
                            <div class="flex items-center justify-between py-2" style="border-bottom: 1px solid var(--border-default);">
                                <div class="flex items-center gap-3">
                                    <span style="font-size: 16px; color: {{ $stepClr($s['status']) }}; min-width: 18px; display: inline-block; text-align: center;">{{ $stepIcon($s['status']) }}</span>
                                    <span style="font-size: 13px; color: {{ $s['status'] === 'pending' ? 'var(--text-tertiary)' : 'var(--text-primary)' }};">
                                        {{ $stepLabels[$s['key']] ?? $s['key'] }}
                                    </span>
                                </div>
                                @if ($s['elapsed_s'] !== null)
                                    <span style="font-size: 11px; color: var(--text-tertiary); font-variant-numeric: tabular-nums;">{{ $s['elapsed_s'] }}s</span>
                                @endif
                            </div>
                        @empty
                            <p style="font-size: 13px; color: var(--text-tertiary);">(menunggu jadwal)</p>
                        @endforelse
                    </x-nui-card>
                @endforeach
            </div>

            @if (! empty($groupedSteps['final']))
                <div class="mt-6">
                    <x-nui-card>
                        <p style="font-size: 11px; font-weight: 600; color: var(--text-tertiary); letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 12px;">
                            {{ $trackLabels['final'] }}
                        </p>
                        @foreach ($groupedSteps['final'] as $s)
                            <div class="flex items-center justify-between py-2">
                                <div class="flex items-center gap-3">
                                    <span style="font-size: 16px; color: {{ $stepClr($s['status']) }}; min-width: 18px; display: inline-block; text-align: center;">{{ $stepIcon($s['status']) }}</span>
                                    <span style="font-size: 13px; color: {{ $s['status'] === 'pending' ? 'var(--text-tertiary)' : 'var(--text-primary)' }};">
                                        {{ $stepLabels[$s['key']] ?? $s['key'] }}
                                        @if ($s['status'] === 'pending') <span style="color: var(--text-tertiary); font-size: 11px;">(menunggu kedua track selesai)</span> @endif
                                    </span>
                                </div>
                                @if ($s['elapsed_s'] !== null)
                                    <span style="font-size: 11px; color: var(--text-tertiary); font-variant-numeric: tabular-nums;">{{ $s['elapsed_s'] }}s</span>
                                @endif
                            </div>
                        @endforeach
                    </x-nui-card>
                </div>
            @endif
        </div>
    @endif

    {{-- ===== STEP 3: DASHBOARD ===== --}}
    @if ($step === 'dashboard')
        @php
            $tierColor = static fn (?int $s): string => match (true) {
                ($s ?? 0) >= 70 => 'var(--chimera-500)',
                ($s ?? 0) >= 50 => 'var(--color-warning)',
                default         => 'var(--color-danger)',
            };
            $circ     = 263.89;
            $filled   = $overallScore ? round(($overallScore / 100) * $circ, 2) : 0;
            $scoreClr = $tierColor($overallScore);
        @endphp

        <div class="max-w-5xl mx-auto pb-16">

            {{-- Header --}}
            <div class="flex items-start justify-between mb-10 flex-wrap gap-4">
                <div>
                    <p style="font-size: 13px; color: var(--text-tertiary); margin-bottom: 4px;">Hasil Brand Health Check</p>
                    <h2 style="font-size: 26px; font-weight: 600; color: var(--text-primary);">{{ $brandName }}</h2>
                </div>
                <div class="flex items-center gap-3 flex-wrap" @if ($kitGenerating) wire:poll.3000ms="checkKit" @endif>
                    <a href="{{ route('home') }}" style="font-size: 13px; color: var(--chimera-600); text-decoration: underline;">
                        Analisis brand lain
                    </a>

                    @if ($activationKitPath && $sessionToken)
                        <a
                            href="{{ route('audit.kit.download', ['token' => $sessionToken]) }}"
                            class="nui-btn-primary rounded-pill"
                            style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; font-size: 13px; font-weight: 500;"
                        >
                            <i class="ti ti-download"></i>
                            Download Activation Kit (PDF)
                        </a>
                    @elseif ($kitGenerating)
                        <span
                            style="display: inline-flex; align-items: center; gap: 8px; border: 1px solid var(--border-default); border-radius: var(--radius-pill); padding: 8px 16px; font-size: 13px; font-weight: 500; color: var(--text-secondary); background: var(--surface-muted);"
                        >
                            <span style="width: 14px; height: 14px; border: 2px solid var(--chimera-200); border-top-color: var(--chimera-500); border-radius: 50%; display: inline-block; animation: baw-spin .8s linear infinite;"></span>
                            Membuat activation kit...
                        </span>
                    @else
                        <button
                            type="button"
                            wire:click="generateKit"
                            wire:loading.attr="disabled"
                            wire:target="generateKit"
                            class="nui-btn-primary rounded-pill"
                            style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; font-size: 13px; font-weight: 500;"
                        >
                            <span wire:loading.remove wire:target="generateKit">
                                <i class="ti ti-file-text"></i> Buat Activation Kit (PDF)
                            </span>
                            <span wire:loading wire:target="generateKit">Memulai...</span>
                        </button>
                    @endif
                </div>
            </div>

            @if ($auditStatus === 'failed')
                <x-nui-card>
                    <div style="border-left: 3px solid var(--color-danger); padding-left: 16px;">
                        <p style="font-weight: 500; color: var(--color-danger); margin-bottom: 4px;">Analisis gagal</p>
                        @if ($errorMessage)
                            <p style="font-size: 13px; color: var(--text-secondary);">{{ $errorMessage }}</p>
                        @endif
                        <a href="{{ route('home') }}" style="font-size: 13px; color: var(--chimera-600); text-decoration: underline; margin-top: 12px; display: inline-block;">
                            Coba lagi
                        </a>
                    </div>
                </x-nui-card>
            @else

                {{-- ===== Overall score ring (centered, top of dashboard) ===== --}}
                <div class="max-w-md mx-auto mb-12 text-center">
                    <div style="position: relative; width: 220px; height: 220px; margin: 0 auto;">
                        <svg viewBox="0 0 100 100" style="width: 220px; height: 220px;">
                            <circle cx="50" cy="50" r="42" fill="none" stroke="var(--chimera-50)" stroke-width="7"/>
                            <circle
                                cx="50" cy="50" r="42"
                                fill="none"
                                stroke="{{ $scoreClr }}"
                                stroke-width="7"
                                stroke-dasharray="{{ $filled }} {{ $circ }}"
                                stroke-linecap="round"
                                transform="rotate(-90 50 50)"
                            />
                        </svg>
                        <div style="position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                            <span style="font-size: 56px; font-weight: 700; line-height: 1; color: {{ $scoreClr }}; font-family: var(--font-display);">{{ $overallScore ?? '—' }}</span>
                            <span style="font-size: 12px; color: var(--text-tertiary); margin-top: 4px;">dari 100</span>
                        </div>
                    </div>
                    @if ($overallLabel)
                        <p style="font-size: 18px; font-weight: 600; color: var(--text-primary); margin-top: 16px;">
                            {{ $overallLabel }}
                        </p>
                    @endif
                </div>

                {{-- ===== Pillar grid (single column) ===== --}}
                @php
                    // BB32: methodology copy explaining WHY each pillar gets its
                    // weight and why each sub-bucket carries its specific cap.
                    // Keyed on pillar slug; values match config/branding.php.
                    // Sourced from config:
                    //   pillar_weights — Konsistensi 35 / Recall 35 / Experience 20 / Digital 10
                    //   pillar_sub_buckets — see config/branding.php:40-73
                    $pillarMethodology = [
                        'brand-konsistensi' => 'Brand Konsistensi (35% of overall score) measures how unified your brand identity is across digital touchpoints — the strongest predictor of long-term recall in customer interviews. Kehadiran Digital (40 pts) carries the highest weight because consistent presence across IG + Website + GMaps + WA + TikTok signals a real operating brand, not a side hustle. Konsistensi Visual (35 pts) judges whether logo, color, and tone hold together when a customer scans across channels. Kelengkapan Layanan (15 pts) and Transparansi Harga (10 pts) round out the score with specific operational signals.',
                        'brand-recall' => 'Brand Recall (35% of overall score) measures how easily potential customers recognize your brand when searching for laundry services. Search Recall (35 pts) carries the highest weight because Google Autocomplete demand is the strongest proxy for true brand awareness in your area. Rating (25 pts) and Jumlah Review (15 pts) reflect cumulative social proof. Kata Kunci Positif di Ulasan (15 pts) and Sentimen (10 pts) measure qualitative reception drawn from up to 30 scraped GMaps reviews per audit.',
                        'brand-experience' => 'Brand Experience (20% of overall score) measures the operational signals customers encounter when interacting with the brand. Every audit starts at a Dasar of 30 pts, with bonuses fired by LLM analysis of touchpoint copy: Variasi Layanan (+15), SOP Keluhan (+15), Antar Jemput (+12), Layanan Ekspres (+10), Daftar Harga (+10). Penalty deltas — Pakaian Hilang (−10), Keterlambatan (−8), No-Response WA (−8) — are deterministic, fired only when the GMaps review corpus contains explicit complaints.',
                        'digital-presence' => 'Digital Presence (10% of overall score) measures how discoverable and complete your brand is across the channels customers actually check. Weights reflect platform impact for laundry SMBs: Google Maps (25 pts — discovery driver #1), Instagram (20 pts), Website (20 pts), WhatsApp Business (15 pts — direct order channel), TikTok (10 pts — emerging). A Bonus Review of up to 15 extra pts fires when the GMaps listing has accumulated meaningful review volume.',
                    ];
                @endphp
                <div class="grid grid-cols-1 gap-6 mb-12">
                    @foreach ($pillarMeta as $slug => $meta)
                        @php
                            $ps      = $pillarScoreInts[$slug] ?? null;
                            $pc      = $tierColor($ps);
                            $sbs     = $subBucketScores[$slug] ?? [];
                            $hasRecs = ($recsByPillar[$slug] ?? 0) > 0;
                            $methodology = $pillarMethodology[$slug] ?? null;
                            // BB34: pillar-level LLM reasoning — rendered ONCE above
                            // the sub-bucket list so it isn't duplicated under every
                            // row (the BB17 anti-pattern this fix retires).
                            $pillarLlmReasoning = (string) (
                                is_array($pillarScores[$slug] ?? null)
                                    ? ($pillarScores[$slug]['reasoning'] ?? '')
                                    : ''
                            );
                        @endphp
                        <x-nui-card>
                            <div class="flex items-center justify-between mb-4">
                                <div class="flex items-center gap-3">
                                    <div style="width: 36px; height: 36px; border-radius: var(--radius-md); background: var(--chimera-50); display: flex; align-items: center; justify-content: center;">
                                        <i class="ti {{ $meta['icon'] }}" style="color: var(--chimera-600); font-size: 18px;"></i>
                                    </div>
                                    <span style="font-size: 15px; font-weight: 600; color: var(--text-primary);">{{ $meta['label'] }}</span>
                                </div>
                                <span style="font-size: 28px; font-weight: 700; color: {{ $pc }}; line-height: 1;">{{ $ps ?? '—' }}</span>
                            </div>

                            <div style="height: 6px; background: var(--chimera-50); border-radius: 999px; margin-bottom: 16px;">
                                <div style="height: 100%; width: {{ $ps ?? 0 }}%; background: {{ $pc }}; border-radius: 999px;"></div>
                            </div>

                            {{-- BB32: "About this score" methodology block — explains the
                                 weight allocation so the numbers below are interpretable
                                 instead of opaque. Renders as muted annotation, not
                                 primary content. --}}
                            @if ($methodology !== null)
                                <div style="margin-bottom: 16px; padding: 12px 14px; background: var(--surface-muted); border-left: 3px solid var(--chimera-200); border-radius: var(--radius-sm);">
                                    <p style="font-size: 10px; font-weight: 600; color: var(--chimera-700); letter-spacing: 0.4px; text-transform: uppercase; margin: 0 0 6px;">About this score</p>
                                    <p style="font-size: 12px; color: var(--text-secondary); line-height: 1.6; margin: 0;">{{ $methodology }}</p>
                                </div>
                            @endif

                            {{-- BB34: pillar-level LLM reasoning — rendered ONCE here
                                 instead of being copied under every sub-bucket row. The
                                 narrative summarises the LLM's overall judgment of THIS
                                 audit's data, vs the static "About this score" block
                                 above which explains the scoring methodology. --}}
                            @if ($pillarLlmReasoning !== '')
                                <div style="margin-bottom: 16px; padding: 12px 14px; background: var(--surface-card); border: 1px solid var(--border-default); border-radius: var(--radius-sm);">
                                    <p style="font-size: 10px; font-weight: 600; color: var(--text-tertiary); letter-spacing: 0.4px; text-transform: uppercase; margin: 0 0 6px;">Ringkasan penilaian</p>
                                    <p style="font-size: 13px; color: var(--text-primary); line-height: 1.65; margin: 0;">{{ $pillarLlmReasoning }}</p>
                                </div>
                            @endif

                            @if (count($sbs) > 0)
                                <div class="flex flex-col mb-4" style="border-top: 1px solid var(--border-default);">
                                    @foreach ($sbs as $k => $v)
                                        @php $bd = is_array($scoreBreakdown[$slug][$k] ?? null) ? $scoreBreakdown[$slug][$k] : null; @endphp
                                        <div style="border-bottom: 1px solid var(--border-default);">
                                            <div class="flex justify-between items-center py-2">
                                                <span style="font-size: 12px; color: var(--text-secondary);">{{ $subBucketLabels[$k] ?? $k }}</span>
                                                <span style="font-size: 13px; font-weight: 500; color: var(--text-primary);">{{ $v }}</span>
                                            </div>
                                            @if ($bd !== null)
                                                {{-- BB32: BB17/BB23 dropdown reverted — sub-bucket breakdown
                                                     renders inline so users see how the score is computed
                                                     without needing to click. Per-pillar methodology lives
                                                     in the "About this score" block above the sub-bucket
                                                     list, demystifying weights without repeating data. --}}
                                                <div style="padding: 8px 12px 12px; background: var(--surface-muted); border-top: 1px solid var(--border-default); font-size: 11px; color: var(--text-secondary);">
                                                    @php
                                                        $formula      = $bd['formula'] ?? 'unknown';
                                                        $rawInputs    = (array) ($bd['raw_inputs'] ?? []);
                                                        $tierTable    = (array) ($bd['tier_table'] ?? []);
                                                        $signals      = (array) ($bd['signals'] ?? []);
                                                        $suggestions  = (array) ($rawInputs['suggestions'] ?? []);
                                                        $llmReasoning = (string) ($bd['llm_reasoning'] ?? '');
                                                        $limitations  = (array) ($bd['limitations'] ?? []);
                                                        $signalLabels = [
                                                            'brand_recognition' => 'Pengenalan Brand',
                                                            'geographic_spread' => 'Sebaran Lokasi',
                                                            'variant_coverage'  => 'Variasi Pencarian',
                                                        ];

                                                        if ($formula === 'llm_judgment') {
                                                            $inputLine = implode(', ', (array) ($rawInputs['context_provided'] ?? []));
                                                        } elseif ($formula === 'deterministic_signals') {
                                                            $inputLine = sprintf(
                                                                'brand_stem: %s · sumber: %s',
                                                                (string) ($rawInputs['brand_stem'] ?? '—'),
                                                                (string) ($rawInputs['source'] ?? 'Google Autocomplete'),
                                                            );
                                                        } else {
                                                            $parts = [];
                                                            foreach ($rawInputs as $rk => $rv) {
                                                                if (in_array($rk, ['source', 'suggestions', 'suggestion_count', 'brand_name', 'brand_stem', 'fetched_at'], true)) continue;
                                                                $parts[] = $rk . ': ' . (is_bool($rv) ? ($rv ? 'Ya' : 'Tidak') : $rv);
                                                            }
                                                            $inputLine = implode(' · ', $parts);
                                                        }
                                                    @endphp

                                                    {{-- BB23: header moved out to the toggle button above. --}}
                                                    @php
                                                        $formulaLabel = match ($formula) {
                                                            'deterministic_threshold' => 'Threshold tier-based (deterministik)',
                                                            'deterministic_signals'   => 'Signal-based weighted (deterministik)',
                                                            'llm_judgment'            => 'Penilaian LLM (Claude)',
                                                            default                   => 'Lainnya',
                                                        };
                                                        $sourceLabel = match ($k) {
                                                            'rating_tier', 'review_count_tier', 'keyword_saturation', 'review_keyword_quality', 'sentiment_quality' => 'Google Maps reviews',
                                                            'search_recall' => 'Google Autocomplete',
                                                            'has_gmaps', 'has_instagram', 'has_website', 'has_wa', 'has_tiktok' => 'Touchpoint input form',
                                                            'review_bonus' => 'Google Maps review count threshold',
                                                            'kehadiran_digital', 'konsistensi_visual', 'kelengkapan_layanan', 'transparansi_harga' => 'Penilaian visual + URL touchpoint',
                                                            'base', 'bonus_ekspres', 'bonus_antar_jemput', 'bonus_variasi_layanan', 'bonus_sop_keluhan', 'bonus_price_list', 'penalty_keterlambatan', 'penalty_pakaian_hilang', 'penalty_no_response_wa' => 'Penilaian LLM dari konteks brand',
                                                            default => 'Konteks brand',
                                                        };
                                                    @endphp
                                                    <p style="margin: 0 0 6px;"><strong>Sumber:</strong> {{ $sourceLabel }} · <strong>Formula:</strong> {{ $formulaLabel }}</p>

                                                    @if ($k === 'search_recall')
                                                        <p style="margin: 0 0 6px; line-height: 1.55;">Frekuensi dan variasi brand muncul di hasil autocomplete pencarian.</p>
                                                    @endif

                                                    <p style="margin: 0 0 6px;"><strong>Berdasarkan:</strong> {{ $inputLine }}</p>

                                                    @if ($formula === 'deterministic_threshold' && count($tierTable) > 0)
                                                        <table style="width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 6px;">
                                                            @foreach ($tierTable as $tier)
                                                                @php $isMatch = (bool) ($tier['matched'] ?? false); @endphp
                                                                <tr style="background: {{ $isMatch ? 'var(--chimera-500)' : 'transparent' }}; color: {{ $isMatch ? '#FFFFFF' : 'inherit' }}; border-radius: 4px;">
                                                                    <td style="padding: 3px 8px;">{{ $tier['range'] }}</td>
                                                                    <td style="padding: 3px 8px; text-align: right; font-weight: {{ $isMatch ? '600' : 'normal' }};">{{ $tier['points'] }} pt</td>
                                                                </tr>
                                                            @endforeach
                                                        </table>
                                                    @endif

                                                    @if ($formula === 'deterministic_signals' && count($signals) > 0)
                                                        <table style="width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 6px;">
                                                            @foreach ($signals as $sigKey => $sig)
                                                                @php
                                                                    $sigScore  = (int) ($sig['score'] ?? 0);
                                                                    $sigCap    = (int) ($sig['cap'] ?? 0);
                                                                    $sigDetail = (string) ($sig['detail'] ?? '');
                                                                    $isFull    = $sigCap > 0 && $sigScore >= $sigCap;
                                                                @endphp
                                                                <tr style="background: {{ $isFull ? 'var(--chimera-500)' : 'transparent' }}; color: {{ $isFull ? '#FFFFFF' : 'inherit' }};">
                                                                    <td style="padding: 3px 8px; vertical-align: top;">{{ $signalLabels[$sigKey] ?? $sigKey }}</td>
                                                                    <td style="padding: 3px 8px; text-align: right; font-weight: {{ $isFull ? '600' : 'normal' }}; white-space: nowrap;">{{ $sigScore }} / {{ $sigCap }} pt</td>
                                                                </tr>
                                                                @if ($sigDetail !== '')
                                                                    <tr>
                                                                        <td colspan="2" style="padding: 0 8px 4px; font-size: 10px; color: var(--text-tertiary); line-height: 1.5;">{{ $sigDetail }}</td>
                                                                    </tr>
                                                                @endif
                                                            @endforeach
                                                        </table>

                                                        @if (count($suggestions) > 0)
                                                            <p style="margin: 4px 0 2px; font-weight: 600; color: var(--text-secondary);">Top {{ count($suggestions) }} hasil autocomplete:</p>
                                                            <ol style="margin: 0 0 6px 18px; padding: 0; font-size: 10px; color: var(--text-secondary); line-height: 1.55;">
                                                                @foreach ($suggestions as $sug)
                                                                    <li>{{ $sug }}</li>
                                                                @endforeach
                                                            </ol>
                                                        @endif
                                                    @endif

                                                    @if ($formula === 'llm_judgment' && $llmReasoning !== '')
                                                        <p style="margin: 0 0 4px; line-height: 1.55;">{{ $llmReasoning }}</p>
                                                    @endif

                                                    @if (count($limitations) > 0)
                                                        <p style="margin: 0; font-style: italic; color: var(--text-tertiary); font-size: 10px;">Keterbatasan: {{ implode('; ', $limitations) }}</p>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if ($hasRecs)
                                <button
                                    type="button"
                                    wire:click="openModal('{{ $slug }}')"
                                    style="font-size: 13px; font-weight: 500; color: var(--chimera-600); text-decoration: none; background: none; border: none; cursor: pointer; padding: 0; display: inline-flex; align-items: center; gap: 4px;"
                                    onmouseover="this.style.textDecoration='underline'"
                                    onmouseout="this.style.textDecoration='none'"
                                >
                                    Lihat rekomendasi <i class="ti ti-arrow-right" style="font-size: 13px;"></i>
                                </button>
                            @endif
                        </x-nui-card>
                    @endforeach
                </div>

                {{-- ===== Phase 7-C BB15: Audit Profil Instagram ===== --}}
                @include('livewire._instagram-audit-section', [
                    'instagramAudit'       => $instagramAudit,
                    'instagramAuditStatus' => $instagramAuditStatus,
                ])

                {{-- ===== Phase 8 BB29: Ulasan Pelanggan (Google Maps) ===== --}}
                @include('livewire._gmaps-reviews-section', ['audit' => (object) [
                    'gmaps_reviews_status' => $gmapsReviewsStatus,
                    'gmaps_reviews'        => $gmapsReviews,
                    'score_breakdown'      => $scoreBreakdown,
                ]])

                {{-- ===== Temuan Utama ===== --}}
                @if (count($keyFindings) > 0)
                    <div class="mb-4">
                        <h3 style="font-size: 18px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px;">Temuan Utama</h3>
                        <p style="font-size: 13px; color: var(--text-secondary);">Hal-hal positif yang perlu dipertahankan dan area yang perlu diperhatikan.</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-12">
                        @foreach ($keyFindings as $finding)
                            @php
                                $impact = is_array($finding) ? ($finding['impact'] ?? 'neutral') : 'neutral';
                                $obs    = is_array($finding) ? ($finding['observation'] ?? '') : (string) $finding;
                                $tp     = is_array($finding) ? ($finding['touchpoint'] ?? null) : null;
                                $isPositive = $impact === 'positive';
                                $borderClr  = $isPositive ? 'var(--color-success)' : 'var(--color-warning)';
                                $tagClr     = $isPositive ? 'var(--chimera-700)' : 'var(--color-warning)';
                                $tagBg      = $isPositive ? 'var(--chimera-50)' : '#FEF3DC';
                                $tagLabel   = $isPositive ? 'Positif' : 'Perlu perhatian';
                                $iconName   = $isPositive ? 'ti-circle-check-filled' : 'ti-alert-triangle-filled';
                            @endphp
                            <x-nui-card padding="none" :class="''">
                                <div style="border-left: 4px solid {{ $borderClr }}; padding: 20px 22px; border-radius: var(--radius-lg);">
                                    <div class="flex items-center gap-2 mb-2">
                                        <i class="ti {{ $iconName }}" style="color: {{ $borderClr }}; font-size: 14px;"></i>
                                        <span style="font-size: 11px; font-weight: 500; color: {{ $tagClr }}; background: {{ $tagBg }}; border-radius: var(--radius-pill); padding: 2px 10px;">
                                            {{ $tagLabel }}
                                        </span>
                                        @if ($tp)
                                            <span style="font-size: 11px; color: var(--text-tertiary); margin-left: auto;">{{ $tp }}</span>
                                        @endif
                                    </div>
                                    <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.6;">{{ $obs }}</p>
                                </div>
                            </x-nui-card>
                        @endforeach
                    </div>
                @endif

                {{-- ===== Ringkasan Brand Health ===== --}}
                @if ($overallScore !== null && count($pillarScoreInts) > 0)
                    @php
                        $summaryPillarArr = $pillarScoreInts;
                        arsort($summaryPillarArr);
                        $highestSlug  = array_key_first($summaryPillarArr) ?? '';
                        $lowestSlug   = array_key_last($summaryPillarArr) ?? '';
                        $highestScore = $pillarScoreInts[$highestSlug] ?? null;
                        $lowestScore  = $pillarScoreInts[$lowestSlug] ?? null;
                        $highestLabel = $pillarMeta[$highestSlug]['label'] ?? $highestSlug;
                        $lowestLabel  = $pillarMeta[$lowestSlug]['label'] ?? $lowestSlug;

                        $summaryTierDescs = [
                            'EXCELLENT' => 'Pertahankan momentum ini dengan konsistensi pada semua touchpoint.',
                            'GOOD'      => 'Beberapa area kunci masih bisa dioptimalkan untuk mencapai level excellent.',
                            'AVERAGE'   => 'Perbaikan sistematis pada area lemah akan memberikan dampak paling besar.',
                            'BELOW AVG' => 'Prioritaskan perbaikan segera pada area dengan gap terbesar.',
                            'CRITICAL'  => 'Mulai dengan membangun kehadiran digital dan konsistensi identitas brand.',
                        ];
                        $summaryTierKey  = trim((string) explode('—', (string) ($overallLabel ?? ''))[0]);
                        $summaryTierDesc = $summaryTierDescs[$summaryTierKey] ?? '';

                        $summaryPriorityOrder = ['tinggi' => 0, 'penting' => 1, 'opsional' => 2];
                        $top3Recs = (array) $recommendations;
                        usort($top3Recs, function (array $a, array $b) use ($summaryPriorityOrder): int {
                            return ($summaryPriorityOrder[$a['priority'] ?? 'opsional'] ?? 2)
                                <=> ($summaryPriorityOrder[$b['priority'] ?? 'opsional'] ?? 2);
                        });
                        $top3Recs = array_slice($top3Recs, 0, 3);
                    @endphp

                    <x-nui-card padding="lg" style="margin-top: 24px;">

                        <h2 style="font-size: 24px; font-weight: 600; color: var(--text-primary); margin-bottom: 24px;">Ringkasan Brand Health</h2>

                        {{-- Three-column metric strip --}}
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6" style="padding-bottom: 24px; border-bottom: 1px solid var(--border-default); margin-bottom: 24px;">
                            <div>
                                <p style="font-size: 12px; color: var(--text-tertiary); margin-bottom: 6px;">Skor Total</p>
                                <div style="display: flex; align-items: baseline; gap: 6px; margin-bottom: 4px;">
                                    <span style="font-size: 40px; font-weight: 700; color: {{ $tierColor($overallScore) }}; line-height: 1;">{{ $overallScore }}</span>
                                    <span style="font-size: 13px; color: var(--text-tertiary);">/100</span>
                                </div>
                                <p style="font-size: 13px; font-weight: 500; color: var(--text-secondary);">{{ $overallLabel }}</p>
                            </div>

                            <div>
                                <p style="font-size: 12px; color: var(--text-tertiary); margin-bottom: 6px;">Kekuatan Utama</p>
                                <p style="font-size: 15px; font-weight: 600; color: var(--chimera-600); margin-bottom: 4px;">{{ $highestLabel }}</p>
                                <p style="font-size: 24px; font-weight: 700; color: var(--chimera-500); line-height: 1;">{{ $highestScore }}<span style="font-size: 13px; font-weight: 400; color: var(--text-tertiary);">/100</span></p>
                            </div>

                            <div>
                                <p style="font-size: 12px; color: var(--text-tertiary); margin-bottom: 6px;">Area Pengembangan</p>
                                <p style="font-size: 15px; font-weight: 600; color: var(--color-warning); margin-bottom: 4px;">{{ $lowestLabel }}</p>
                                <p style="font-size: 24px; font-weight: 700; color: var(--color-warning); line-height: 1;">{{ $lowestScore }}<span style="font-size: 13px; font-weight: 400; color: var(--text-tertiary);">/100</span></p>
                            </div>
                        </div>

                        {{-- Synthesis paragraph --}}
                        <p style="font-size: 14px; color: var(--text-secondary); line-height: 1.75; padding-bottom: 24px; border-bottom: 1px solid var(--border-default); margin-bottom: 24px;">
                            <strong style="color: var(--text-primary);">{{ $brandName }}</strong> menunjukkan profil brand dengan tier <strong>{{ $overallLabel }}</strong>.
                            Kekuatan terbesar berada pada pilar <strong>{{ $highestLabel }}</strong> ({{ $highestScore }}/100),
                            sementara <strong>{{ $lowestLabel }}</strong> ({{ $lowestScore }}/100) menjadi area dengan potensi pengembangan terbesar.
                            @if ($summaryTierDesc) {{ $summaryTierDesc }} @endif
                        </p>

                        {{-- Top 3 priority recommendations --}}
                        @if (count($top3Recs) > 0)
                            <div style="padding-bottom: 24px; border-bottom: 1px solid var(--border-default); margin-bottom: 0;">
                                <h3 style="font-size: 15px; font-weight: 600; color: var(--text-primary); margin-bottom: 16px;">3 Tindakan Prioritas</h3>
                                <ol style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 14px;">
                                    @foreach ($top3Recs as $ri => $rec)
                                        @php
                                            $rp      = $rec['priority'] ?? 'opsional';
                                            $rpClr   = match ($rp) {
                                                'tinggi'  => 'var(--color-danger)',
                                                'penting' => 'var(--color-warning)',
                                                default   => 'var(--text-tertiary)',
                                            };
                                            $rpLabel = match ($rp) {
                                                'tinggi'  => 'Tinggi',
                                                'penting' => 'Penting',
                                                default   => 'Opsional',
                                            };
                                        @endphp
                                        <li style="display: flex; align-items: flex-start; gap: 12px;">
                                            <span style="font-size: 20px; font-weight: 700; color: var(--chimera-500); line-height: 1.2; flex-shrink: 0; width: 24px;">{{ $ri + 1 }}</span>
                                            <div>
                                                <span style="display: inline-block; font-size: 11px; font-weight: 500; color: {{ $rpClr }}; background: var(--surface-muted); border-radius: var(--radius-pill); padding: 2px 10px; border: 1px solid {{ $rpClr }}; margin-bottom: 4px;">{{ $rpLabel }}</span>
                                                <p style="font-size: 14px; font-weight: 500; color: var(--text-primary); margin: 0;">{{ $rec['title'] ?? '' }}</p>
                                            </div>
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        @endif

                        {{-- CTA row --}}
                        <div class="flex justify-between items-center flex-wrap gap-3" style="margin-top: 24px; padding-top: 24px; border-top: 1px solid var(--border-default);">
                            <a href="{{ route('home') }}"
                               style="font-size: 14px; color: var(--chimera-600); text-decoration: none;"
                               onmouseover="this.style.textDecoration='underline'"
                               onmouseout="this.style.textDecoration='none'">
                                ← Analisis brand lain
                            </a>

                            @if ($activationKitPath && $sessionToken)
                                <a
                                    href="{{ route('audit.kit.download', ['token' => $sessionToken]) }}"
                                    class="nui-btn-primary rounded-pill"
                                    style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 24px; font-size: 14px; font-weight: 600;"
                                >
                                    <i class="ti ti-download"></i>
                                    Download Activation Kit (PDF) ↓
                                </a>
                            @elseif ($kitGenerating)
                                <span style="display: inline-flex; align-items: center; gap: 8px; border: 1px solid var(--border-default); border-radius: var(--radius-pill); padding: 10px 24px; font-size: 14px; font-weight: 500; color: var(--text-secondary); background: var(--surface-muted);">
                                    <span style="width: 14px; height: 14px; border: 2px solid var(--chimera-200); border-top-color: var(--chimera-500); border-radius: 50%; display: inline-block; animation: baw-spin .8s linear infinite;"></span>
                                    Membuat activation kit...
                                </span>
                            @else
                                <button
                                    type="button"
                                    wire:click="generateKit"
                                    wire:loading.attr="disabled"
                                    wire:target="generateKit"
                                    class="nui-btn-primary rounded-pill"
                                    style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 24px; font-size: 14px; font-weight: 600;"
                                >
                                    <span wire:loading.remove wire:target="generateKit">
                                        <i class="ti ti-file-text"></i> Download Activation Kit (PDF) ↓
                                    </span>
                                    <span wire:loading wire:target="generateKit">Memulai...</span>
                                </button>
                            @endif
                        </div>

                    </x-nui-card>
                @endif

            @endif
        </div>
    @endif

    {{-- ===== MODAL: RECOMMENDATIONS (kit-driven, only visible when triggered) ===== --}}
    <x-nui-modal name="recommendations" maxWidth="lg">
        @if ($modalPillar)
            <div class="flex items-start justify-between" style="margin-bottom: 20px;">
                <div>
                    <p style="font-size: 12px; color: var(--text-tertiary); margin-bottom: 2px;">Rekomendasi</p>
                    <h3 style="font-size: 18px; font-weight: 600; color: var(--text-primary);">
                        {{ $pillarMeta[$modalPillar]['label'] ?? $modalPillar }}
                    </h3>
                </div>
                <button
                    type="button"
                    @click="$dispatch('close-modal-recommendations'); $wire.closeModal()"
                    style="width: 32px; height: 32px; border-radius: var(--radius-sm); border: 1px solid var(--border-default); background: none; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--text-secondary);"
                    aria-label="Tutup"
                >
                    <i class="ti ti-x" style="font-size: 16px;"></i>
                </button>
            </div>

            @if (count($modalRecs) === 0)
                <p style="font-size: 14px; color: var(--text-secondary);">Tidak ada rekomendasi spesifik untuk pilar ini.</p>
            @else
                <div class="flex flex-col gap-5">
                    @foreach ($modalRecs as $i => $rec)
                        @php
                            $prio      = $rec['priority'] ?? 'opsional';
                            $prioClr   = match ($prio) {
                                'tinggi'  => 'var(--color-danger)',
                                'penting' => 'var(--color-warning)',
                                default   => 'var(--text-tertiary)',
                            };
                            $prioLabel = match ($prio) {
                                'tinggi'  => 'Prioritas Tinggi',
                                'penting' => 'Penting',
                                default   => 'Opsional',
                            };
                            $bucketLabel = $subBucketLabels[$rec['bucket'] ?? ''] ?? ($rec['bucket'] ?? '');
                        @endphp
                        <div @if ($i < count($modalRecs) - 1) style="padding-bottom: 20px; border-bottom: 1px solid var(--border-default);" @endif>
                            <div class="flex items-center gap-2" style="margin-bottom: 8px; flex-wrap: wrap;">
                                <span style="font-size: 11px; font-weight: 500; color: {{ $prioClr }}; background: var(--surface-muted); border-radius: var(--radius-pill); padding: 2px 10px; border: 1px solid {{ $prioClr }};">
                                    {{ $prioLabel }}
                                </span>
                                @if ($bucketLabel)
                                    <span style="font-size: 11px; color: var(--text-tertiary);">{{ $bucketLabel }}</span>
                                @endif
                                @if (isset($rec['gap']))
                                    <span style="font-size: 11px; color: var(--text-tertiary);">· gap {{ $rec['gap'] }} pt</span>
                                @endif
                            </div>
                            <p style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 6px;">{{ $rec['title'] ?? '' }}</p>
                            <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.65;">{{ $rec['body'] ?? '' }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </x-nui-modal>

</div>
