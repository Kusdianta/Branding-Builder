<?php

declare(strict_types=1);

use App\Models\BrandAudit;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use function Livewire\Volt\layout;

layout('layouts.audit');

new class extends Component {
    use WithPagination;

    /**
     * @return array{audits: LengthAwarePaginator<BrandAudit>}
     */
    public function with(): array
    {
        return [
            'audits' => BrandAudit::query()
                ->where('user_id', Auth::id())
                ->orderByDesc('created_at')
                ->paginate(20),
        ];
    }

    /**
     * Permanently delete one of the current user's own audits.
     *
     * Scoped to Auth::id() so a user can never delete another user's
     * audit by guessing an ULID. Related brand_kits and audit_steps
     * rows cascade at the DB level; the per-audit asset directory
     * (activation kit PDF, GMaps screenshot, Instagram assets) is
     * removed here since the filesystem does not cascade.
     */
    public function deleteAudit(string $id): void
    {
        $audit = BrandAudit::query()
            ->where('user_id', Auth::id())
            ->whereKey($id)
            ->first();

        if ($audit === null) {
            return;
        }

        Storage::disk('local')->deleteDirectory("audits/{$audit->id}");

        $audit->delete();

        // Keep pagination valid if the last item on a page was removed.
        $this->resetPage();
    }
};
?>

<section class="max-w-4xl mx-auto py-8">
    <div class="mb-8 flex items-start justify-between gap-4 flex-wrap">
        <div>
            <h1 style="font-size: 30px; font-weight: 600; color: var(--text-primary); letter-spacing: -0.01em;">
                Riwayat Audit Anda
            </h1>
            <p style="font-size: 14px; color: var(--text-secondary); margin-top: 6px;">
                Klik audit untuk melihat hasil lengkap. Audit yang sudah selesai bisa dibuka kapan saja.
            </p>
        </div>
        @auth
            <div style="display: inline-flex; align-items: center; gap: 12px;">
                <div style="text-align: right;">
                    <div style="font-size: 11px; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.04em;">Sisa Kredit</div>
                    <div style="font-size: 22px; font-weight: 600; color: var(--chimera-700); line-height: 1;">{{ (int) auth()->user()->credits_balance }}</div>
                </div>
                @if ($audits->total() > 0)
                    <a href="{{ route('home') }}" wire:navigate class="nui-btn-primary rounded-pill" style="font-size: 13px; padding: 10px 18px;">
                        <i class="ti ti-plus" style="font-size: 13px;"></i>
                        Audit baru
                    </a>
                @endif
            </div>
        @endauth
    </div>

    @if ($audits->total() === 0)
        <div class="nui-card p-12 flex flex-col items-center text-center gap-4">
            <div style="width: 72px; height: 72px; border-radius: 50%; background: var(--chimera-50); display: flex; align-items: center; justify-content: center;">
                <i class="ti ti-clipboard-list" style="font-size: 36px; color: var(--chimera-600);"></i>
            </div>
            <div>
                <h2 style="font-size: 18px; font-weight: 600; color: var(--text-primary);">Belum ada audit</h2>
                <p style="font-size: 14px; color: var(--text-secondary); margin-top: 6px; max-width: 360px;">
                    Mulai audit pertama Anda dan saya akan menganalisis 4 pilar brand laundry dalam 30–60 detik.
                </p>
            </div>
            <a href="{{ route('home') }}" class="nui-btn-primary rounded-pill" style="font-size: 14px; padding: 10px 20px; margin-top: 4px;">
                Mulai Audit Pertama
            </a>
        </div>
    @else
        <style>
            .nui-audit-card-wrap { position: relative; }
            .nui-audit-delete {
                position: absolute;
                top: 10px;
                right: 10px;
                z-index: 2;
                width: 30px;
                height: 30px;
                border-radius: 50%;
                border: 1px solid var(--border-default);
                background: var(--surface-card);
                color: var(--text-tertiary);
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: color .15s, border-color .15s, background .15s;
            }
            .nui-audit-delete:hover {
                color: var(--text-on-primary);
                background: var(--color-danger);
                border-color: var(--color-danger);
            }
        </style>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach ($audits as $audit)
                @php
                    $score = (int) ($audit->overall_score ?? 0);
                    $scoreColor = $score >= 75 ? 'var(--chimera-600)'
                        : ($score >= 50 ? 'var(--color-warning)' : 'var(--color-danger)');
                    $statusLabel = match ($audit->status) {
                        BrandAudit::STATUS_DONE              => 'Selesai',
                        BrandAudit::STATUS_VALIDATION_WARNING => 'Perlu Tinjauan',
                        BrandAudit::STATUS_FAILED            => 'Gagal',
                        BrandAudit::STATUS_ANALYZING         => 'Sedang Dianalisis',
                        default                              => 'Menunggu',
                    };
                    $statusColor = match ($audit->status) {
                        BrandAudit::STATUS_DONE              => 'var(--chimera-600)',
                        BrandAudit::STATUS_VALIDATION_WARNING => 'var(--color-warning)',
                        BrandAudit::STATUS_FAILED            => 'var(--color-danger)',
                        default                              => 'var(--text-tertiary)',
                    };
                @endphp
                <div class="nui-audit-card-wrap" wire:key="audit-{{ $audit->id }}">
                    <a
                        href="{{ route('audit.show', ['token' => $audit->session_token]) }}"
                        wire:navigate
                        class="nui-card p-5 flex items-center gap-5 hover:shadow-md transition"
                        style="text-decoration: none; color: inherit; padding-right: 52px;"
                    >
                        <div style="width: 72px; height: 72px; flex-shrink: 0; border-radius: 50%; border: 3px solid {{ $audit->status === BrandAudit::STATUS_DONE ? $scoreColor : 'var(--border-default)' }}; display: flex; align-items: center; justify-content: center;">
                            @if ($audit->status === BrandAudit::STATUS_DONE || $audit->status === BrandAudit::STATUS_VALIDATION_WARNING)
                                <span style="font-size: 22px; font-weight: 600; color: {{ $scoreColor }};">{{ $score }}</span>
                            @else
                                <i class="ti ti-loader-2" style="font-size: 24px; color: var(--text-tertiary);"></i>
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 style="font-size: 16px; font-weight: 600; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                {{ $audit->brand_name }}
                            </h3>
                            <p style="font-size: 13px; color: var(--text-secondary); margin-top: 2px;">
                                {{ $audit->city ?: 'Kota tidak diisi' }} · {{ $audit->created_at?->translatedFormat('j M Y') }}
                            </p>
                            <span style="display: inline-block; margin-top: 8px; font-size: 11px; font-weight: 500; padding: 3px 10px; border-radius: var(--radius-pill); background: var(--surface-muted); color: {{ $statusColor }};">
                                {{ $statusLabel }}
                            </span>
                        </div>
                    </a>
                    <button
                        type="button"
                        wire:click="deleteAudit('{{ $audit->id }}')"
                        wire:confirm="Hapus audit &quot;{{ $audit->brand_name }}&quot;? Tindakan ini permanen dan tidak bisa dibatalkan."
                        class="nui-audit-delete"
                        title="Hapus audit"
                        aria-label="Hapus audit {{ $audit->brand_name }}"
                    >
                        <i class="ti ti-trash" style="font-size: 15px;"></i>
                    </button>
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $audits->links() }}
        </div>
    @endif
</section>
