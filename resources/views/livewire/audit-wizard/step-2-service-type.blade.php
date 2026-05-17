{{--
    BB99 — Step 2: Service type selection.

    Card grid; 2 cols mobile, 3 cols >=640px. Default 'kiloan' selection
    means Lanjutkan is enabled from entry — laundries pick it 90% of
    the time, so pre-selection is a UX win.
--}}
<div class="bb-step bb-step-2">
    <h2 class="bb-step-title">Jenis layanan utama?</h2>
    <p class="bb-step-sub">Pilih satu yang paling dominan di outletmu.</p>

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

    @error('serviceType')
        <p class="bb-error">{{ $message }}</p>
    @enderror

    <div class="bb-actions">
        <button type="button" wire:click="nextStep" class="bb-btn-primary">
            Lanjutkan
            <i class="ti ti-arrow-right"></i>
        </button>
    </div>
</div>
