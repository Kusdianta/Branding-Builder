{{--
    BB91 skeleton — content lands in BB93 (card-based service type
    selector with self_service option). Step routing only here.
--}}
<div class="step step-2">
    <h2 style="font-size: 28px; font-weight: 600; line-height: 1.2; margin: 0 0 8px;">Jenis layanan utama?</h2>
    <p style="font-size: 15px; color: var(--text-secondary); margin: 0 0 24px;">Pilih satu yang paling dominan di outletmu.</p>

    <div style="padding: 24px; border: 1px dashed var(--border-default); border-radius: var(--radius-lg); background: var(--surface-muted); text-align: center;">
        <p style="font-size: 13px; color: var(--text-tertiary); margin: 0;">
            <em>Step 2 placeholder — service type cards activate in BB93.</em>
        </p>
    </div>

    <div style="margin-top: 24px;">
        <button
            type="button"
            wire:click="nextStep"
            style="padding: 10px 20px; background: var(--chimera-500); color: var(--text-on-primary); border-radius: var(--radius-pill); border: none; cursor: pointer; font-size: 14px; font-weight: 500;"
        >
            Lanjutkan →
        </button>
    </div>
</div>
