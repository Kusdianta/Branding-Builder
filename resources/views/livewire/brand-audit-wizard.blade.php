<?php

declare(strict_types=1);

use App\Jobs\AnalyzeBrand;
use App\Models\BrandAudit;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use function Livewire\Volt\layout;

layout('layouts.app');

new class extends Component {
    public string $step = 'touchpoint_inputs';

    public string $brandName     = '';
    public string $city          = '';
    public string $serviceType   = 'kiloan';
    public string $instagramUrl  = '';
    public string $websiteUrl    = '';
    public string $googleMapsUrl = '';

    public ?string $auditId         = null;
    public string  $auditStatus     = '';
    public ?int    $overallScore    = null;
    public ?string $overallLabel    = null;
    public array   $pillarScores    = [];
    public array   $subBucketScores = [];
    public array   $keyFindings     = [];
    public array   $recommendations = [];
    public ?string $errorMessage    = null;

    public bool    $showModal   = false;
    public ?string $modalPillar = null;

    protected array $rules = [
        'brandName'     => 'required|string|max:100',
        'city'          => 'required|string|max:100',
        'serviceType'   => 'required|string|in:kiloan,satuan,express,premium,mixed',
        'googleMapsUrl' => 'required|url',
        'instagramUrl'  => 'nullable|url',
        'websiteUrl'    => 'nullable|url',
    ];

    protected array $messages = [
        'brandName.required'     => 'Nama brand wajib diisi.',
        'city.required'          => 'Kota wajib diisi.',
        'googleMapsUrl.required' => 'URL Google Maps wajib diisi.',
        'googleMapsUrl.url'      => 'Format URL tidak valid.',
        'instagramUrl.url'       => 'Format URL Instagram tidak valid.',
        'websiteUrl.url'         => 'Format URL website tidak valid.',
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

    public function submit(): void
    {
        $this->validate();

        $touchpoints = ['google_maps' => $this->googleMapsUrl];
        if ($this->instagramUrl) {
            $touchpoints['instagram'] = $this->instagramUrl;
        }
        if ($this->websiteUrl) {
            $touchpoints['website'] = $this->websiteUrl;
        }

        $token = Str::random(64);

        $audit = BrandAudit::create([
            'session_token' => $token,
            'ip_address'    => request()->ip(),
            'brand_name'    => $this->brandName,
            'city'          => $this->city,
            'service_type'  => $this->serviceType,
            'touchpoints'   => $touchpoints,
            'status'        => 'pending',
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
            'rating_tier'         => 'Rating',
            'review_count_tier'   => 'Jumlah Review',
            'keyword_saturation'  => 'Kata Kunci',
            'sentiment_quality'   => 'Sentimen',
            'kehadiran_digital'   => 'Kehadiran Digital',
            'konsistensi_visual'  => 'Visual',
            'kelengkapan_layanan' => 'Kelengkapan Layanan',
            'transparansi_harga'  => 'Transparansi Harga',
            'base'                => 'Dasar',
            'ekspres'             => 'Ekspres',
            'antar_jemput'        => 'Antar Jemput',
            'variasi'             => 'Variasi',
            'price_list'          => 'Daftar Harga',
            'sop_keluhan'         => 'Penanganan Keluhan',
        ];

        $modalRecs = $this->modalPillar
            ? array_values(array_filter(
                $this->recommendations,
                fn ($r) => ($r['pillar'] ?? '') === $this->modalPillar,
            ))
            : [];

        return compact('pillarMeta', 'subBucketLabels', 'modalRecs') + [
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
        .baw-lbl { font-size: 13px; font-weight: 500; margin-bottom: 6px; display: block; color: var(--text-primary); }
    </style>

    {{-- ===== STEP 1: FORM ===== --}}
    @if ($step === 'touchpoint_inputs')
        <div class="max-w-2xl mx-auto">
            <div class="mb-8">
                <h1 style="font-size: 28px; font-weight: 600; color: var(--text-primary);">Brand Health Check</h1>
                <p style="font-size: 15px; color: var(--text-secondary); margin-top: 8px; line-height: 1.6;">
                    Masukkan informasi dan touchpoint brand laundry Anda. Saya akan menganalisis 4 pilar kekuatan brand dalam 30–60 detik.
                </p>
            </div>

            <div class="nui-card p-8">
                <form wire:submit="submit" class="flex flex-col gap-6">

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="baw-lbl">
                                Nama Brand <span style="color:var(--color-danger)">*</span>
                            </label>
                            <input
                                type="text"
                                wire:model="brandName"
                                placeholder="Contoh: Less Worry Laundry"
                                class="nui-input w-full"
                                autocomplete="organization"
                            />
                            @error('brandName') <p class="baw-err">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="baw-lbl">
                                Kota / Area <span style="color:var(--color-danger)">*</span>
                            </label>
                            <input
                                type="text"
                                wire:model="city"
                                placeholder="Contoh: Jakarta Selatan"
                                class="nui-input w-full"
                                autocomplete="address-level2"
                            />
                            @error('city') <p class="baw-err">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="baw-lbl">Jenis Layanan Utama</label>
                        <select wire:model="serviceType" class="nui-input w-full">
                            @foreach ($serviceTypes as $val => $lbl)
                                <option value="{{ $val }}">{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <p style="font-size: 14px; font-weight: 600; margin-bottom: 4px; color: var(--text-primary);">Touchpoint Digital</p>
                        <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 16px;">
                            Isi URL yang tersedia. Google Maps wajib diisi, yang lain opsional.
                        </p>

                        <div class="flex flex-col gap-4">
                            <div>
                                <label class="baw-lbl">
                                    <i class="ti ti-map-pin" style="color:var(--chimera-500);"></i>
                                    Google Maps <span style="color:var(--color-danger)">*</span>
                                </label>
                                <input
                                    type="url"
                                    wire:model="googleMapsUrl"
                                    placeholder="https://maps.app.goo.gl/..."
                                    class="nui-input w-full"
                                />
                                @error('googleMapsUrl') <p class="baw-err">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="baw-lbl">
                                    <i class="ti ti-brand-instagram" style="color:var(--color-info);"></i>
                                    Instagram
                                </label>
                                <input
                                    type="url"
                                    wire:model="instagramUrl"
                                    placeholder="https://instagram.com/laundry_anda"
                                    class="nui-input w-full"
                                />
                                @error('instagramUrl') <p class="baw-err">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="baw-lbl">
                                    <i class="ti ti-world" style="color:var(--text-tertiary);"></i>
                                    Website
                                </label>
                                <input
                                    type="url"
                                    wire:model="websiteUrl"
                                    placeholder="https://laundryanda.com"
                                    class="nui-input w-full"
                                />
                                @error('websiteUrl') <p class="baw-err">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>

                    <div class="flex items-start gap-3 p-4 rounded-lg" style="background:var(--chimera-50);border:1px solid var(--chimera-100);">
                        <i class="ti ti-info-circle" style="color:var(--chimera-600);font-size:16px;margin-top:2px;flex-shrink:0;"></i>
                        <p style="font-size:13px;color:var(--chimera-700);">
                            Saya menggunakan data publik dari Google Maps dan Instagram. Tidak ada data sensitif yang disimpan.
                        </p>
                    </div>

                    <div class="flex justify-end">
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            class="nui-btn-primary px-8 py-3 rounded-pill"
                            style="font-size:15px;font-weight:500;"
                        >
                            <span wire:loading.remove wire:target="submit">
                                Analisis Brand <i class="ti ti-sparkles"></i>
                            </span>
                            <span wire:loading wire:target="submit">Memulai analisis...</span>
                        </button>
                    </div>

                </form>
            </div>
        </div>
    @endif

    {{-- ===== STEP 2: ANALYZING ===== --}}
    @if ($step === 'analyzing')
        <div @if (!in_array($auditStatus, ['done', 'failed'])) wire:poll.3000ms="pollStatus" @endif>
            <div class="max-w-md mx-auto text-center py-16">
                <div class="flex justify-center mb-6">
                    <div class="baw-spinner"></div>
                </div>
                <h2 style="font-size:22px;font-weight:600;color:var(--text-primary);">
                    Menganalisis brand <em>{{ $brandName }}</em>...
                </h2>
                <p style="font-size:14px;color:var(--text-secondary);margin-top:8px;">
                    Proses ini memakan 30–60 detik. Halaman ini otomatis diperbarui.
                </p>
                <div class="mt-10 flex flex-col gap-3 text-left max-w-xs mx-auto">
                    @foreach ([
                        'Mengambil ulasan Google Maps',
                        'Menganalisis kehadiran digital',
                        'Menghitung Brand Recall & Experience',
                        'Menyusun rekomendasi',
                    ] as $lbl)
                        <div class="flex items-center gap-3" style="font-size:13px;color:var(--text-secondary);">
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
            $scoreClr  = match(true) {
                ($overallScore ?? 0) >= 80 => 'var(--chimera-500)',
                ($overallScore ?? 0) >= 60 => 'var(--color-warning)',
                default                    => 'var(--color-danger)',
            };
        @endphp

        <div class="max-w-4xl mx-auto">

            {{-- Header --}}
            <div class="flex items-start justify-between mb-8 flex-wrap gap-4">
                <div>
                    <p style="font-size:13px;color:var(--text-tertiary);margin-bottom:4px;">Hasil Brand Health Check</p>
                    <h2 style="font-size:26px;font-weight:600;color:var(--text-primary);">{{ $brandName }}</h2>
                    @if ($overallLabel)
                        <p style="font-size:14px;color:var(--text-secondary);margin-top:4px;">{{ $overallLabel }}</p>
                    @endif
                </div>
                <div class="flex items-center gap-3 flex-wrap">
                    <a href="{{ route('home') }}" style="font-size:13px;color:var(--chimera-600);text-decoration:underline;">
                        Analisis brand lain
                    </a>
                    <button
                        disabled
                        title="Segera hadir"
                        style="display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border-default);border-radius:var(--radius-pill);padding:8px 16px;font-size:13px;font-weight:500;color:var(--text-tertiary);background:var(--surface-muted);cursor:not-allowed;opacity:0.65;"
                    >
                        <i class="ti ti-file-text"></i>
                        Buat Activation Kit
                        <span style="font-size:10px;background:var(--chimera-50);color:var(--chimera-700);border-radius:var(--radius-pill);padding:1px 7px;margin-left:2px;">Segera</span>
                    </button>
                </div>
            </div>

            @if ($auditStatus === 'failed')
                <div class="nui-card p-6" style="border-left:3px solid var(--color-danger);">
                    <p style="font-weight:500;color:var(--color-danger);margin-bottom:4px;">Analisis gagal</p>
                    @if ($errorMessage)
                        <p style="font-size:13px;color:var(--text-secondary);">{{ $errorMessage }}</p>
                    @endif
                    <a href="{{ route('home') }}" style="font-size:13px;color:var(--chimera-600);text-decoration:underline;margin-top:12px;display:inline-block;">
                        Coba lagi
                    </a>
                </div>
            @else

                {{-- Overall score card --}}
                <div class="nui-card p-8 mb-6">
                    <div class="flex flex-col sm:flex-row items-center gap-8">
                        <div class="flex-shrink-0" style="position:relative;width:140px;height:140px;">
                            <svg viewBox="0 0 100 100" style="width:140px;height:140px;">
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
                            <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;">
                                <span style="font-size:32px;font-weight:700;color:{{ $scoreClr }};line-height:1;">{{ $overallScore ?? '—' }}</span>
                                <span style="font-size:12px;color:var(--text-tertiary);">dari 100</span>
                            </div>
                        </div>

                        <div class="flex-1">
                            <p style="font-size:13px;color:var(--text-tertiary);margin-bottom:4px;">Skor Keseluruhan</p>
                            <p style="font-size:22px;font-weight:600;color:var(--text-primary);margin-bottom:12px;">
                                {{ $overallLabel ?? '—' }}
                            </p>
                            @if (count($keyFindings) > 0)
                                <div class="flex flex-col gap-2">
                                    @foreach (array_slice($keyFindings, 0, 3) as $finding)
                                        <div class="flex items-start gap-2" style="font-size:13px;color:var(--text-secondary);">
                                            <i class="ti ti-point-filled" style="color:var(--chimera-400);font-size:10px;margin-top:4px;flex-shrink:0;"></i>
                                            {{ $finding }}
                                        </div>
                                    @endforeach
                                    @if (count($keyFindings) > 3)
                                        <p style="font-size:12px;color:var(--text-tertiary);">+{{ count($keyFindings) - 3 }} temuan lainnya</p>
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
                            $ps  = $pillarScores[$slug] ?? null;
                            $pc  = match(true) {
                                ($ps ?? 0) >= 80 => 'var(--chimera-500)',
                                ($ps ?? 0) >= 60 => 'var(--color-warning)',
                                default          => 'var(--color-danger)',
                            };
                            $sbs = $subBucketScores[$slug] ?? [];
                            $hasRecs = count(array_filter($recommendations, fn($r) => ($r['pillar'] ?? '') === $slug)) > 0;
                        @endphp
                        <div class="nui-card p-6">
                            <div class="flex items-center justify-between mb-4">
                                <div class="flex items-center gap-2">
                                    <div style="width:32px;height:32px;border-radius:var(--radius-sm);background:var(--chimera-50);display:flex;align-items:center;justify-content:center;">
                                        <i class="ti {{ $meta['icon'] }}" style="color:var(--chimera-500);font-size:16px;"></i>
                                    </div>
                                    <span style="font-size:14px;font-weight:600;color:var(--text-primary);">{{ $meta['label'] }}</span>
                                </div>
                                <span style="font-size:24px;font-weight:700;color:{{ $pc }};">{{ $ps ?? '—' }}</span>
                            </div>

                            <div style="height:6px;background:var(--chimera-50);border-radius:999px;margin-bottom:16px;">
                                <div style="height:100%;width:{{ $ps ?? 0 }}%;background:{{ $pc }};border-radius:999px;"></div>
                            </div>

                            @if (count($sbs) > 0)
                                <div class="flex flex-col gap-1.5 mb-4">
                                    @foreach ($sbs as $k => $v)
                                        <div class="flex justify-between items-center">
                                            <span style="font-size:12px;color:var(--text-secondary);">{{ $subBucketLabels[$k] ?? $k }}</span>
                                            <span style="font-size:12px;font-weight:500;color:var(--text-primary);">{{ $v }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if ($hasRecs)
                                <button
                                    wire:click="openModal('{{ $slug }}')"
                                    style="font-size:12px;color:var(--chimera-600);text-decoration:underline;background:none;border:none;cursor:pointer;padding:0;"
                                >
                                    Lihat rekomendasi <i class="ti ti-arrow-right" style="font-size:11px;"></i>
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- All key findings (if more than 3) --}}
                @if (count($keyFindings) > 3)
                    <div class="nui-card p-6">
                        <p style="font-size:14px;font-weight:600;margin-bottom:12px;color:var(--text-primary);">Semua Temuan</p>
                        <div class="flex flex-col gap-3">
                            @foreach ($keyFindings as $finding)
                                <div class="flex items-start gap-2" style="font-size:13px;color:var(--text-secondary);">
                                    <i class="ti ti-point-filled" style="color:var(--chimera-400);font-size:10px;margin-top:5px;flex-shrink:0;"></i>
                                    {{ $finding }}
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
            style="position:fixed;inset:0;background:rgba(15,20,17,0.5);z-index:50;display:flex;align-items:center;justify-content:center;padding:16px;"
            wire:click.self="closeModal"
        >
            <div style="background:var(--surface-card);border-radius:var(--radius-xl);max-width:520px;width:100%;max-height:80vh;overflow-y:auto;box-shadow:var(--shadow-popover);">

                <div style="padding:24px;border-bottom:1px solid var(--border-default);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:var(--surface-card);border-radius:var(--radius-xl) var(--radius-xl) 0 0;">
                    <div>
                        <p style="font-size:12px;color:var(--text-tertiary);margin-bottom:2px;">Rekomendasi</p>
                        <h3 style="font-size:17px;font-weight:600;color:var(--text-primary);">
                            {{ $pillarMeta[$modalPillar]['label'] ?? $modalPillar }}
                        </h3>
                    </div>
                    <button
                        wire:click="closeModal"
                        style="width:32px;height:32px;border-radius:var(--radius-sm);border:1px solid var(--border-default);background:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-secondary);"
                    >
                        <i class="ti ti-x" style="font-size:16px;"></i>
                    </button>
                </div>

                <div style="padding:24px;">
                    @if (count($modalRecs) === 0)
                        <p style="font-size:14px;color:var(--text-secondary);">Tidak ada rekomendasi spesifik untuk pilar ini.</p>
                    @else
                        <div class="flex flex-col gap-5">
                            @foreach ($modalRecs as $i => $rec)
                                @php
                                    $prio      = $rec['priority'] ?? 'medium';
                                    $prioClr   = match($prio) { 'high' => 'var(--color-danger)', 'medium' => 'var(--color-warning)', default => 'var(--text-tertiary)' };
                                    $prioLabel = match($prio) { 'high' => 'Prioritas Tinggi', 'medium' => 'Prioritas Sedang', default => 'Prioritas Rendah' };
                                @endphp
                                <div @if ($i < count($modalRecs) - 1) style="padding-bottom:20px;border-bottom:1px solid var(--border-default);" @endif>
                                    <div style="margin-bottom:8px;">
                                        <span style="font-size:11px;font-weight:500;color:{{ $prioClr }};background:var(--surface-muted);border-radius:var(--radius-pill);padding:2px 8px;border:1px solid {{ $prioClr }};">
                                            {{ $prioLabel }}
                                        </span>
                                    </div>
                                    <p style="font-size:14px;font-weight:600;color:var(--text-primary);margin-bottom:6px;">{{ $rec['title'] ?? '' }}</p>
                                    <p style="font-size:13px;color:var(--text-secondary);line-height:1.65;">{{ $rec['body'] ?? '' }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

            </div>
        </div>
    @endif

</div>
