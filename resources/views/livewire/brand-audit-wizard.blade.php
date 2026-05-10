<?php

declare(strict_types=1);

use App\Jobs\AnalyzeBrand;
use App\Models\BrandAudit;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use function Livewire\Volt\layout;

layout('layouts.app');

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
    public ?string $auditId         = null;
    public string  $auditStatus     = '';
    public ?int    $overallScore    = null;
    public ?string $overallLabel    = null;
    public array   $pillarScores    = [];
    public array   $subBucketScores = [];
    public array   $keyFindings     = [];
    public array   $recommendations = [];
    public ?string $errorMessage    = null;

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
        $this->auditId         = $audit->id;
        $this->auditStatus     = $audit->status;
        $this->brandName       = $audit->brand_name ?? '';
        $this->overallScore    = $audit->overall_score;
        $this->overallLabel    = $audit->overall_label;
        $this->pillarScores    = $audit->pillar_scores ?? [];
        $this->subBucketScores = $audit->sub_bucket_scores ?? [];
        $this->keyFindings     = $audit->key_findings ?? [];
        $this->recommendations = $audit->recommendations ?? [];
        $this->errorMessage    = $audit->error_message;

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
        $this->showModal   = true;
    }

    public function closeModal(): void
    {
        $this->showModal   = false;
        $this->modalPillar = null;
    }

    public function with(): array
    {
        $pillarMeta = [
            'brand-recall'      => ['label' => 'Brand Recall',      'icon' => 'ti-message-star'],
            'digital-presence'  => ['label' => 'Digital Presence',  'icon' => 'ti-world'],
            'brand-konsistensi' => ['label' => 'Konsistensi Brand', 'icon' => 'ti-layers-intersect'],
            'brand-experience'  => ['label' => 'Brand Experience',  'icon' => 'ti-users'],
        ];

        $subBucketLabels = [
            // brand-recall
            'rating_tier'         => 'Rating',
            'review_count_tier'   => 'Jumlah Review',
            'keyword_saturation'  => 'Kata Kunci',
            'sentiment_quality'   => 'Sentimen',
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
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
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

    {{-- ===== STEP 2: ANALYZING ===== --}}
    @if ($step === 'analyzing')
        <div @if (! in_array($auditStatus, ['done', 'failed'])) wire:poll.3000ms="pollStatus" @endif>
            <div class="max-w-md mx-auto text-center py-16">
                <div class="flex justify-center mb-6">
                    <div class="baw-spinner"></div>
                </div>
                <h2 style="font-size: 22px; font-weight: 600; color: var(--text-primary);">
                    Menganalisis brand <em>{{ $brandName }}</em>...
                </h2>
                <p style="font-size: 14px; color: var(--text-secondary); margin-top: 8px;">
                    Proses ini memakan 30–60 detik. Halaman ini otomatis diperbarui.
                </p>
                <div class="mt-10 flex flex-col gap-3 text-left max-w-xs mx-auto">
                    @foreach ([
                        'Mengambil ulasan Google Maps',
                        'Menganalisis kehadiran digital',
                        'Menghitung Brand Recall & Experience',
                        'Menyusun rekomendasi',
                    ] as $lbl)
                        <div class="flex items-center gap-3" style="font-size: 13px; color: var(--text-secondary);">
                            <span class="baw-dot"></span>
                            {{ $lbl }}
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- ===== STEP 3: DASHBOARD ===== --}}
    @if ($step === 'dashboard')
        @php
            $circ      = 263.89;
            $filled    = $overallScore ? round(($overallScore / 100) * $circ, 2) : 0;
            $scoreClr  = match (true) {
                ($overallScore ?? 0) >= 80 => 'var(--chimera-500)',
                ($overallScore ?? 0) >= 60 => 'var(--color-warning)',
                default                    => 'var(--color-danger)',
            };
        @endphp

        <div class="max-w-4xl mx-auto">

            {{-- Header --}}
            <div class="flex items-start justify-between mb-8 flex-wrap gap-4">
                <div>
                    <p style="font-size: 13px; color: var(--text-tertiary); margin-bottom: 4px;">Hasil Brand Health Check</p>
                    <h2 style="font-size: 26px; font-weight: 600; color: var(--text-primary);">{{ $brandName }}</h2>
                    @if ($overallLabel)
                        <p style="font-size: 14px; color: var(--text-secondary); margin-top: 4px;">{{ $overallLabel }}</p>
                    @endif
                </div>
                <div class="flex items-center gap-3 flex-wrap">
                    <a href="{{ route('home') }}" style="font-size: 13px; color: var(--chimera-600); text-decoration: underline;">
                        Analisis brand lain
                    </a>
                    <button
                        disabled
                        title="Segera hadir"
                        style="display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--border-default); border-radius: var(--radius-pill); padding: 8px 16px; font-size: 13px; font-weight: 500; color: var(--text-tertiary); background: var(--surface-muted); cursor: not-allowed; opacity: 0.65;"
                    >
                        <i class="ti ti-file-text"></i>
                        Buat Activation Kit
                        <span style="font-size: 10px; background: var(--chimera-50); color: var(--chimera-700); border-radius: var(--radius-pill); padding: 1px 7px; margin-left: 2px;">Segera</span>
                    </button>
                </div>
            </div>

            @if ($auditStatus === 'failed')
                <div class="nui-card p-6" style="border-left: 3px solid var(--color-danger);">
                    <p style="font-weight: 500; color: var(--color-danger); margin-bottom: 4px;">Analisis gagal</p>
                    @if ($errorMessage)
                        <p style="font-size: 13px; color: var(--text-secondary);">{{ $errorMessage }}</p>
                    @endif
                    <a href="{{ route('home') }}" style="font-size: 13px; color: var(--chimera-600); text-decoration: underline; margin-top: 12px; display: inline-block;">
                        Coba lagi
                    </a>
                </div>
            @else

                {{-- Overall score card --}}
                <div class="nui-card p-8 mb-6">
                    <div class="flex flex-col sm:flex-row items-center gap-8">
                        <div class="flex-shrink-0" style="position: relative; width: 140px; height: 140px;">
                            <svg viewBox="0 0 100 100" style="width: 140px; height: 140px;">
                                <circle cx="50" cy="50" r="42" fill="none" stroke="var(--chimera-50)" stroke-width="8"/>
                                <circle
                                    cx="50" cy="50" r="42"
                                    fill="none"
                                    stroke="{{ $scoreClr }}"
                                    stroke-width="8"
                                    stroke-dasharray="{{ $filled }} {{ $circ }}"
                                    stroke-linecap="round"
                                    transform="rotate(-90 50 50)"
                                />
                            </svg>
                            <div style="position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                                <span style="font-size: 32px; font-weight: 700; color: {{ $scoreClr }}; line-height: 1;">{{ $overallScore ?? '—' }}</span>
                                <span style="font-size: 12px; color: var(--text-tertiary);">dari 100</span>
                            </div>
                        </div>

                        <div class="flex-1">
                            <p style="font-size: 13px; color: var(--text-tertiary); margin-bottom: 4px;">Skor Keseluruhan</p>
                            <p style="font-size: 22px; font-weight: 600; color: var(--text-primary); margin-bottom: 12px;">
                                {{ $overallLabel ?? '—' }}
                            </p>
                            @if (count($keyFindings) > 0)
                                <div class="flex flex-col gap-2">
                                    @foreach (array_slice($keyFindings, 0, 3) as $finding)
                                        @php
                                            $impact = is_array($finding) ? ($finding['impact'] ?? 'neutral') : 'neutral';
                                            $obs    = is_array($finding) ? ($finding['observation'] ?? '') : (string) $finding;
                                            $iconClr = match ($impact) {
                                                'positive' => 'var(--chimera-500)',
                                                'negative' => 'var(--color-danger)',
                                                default    => 'var(--text-tertiary)',
                                            };
                                            $icon = match ($impact) {
                                                'positive' => 'ti-circle-check-filled',
                                                'negative' => 'ti-alert-circle-filled',
                                                default    => 'ti-point-filled',
                                            };
                                        @endphp
                                        <div class="flex items-start gap-2" style="font-size: 13px; color: var(--text-secondary);">
                                            <i class="ti {{ $icon }}" style="color: {{ $iconClr }}; font-size: 13px; margin-top: 2px; flex-shrink: 0;"></i>
                                            <span>{{ $obs }}</span>
                                        </div>
                                    @endforeach
                                    @if (count($keyFindings) > 3)
                                        <p style="font-size: 12px; color: var(--text-tertiary);">+{{ count($keyFindings) - 3 }} temuan lainnya di bawah</p>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Pillar cards --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                    @foreach ($pillarMeta as $slug => $meta)
                        @php
                            $ps = $pillarScoreInts[$slug] ?? null;
                            $pc = match (true) {
                                ($ps ?? 0) >= 80 => 'var(--chimera-500)',
                                ($ps ?? 0) >= 60 => 'var(--color-warning)',
                                default          => 'var(--color-danger)',
                            };
                            $sbs = $subBucketScores[$slug] ?? [];
                            $hasRecs = ($recsByPillar[$slug] ?? 0) > 0;
                        @endphp
                        <div class="nui-card p-6">
                            <div class="flex items-center justify-between mb-4">
                                <div class="flex items-center gap-2">
                                    <div style="width: 32px; height: 32px; border-radius: var(--radius-sm); background: var(--chimera-50); display: flex; align-items: center; justify-content: center;">
                                        <i class="ti {{ $meta['icon'] }}" style="color: var(--chimera-500); font-size: 16px;"></i>
                                    </div>
                                    <span style="font-size: 14px; font-weight: 600; color: var(--text-primary);">{{ $meta['label'] }}</span>
                                </div>
                                <span style="font-size: 24px; font-weight: 700; color: {{ $pc }};">{{ $ps ?? '—' }}</span>
                            </div>

                            <div style="height: 6px; background: var(--chimera-50); border-radius: 999px; margin-bottom: 16px;">
                                <div style="height: 100%; width: {{ $ps ?? 0 }}%; background: {{ $pc }}; border-radius: 999px;"></div>
                            </div>

                            @if (count($sbs) > 0)
                                <div class="flex flex-col gap-1.5 mb-4">
                                    @foreach ($sbs as $k => $v)
                                        <div class="flex justify-between items-center">
                                            <span style="font-size: 12px; color: var(--text-secondary);">{{ $subBucketLabels[$k] ?? $k }}</span>
                                            <span style="font-size: 12px; font-weight: 500; color: var(--text-primary);">{{ $v }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if ($hasRecs)
                                <button
                                    wire:click="openModal('{{ $slug }}')"
                                    style="font-size: 12px; color: var(--chimera-600); text-decoration: underline; background: none; border: none; cursor: pointer; padding: 0;"
                                >
                                    Lihat rekomendasi <i class="ti ti-arrow-right" style="font-size: 11px;"></i>
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if (count($keyFindings) > 3)
                    <div class="nui-card p-6">
                        <p style="font-size: 14px; font-weight: 600; margin-bottom: 12px; color: var(--text-primary);">Semua Temuan</p>
                        <div class="flex flex-col gap-3">
                            @foreach ($keyFindings as $finding)
                                @php
                                    $impact = is_array($finding) ? ($finding['impact'] ?? 'neutral') : 'neutral';
                                    $obs    = is_array($finding) ? ($finding['observation'] ?? '') : (string) $finding;
                                    $tp     = is_array($finding) ? ($finding['touchpoint'] ?? null) : null;
                                    $iconClr = match ($impact) {
                                        'positive' => 'var(--chimera-500)',
                                        'negative' => 'var(--color-danger)',
                                        default    => 'var(--text-tertiary)',
                                    };
                                    $icon = match ($impact) {
                                        'positive' => 'ti-circle-check-filled',
                                        'negative' => 'ti-alert-circle-filled',
                                        default    => 'ti-point-filled',
                                    };
                                @endphp
                                <div class="flex items-start gap-2" style="font-size: 13px; color: var(--text-secondary);">
                                    <i class="ti {{ $icon }}" style="color: {{ $iconClr }}; font-size: 13px; margin-top: 2px; flex-shrink: 0;"></i>
                                    <div>
                                        <span>{{ $obs }}</span>
                                        @if ($tp)
                                            <span style="font-size: 11px; color: var(--text-tertiary); margin-left: 6px;">[{{ $tp }}]</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

            @endif
        </div>
    @endif

    {{-- ===== MODAL: RECOMMENDATIONS ===== --}}
    @if ($showModal && $modalPillar)
        <div
            style="position: fixed; inset: 0; background: rgba(15,20,17,0.5); z-index: 50; display: flex; align-items: center; justify-content: center; padding: 16px;"
            wire:click.self="closeModal"
        >
            <div style="background: var(--surface-card); border-radius: var(--radius-xl); max-width: 520px; width: 100%; max-height: 80vh; overflow-y: auto; box-shadow: var(--shadow-popover);">

                <div style="padding: 24px; border-bottom: 1px solid var(--border-default); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: var(--surface-card); border-radius: var(--radius-xl) var(--radius-xl) 0 0;">
                    <div>
                        <p style="font-size: 12px; color: var(--text-tertiary); margin-bottom: 2px;">Rekomendasi</p>
                        <h3 style="font-size: 17px; font-weight: 600; color: var(--text-primary);">
                            {{ $pillarMeta[$modalPillar]['label'] ?? $modalPillar }}
                        </h3>
                    </div>
                    <button
                        wire:click="closeModal"
                        style="width: 32px; height: 32px; border-radius: var(--radius-sm); border: 1px solid var(--border-default); background: none; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--text-secondary);"
                    >
                        <i class="ti ti-x" style="font-size: 16px;"></i>
                    </button>
                </div>

                <div style="padding: 24px;">
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
                                        <span style="font-size: 11px; font-weight: 500; color: {{ $prioClr }}; background: var(--surface-muted); border-radius: var(--radius-pill); padding: 2px 8px; border: 1px solid {{ $prioClr }};">
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
                </div>

            </div>
        </div>
    @endif

</div>
