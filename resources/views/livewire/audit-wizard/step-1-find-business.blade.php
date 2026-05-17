{{--
    BB92 — Step 1: Find Business.

    Primary path: places-autocomplete-js mounts into the container div
    and pipes selections back via $wire.call('selectPlace', ...). The
    library handles session-token lifecycle so we get autocomplete
    keystrokes at session pricing + a single billed Place Details call
    on selection.

    Fallback path: "Tidak ketemu?" link toggles a URL paste input that
    the server-side PlacesApiService resolves to the same payload shape
    (maps.app.goo.gl shortlink, google.com/maps/place/..., or cid=...).

    The two paths converge on selectPlace() → place_* state.
--}}
<div class="step step-1" x-data="{}">
    <h2 style="font-size: 28px; font-weight: 600; line-height: 1.2; margin: 0 0 8px;">Cari bisnismu di Google Maps</h2>
    <p style="font-size: 15px; color: var(--text-secondary); margin: 0 0 24px;">Saya akan mengambil semua info dari listing Maps kamu.</p>

    @if ($placeId)
        {{-- Selected place preview --}}
        <div style="display: flex; gap: 16px; padding: 16px 20px; border: 1px solid var(--chimera-200); border-radius: var(--radius-lg); background: var(--chimera-50);">
            <span style="display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; background: var(--chimera-500); color: var(--text-on-primary); flex-shrink: 0;">
                <i class="ti ti-map-pin" style="font-size: 20px;"></i>
            </span>
            <div style="flex: 1; min-width: 0;">
                <strong style="display: block; font-size: 16px; color: var(--text-primary); line-height: 1.3;">{{ $placeName ?? 'Bisnis terpilih' }}</strong>
                @if ($placeAddress)
                    <p style="margin: 4px 0 0; font-size: 13px; color: var(--text-secondary); line-height: 1.4;">{{ $placeAddress }}</p>
                @endif
                <button
                    type="button"
                    wire:click="clearSelectedPlace"
                    style="margin-top: 8px; background: none; border: none; padding: 0; font-size: 12px; color: var(--chimera-700); cursor: pointer; text-decoration: underline;"
                >
                    Pilih bisnis lain
                </button>
            </div>
        </div>

        <button
            type="button"
            wire:click="nextStep"
            style="margin-top: 24px; padding: 12px 24px; background: var(--chimera-500); color: var(--text-on-primary); border-radius: var(--radius-pill); border: none; cursor: pointer; font-size: 15px; font-weight: 500; display: inline-flex; align-items: center; gap: 8px;"
        >
            Lanjutkan
            <i class="ti ti-arrow-right" style="font-size: 16px;"></i>
        </button>
    @elseif (! $showManualFallback)
        {{-- Autocomplete path. wire:ignore keeps Livewire from rebuilding
             the autocomplete-injected DOM on every roundtrip; the inner
             div is mounted by the library, not by Livewire. --}}
        <div wire:ignore>
            <div id="places-autocomplete-container"></div>
        </div>

        @error('placeId')
            <p style="font-size: 12px; color: var(--color-danger); margin: 8px 0 0;">{{ $message }}</p>
        @enderror

        @if (empty($googleMapsApiKey))
            <div style="margin-top: 12px; padding: 12px 16px; border-radius: var(--radius-md); background: #FEF3C7; border: 1px solid #F59E0B; color: #92400E; font-size: 13px;">
                <strong>Catatan operator:</strong> <code>GOOGLE_MAPS_API_KEY</code> belum di-set di <code>.env</code>. Autocomplete tidak akan aktif sampai key dipaste.
            </div>
        @endif

        <button
            type="button"
            wire:click="$toggle('showManualFallback')"
            style="margin-top: 16px; background: none; border: none; padding: 0; font-size: 13px; color: var(--text-secondary); cursor: pointer; text-decoration: underline;"
        >
            Tidak ketemu? Tempel link Google Maps
        </button>

        @script
            <script>
                (function () {
                    const apiKey = @js($googleMapsApiKey ?? '');
                    const countryBias = @js($googleMapsCountryBias ?? 'id');
                    if (!apiKey) {
                        return; // banner above already warns the operator.
                    }
                    if (typeof window.bbInitPlacesAutocomplete !== 'function') {
                        console.error('[wizard] bbInitPlacesAutocomplete not loaded — did npm run build run?');
                        return;
                    }
                    // $wire is the Livewire component handle scoped to this @script block.
                    window.bbInitPlacesAutocomplete({
                        containerId: 'places-autocomplete-container',
                        livewireComponent: $wire,
                        apiKey,
                        countryBias,
                    });
                })();
            </script>
        @endscript
    @else
        {{-- Manual google.com/maps URL fallback --}}
        <label for="manual-gmaps-url" style="display: block; font-size: 13px; font-weight: 500; color: var(--text-primary); margin-bottom: 6px;">Link Google Maps</label>
        <input
            type="url"
            id="manual-gmaps-url"
            wire:model="manualGmapsUrl"
            placeholder="https://maps.app.goo.gl/... atau https://google.com/maps/..."
            style="width: 100%; padding: 10px 14px; font-size: 14px; border: 1px solid var(--border-default); border-radius: var(--radius-md); background: var(--surface-card); color: var(--text-primary);"
        />

        @if ($manualResolveError)
            <p style="font-size: 12px; color: var(--color-danger); margin: 8px 0 0;">{{ $manualResolveError }}</p>
        @endif

        <div style="margin-top: 16px; display: flex; align-items: center; gap: 12px;">
            <button
                type="button"
                wire:click="submitManualGmapsUrl"
                wire:loading.attr="disabled"
                wire:target="submitManualGmapsUrl"
                style="padding: 10px 20px; background: var(--chimera-500); color: var(--text-on-primary); border-radius: var(--radius-pill); border: none; cursor: pointer; font-size: 14px; font-weight: 500;"
            >
                <span wire:loading.remove wire:target="submitManualGmapsUrl">Verifikasi &amp; Lanjutkan</span>
                <span wire:loading wire:target="submitManualGmapsUrl">Memverifikasi…</span>
            </button>
            <button
                type="button"
                wire:click="$toggle('showManualFallback')"
                style="background: none; border: none; padding: 0; font-size: 13px; color: var(--text-secondary); cursor: pointer; text-decoration: underline;"
            >
                ← Kembali ke pencarian
            </button>
        </div>
    @endif
</div>
