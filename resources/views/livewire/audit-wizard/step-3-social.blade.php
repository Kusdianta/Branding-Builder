{{--
    BB91 skeleton — content lands in BB94 (IG + TT username inputs +
    server-side URL strippers).
--}}
<div class="step step-3">
    <h2 style="font-size: 28px; font-weight: 600; line-height: 1.2; margin: 0 0 8px;">Akun sosialmu?</h2>
    <p style="font-size: 15px; color: var(--text-secondary); margin: 0 0 24px;">Opsional. Bisa di-skip kalau belum ada.</p>

    <div style="padding: 24px; border: 1px dashed var(--border-default); border-radius: var(--radius-lg); background: var(--surface-muted); text-align: center;">
        <p style="font-size: 13px; color: var(--text-tertiary); margin: 0;">
            <em>Step 3 placeholder — social handle inputs activate in BB94.</em>
        </p>
    </div>

    <div style="margin-top: 24px; display: flex; gap: 12px; align-items: center;">
        <button
            type="button"
            wire:click="nextStep"
            style="padding: 8px 16px; background: none; color: var(--text-secondary); border: none; cursor: pointer; font-size: 13px; text-decoration: underline;"
        >
            Skip
        </button>
        <button
            type="button"
            wire:click="nextStep"
            style="padding: 10px 20px; background: var(--chimera-500); color: var(--text-on-primary); border-radius: var(--radius-pill); border: none; cursor: pointer; font-size: 14px; font-weight: 500;"
        >
            Lanjutkan →
        </button>
    </div>
</div>
