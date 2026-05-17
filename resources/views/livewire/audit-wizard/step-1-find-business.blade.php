{{--
    BB91 skeleton — content lands in BB92 (Places Autocomplete + manual
    google.com/maps fallback). For now this renders the layout shell so
    the step routing in brand-audit-wizard.blade.php can be exercised
    end-to-end without the JS bundle landed.
--}}
<div class="step step-1">
    <h2 style="font-size: 28px; font-weight: 600; line-height: 1.2; margin: 0 0 8px;">Cari bisnismu di Google Maps</h2>
    <p style="font-size: 15px; color: var(--text-secondary); margin: 0 0 24px;">Saya akan mengambil semua info dari listing Maps kamu.</p>

    <div style="padding: 24px; border: 1px dashed var(--border-default); border-radius: var(--radius-lg); background: var(--surface-muted); text-align: center;">
        <p style="font-size: 13px; color: var(--text-tertiary); margin: 0;">
            <em>Step 1 placeholder — Places Autocomplete activates in BB92.</em>
        </p>
        @if ($placeId)
            <p style="font-size: 13px; color: var(--text-secondary); margin: 12px 0 0;">
                Selected: <strong>{{ $placeName ?? $placeId }}</strong>
            </p>
        @endif
    </div>

    @error('placeId')
        <p style="font-size: 12px; color: var(--color-danger); margin: 8px 0 0;">{{ $message }}</p>
    @enderror

    <div style="margin-top: 24px; display: flex; gap: 12px; align-items: center;">
        <button
            type="button"
            wire:click="nextStep"
            @if (! $placeId) disabled @endif
            style="padding: 10px 20px; background: var(--chimera-500); color: var(--text-on-primary); border-radius: var(--radius-pill); border: none; cursor: pointer; font-size: 14px; font-weight: 500; @if (! $placeId) opacity: 0.4; cursor: not-allowed; @endif"
        >
            Lanjutkan →
        </button>
    </div>
</div>
