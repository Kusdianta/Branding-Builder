{{--
    BB91 skeleton — content lands in BB95 (notes textarea + submit
    pipeline). The active submit button currently dispatches the legacy
    v1 submit() (which won't fire correctly without place_id wired into
    that path). BB95 rewrites submit() to consume the v2 state directly.
--}}
<div class="step step-4">
    <h2 style="font-size: 28px; font-weight: 600; line-height: 1.2; margin: 0 0 8px;">Ada yang ingin saya soroti?</h2>
    <p style="font-size: 15px; color: var(--text-secondary); margin: 0 0 24px;">Opsional. Misalnya target segmen, layanan unik, atau hal lain yang harus saya perhatikan.</p>

    <div style="padding: 24px; border: 1px dashed var(--border-default); border-radius: var(--radius-lg); background: var(--surface-muted); text-align: center;">
        <p style="font-size: 13px; color: var(--text-tertiary); margin: 0;">
            <em>Step 4 placeholder — notes input + submit pipeline activate in BB95.</em>
        </p>
    </div>

    <div style="margin-top: 24px; display: flex; gap: 12px; align-items: center;">
        <button
            type="button"
            disabled
            style="padding: 10px 20px; background: var(--chimera-500); color: var(--text-on-primary); border-radius: var(--radius-pill); border: none; cursor: not-allowed; font-size: 14px; font-weight: 500; opacity: 0.4;"
        >
            Mulai Analisis →
        </button>
        <span style="font-size: 12px; color: var(--text-tertiary);">(disabled in BB91 skeleton)</span>
    </div>
</div>
