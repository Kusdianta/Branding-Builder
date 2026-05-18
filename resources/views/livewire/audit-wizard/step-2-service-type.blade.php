{{--
    BB99 → BB111 — Step 2: primary service type + secondary multi-select.

    Primary = "yang paling dominan" (radio). Secondary = "yang juga
    tersedia" (multi-checkbox). Combined variety_count feeds the
    BB117 Kelengkapan Layanan sub-bucket in Brand Konsistensi.

    Default 'kiloan' primary keeps Lanjutkan enabled from entry — the
    secondary checkboxes are optional. Secondary cards for the slug
    that's currently primary are filtered out so the operator can't
    double-count one layanan as both primary and secondary.
--}}
<div class="bb-step bb-step-2">
    <h2 class="bb-step-title">Jenis layanan utama & tambahan</h2>
    <p class="bb-step-sub">Pilih satu yang paling dominan. Layanan tambahan bisa lebih dari satu.</p>

    <h3 class="bb-substep-label">Layanan utama (paling dominan)</h3>
    <div class="bb-svc-grid">
        @foreach ($availableServiceTypes as $type)
            @php($isSelected = $serviceType === $type['slug'])
            <button
                type="button"
                wire:click="$set('serviceType', '{{ $type['slug'] }}')"
                class="bb-svc-card @if ($isSelected) is-selected @endif"
                aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
            >
                <span class="icon">{{ $type['icon'] }}</span>
                <span class="label">{{ $type['label'] }}</span>
                <span class="sub">{{ $type['subtitle'] }}</span>
            </button>
        @endforeach
    </div>

    <h3 class="bb-substep-label bb-substep-label--secondary">Layanan tambahan yang juga tersedia <span class="bb-substep-hint">(opsional)</span></h3>
    <div class="bb-svc-secondary-grid">
        @foreach ($availableServiceTypes as $type)
            @if ($type['slug'] !== $serviceType)
                <label class="bb-svc-checkbox-card">
                    <input
                        type="checkbox"
                        wire:model.live="secondaryServiceTypes"
                        value="{{ $type['slug'] }}"
                    />
                    <span class="icon">{{ $type['icon'] }}</span>
                    <span class="label">{{ $type['label'] }}</span>
                </label>
            @endif
        @endforeach
    </div>

    @error('serviceType')
        <p class="bb-error">{{ $message }}</p>
    @enderror
    @error('secondaryServiceTypes')
        <p class="bb-error">{{ $message }}</p>
    @enderror

    <div class="bb-actions">
        <button type="button" wire:click="nextStep" class="bb-btn-primary">
            Lanjutkan
            <i class="ti ti-arrow-right"></i>
        </button>
    </div>
</div>
