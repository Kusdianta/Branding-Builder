{{--
    BB99 — Step 4: Notes + submit.

    Optional textarea. Both buttons call submit() — "Lewati" is just
    a visual ghost so the user understands notes really are optional.
    submit() charges 1 credit + dispatches AnalyzeBrand; the insufficient-
    credits modal in the wizard wrapper handles the balance=0 case.
--}}
<div class="bb-step bb-step-4">
    <h2 class="bb-step-title">Ada yang ingin saya soroti?</h2>
    <p class="bb-step-sub">Opsional. Misalnya target segmen, layanan unik, atau hal lain yang harus saya perhatikan.</p>

    <textarea
        wire:model.lazy="notes"
        rows="5"
        maxlength="500"
        placeholder="Misalnya: bisnisku fokus ke segmen mahasiswa, atau punya layanan unik..."
        class="bb-textarea"
    ></textarea>
    <p class="bb-hint" style="text-align: right;">Maks. 500 karakter</p>

    @error('notes')<p class="bb-error">{{ $message }}</p>@enderror
    @error('placeId')<p class="bb-error">{{ $message }}</p>@enderror
    @error('serviceType')<p class="bb-error">{{ $message }}</p>@enderror

    <div class="bb-actions between">
        <button
            type="button"
            wire:click="submit"
            wire:loading.attr="disabled"
            wire:target="submit"
            class="bb-btn-ghost"
        >
            Lewati & Analisis
        </button>
        <button
            type="button"
            wire:click="submit"
            wire:loading.attr="disabled"
            wire:target="submit"
            class="bb-btn-primary"
        >
            <span wire:loading.remove wire:target="submit">Mulai Analisis</span>
            <span wire:loading wire:target="submit">Memulai…</span>
            <i class="ti ti-sparkles" wire:loading.remove wire:target="submit"></i>
        </button>
    </div>

    <p class="bb-hint" style="text-align: center; margin-top: 16px;">
        Mulai analisis akan memotong <strong>1 kredit</strong> dari saldo kamu. Kalau audit gagal di tengah jalan, kredit otomatis dikembalikan.
    </p>
</div>
