{{--
    BB93 — Step 2: Service type selection.

    Card grid: 2 columns mobile, 3 columns desktop. Each card is a
    tappable button that wire:click="$set('serviceType', '<slug>')".
    The 'kiloan' default from the Volt class survives as the initial
    selection so the Lanjutkan button is enabled from step entry —
    users can advance immediately if their primary service truly is
    kiloan (the most common case for IDN laundries).
--}}
<div class="step step-2">
    <h2 style="font-size: 28px; font-weight: 600; line-height: 1.2; margin: 0 0 8px;">Jenis layanan utama?</h2>
    <p style="font-size: 15px; color: var(--text-secondary); margin: 0 0 24px;">Pilih satu yang paling dominan di outletmu.</p>

    <div class="service-type-grid" style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px;">
        @foreach ($availableServiceTypes as $type)
            @php($isSelected = $serviceType === $type['slug'])
            <button
                type="button"
                wire:click="$set('serviceType', '{{ $type['slug'] }}')"
                style="
                    display: flex;
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 8px;
                    padding: 16px;
                    min-height: 96px;
                    border-radius: var(--radius-lg);
                    border: 2px solid {{ $isSelected ? 'var(--chimera-500)' : 'var(--border-default)' }};
                    background: {{ $isSelected ? 'var(--chimera-50)' : 'var(--surface-card)' }};
                    cursor: pointer;
                    transition: all 0.15s ease;
                    text-align: left;
                "
                aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
            >
                <span style="font-size: 28px; line-height: 1;">{{ $type['icon'] }}</span>
                <strong style="font-size: 15px; font-weight: 600; color: var(--text-primary);">{{ $type['label'] }}</strong>
                <span style="font-size: 12px; color: var(--text-secondary); line-height: 1.3;">{{ $type['subtitle'] }}</span>
            </button>
        @endforeach
    </div>

    @error('serviceType')
        <p style="font-size: 12px; color: var(--color-danger); margin: 12px 0 0;">{{ $message }}</p>
    @enderror

    <div style="margin-top: 24px;">
        <button
            type="button"
            wire:click="nextStep"
            style="padding: 12px 24px; background: var(--chimera-500); color: var(--text-on-primary); border-radius: var(--radius-pill); border: none; cursor: pointer; font-size: 15px; font-weight: 500; display: inline-flex; align-items: center; gap: 8px;"
        >
            Lanjutkan
            <i class="ti ti-arrow-right" style="font-size: 16px;"></i>
        </button>
    </div>

    <style>
        @media (min-width: 768px) {
            .service-type-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            }
        }
    </style>
</div>
