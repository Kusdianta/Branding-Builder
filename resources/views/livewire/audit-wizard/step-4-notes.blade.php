{{--
    BB95 — Step 4: Notes + submit.

    Optional free-form textarea. Clicking the primary button calls
    submit() (or the "Lewati dan analisis" link does the same — both
    are explicit so screen-reader users have two clear pathways out).
    submit() charges 1 credit + dispatches AnalyzeBrand; the
    insufficient-credits modal in the parent wizard template handles
    the balance=0 case.
--}}
<div class="step step-4">
    <h2 style="font-size: 28px; font-weight: 600; line-height: 1.2; margin: 0 0 8px;">Ada yang ingin saya soroti?</h2>
    <p style="font-size: 15px; color: var(--text-secondary); margin: 0 0 24px;">Opsional. Misalnya target segmen, layanan unik, atau hal lain yang harus saya perhatikan.</p>

    <textarea
        wire:model.lazy="notes"
        rows="5"
        maxlength="500"
        placeholder="Misalnya: bisnisku fokus ke segmen mahasiswa, atau punya layanan unik..."
        style="width: 100%; padding: 12px 14px; font-size: 14px; line-height: 1.5; border: 1px solid var(--border-default); border-radius: var(--radius-md); background: var(--surface-card); color: var(--text-primary); font-family: var(--font-sans); resize: vertical;"
    ></textarea>
    <p style="font-size: 11px; color: var(--text-tertiary); margin: 4px 0 0; text-align: right;">
        Maks. 500 karakter
    </p>

    @error('notes')
        <p style="font-size: 12px; color: var(--color-danger); margin: 6px 0 0;">{{ $message }}</p>
    @enderror

    {{-- Cross-step error surface: if submit() bounces back with a
         placeId/serviceType error (state tampered or step skipped),
         it lands on those keys; show a top-level pointer here. --}}
    @error('placeId')
        <p style="font-size: 12px; color: var(--color-danger); margin: 12px 0 0;">{{ $message }}</p>
    @enderror
    @error('serviceType')
        <p style="font-size: 12px; color: var(--color-danger); margin: 12px 0 0;">{{ $message }}</p>
    @enderror

    <div style="margin-top: 24px; display: flex; gap: 12px; align-items: center;">
        <button
            type="button"
            wire:click="submit"
            wire:loading.attr="disabled"
            wire:target="submit"
            style="padding: 8px 16px; background: none; color: var(--text-secondary); border: none; cursor: pointer; font-size: 13px; text-decoration: underline;"
        >
            Lewati dan analisis
        </button>
        <button
            type="button"
            wire:click="submit"
            wire:loading.attr="disabled"
            wire:target="submit"
            style="padding: 12px 24px; background: var(--chimera-500); color: var(--text-on-primary); border-radius: var(--radius-pill); border: none; cursor: pointer; font-size: 15px; font-weight: 500; display: inline-flex; align-items: center; gap: 8px;"
        >
            <span wire:loading.remove wire:target="submit">Mulai Analisis</span>
            <span wire:loading wire:target="submit">Memulai…</span>
            <i class="ti ti-sparkles" style="font-size: 16px;" wire:loading.remove wire:target="submit"></i>
        </button>
    </div>

    <p style="font-size: 12px; color: var(--text-tertiary); margin: 16px 0 0; line-height: 1.5;">
        Mulai analisis akan memotong <strong>1 kredit</strong> dari saldo kamu. Kalau audit gagal di tengah jalan, kredit otomatis dikembalikan.
    </p>
</div>
