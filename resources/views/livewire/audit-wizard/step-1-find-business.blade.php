{{--
    BB99.1 — Step 1: Find Business.

    Primary path: places-autocomplete-js mounts inside
    #places-autocomplete-container. Config (apiKey, countryBias) is
    passed via DOM data attributes, NOT via a @script call — see the
    rationale in resources/js/places-autocomplete.js. Net effect:
    no @script directive in this partial means no morph-cycle
    re-execution, no Blade parsing surprises, and the container's
    own dataset survives across Livewire roundtrips because of
    wire:ignore.

    Fallback path: "Tidak ketemu?" toggle reveals a URL paste input
    that PlacesApiService resolves server-side to the same payload
    shape selectPlace() consumes from the autocomplete onResponse.
--}}
<div class="bb-step bb-step-1" x-data="{}">
    <h2 class="bb-step-title">Cari bisnismu di Google Maps</h2>
    <p class="bb-step-sub">Saya akan mengambil semua info dari listing Maps kamu.</p>

    @if ($placeId)
        {{-- Selected place preview --}}
        <div class="bb-place-preview">
            <span class="pin"><i class="ti ti-map-pin"></i></span>
            <div style="flex: 1; min-width: 0;">
                <strong class="name">{{ $placeName ?? 'Bisnis terpilih' }}</strong>
                @if ($placeAddress)
                    <p class="addr">{{ $placeAddress }}</p>
                @endif
                <button type="button" wire:click="clearSelectedPlace" class="change">
                    Ganti bisnis
                </button>
            </div>
        </div>

        <div class="bb-actions">
            <button type="button" wire:click="nextStep" class="bb-btn-primary">
                Lanjutkan
                <i class="ti ti-arrow-right"></i>
            </button>
        </div>
    @elseif (! $showManualFallback)
        {{-- Autocomplete container. wire:ignore so Livewire doesn't
             tear down the library-injected input on every roundtrip;
             the data-* attributes are read once by the JS module when
             the MutationObserver sees this node enter the DOM. --}}
        <div
            id="places-autocomplete-container"
            wire:ignore
            data-api-key="{{ $googleMapsApiKey ?? '' }}"
            data-country-bias="{{ $googleMapsCountryBias ?? 'id' }}"
        ></div>

        @error('placeId')
            <p class="bb-error">{{ $message }}</p>
        @enderror

        @if (empty($googleMapsApiKey))
            <div class="bb-warning-banner">
                <strong>Catatan operator:</strong> <code>GOOGLE_MAPS_API_KEY</code> belum di-set di <code>.env</code>. Autocomplete tidak akan aktif sampai key dipaste.
            </div>
        @endif

        <div class="bb-actions between">
            <button type="button" wire:click="$toggle('showManualFallback')" class="bb-btn-ghost">
                Tidak ketemu? Tempel link Google Maps
            </button>
        </div>
    @else
        {{-- Manual google.com/maps URL fallback --}}
        <div class="bb-field">
            <label for="manual-gmaps-url">
                <i class="ti ti-link"></i> Link Google Maps
            </label>
            <input
                type="url"
                id="manual-gmaps-url"
                wire:model="manualGmapsUrl"
                placeholder="https://maps.app.goo.gl/... atau https://google.com/maps/..."
                class="bb-input"
            />
            @if ($manualResolveError)
                <p class="bb-error">{{ $manualResolveError }}</p>
            @endif
        </div>

        <div class="bb-actions between">
            <button type="button" wire:click="$toggle('showManualFallback')" class="bb-btn-ghost">
                <i class="ti ti-arrow-left"></i> Kembali ke pencarian
            </button>
            <button
                type="button"
                wire:click="submitManualGmapsUrl"
                wire:loading.attr="disabled"
                wire:target="submitManualGmapsUrl"
                class="bb-btn-primary"
            >
                <span wire:loading.remove wire:target="submitManualGmapsUrl">Verifikasi & Lanjutkan</span>
                <span wire:loading wire:target="submitManualGmapsUrl">Memverifikasi…</span>
            </button>
        </div>
    @endif
</div>
