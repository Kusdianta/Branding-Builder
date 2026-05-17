{{--
    BB94 — Step 3: Social handles.

    Both fields are optional. Usernames are normalized server-side via
    updatedInstagramUsername() / updatedTiktokUsername() hooks so a
    pasted profile URL or @-prefixed handle becomes a bare username
    before it reaches the database. The visual @-prefix sits inside
    the input chrome so the user sees what's being stored.
--}}
<div class="step step-3">
    <h2 style="font-size: 28px; font-weight: 600; line-height: 1.2; margin: 0 0 8px;">Akun sosialmu?</h2>
    <p style="font-size: 15px; color: var(--text-secondary); margin: 0 0 24px;">Opsional. Bisa di-skip kalau belum ada.</p>

    <div style="display: flex; flex-direction: column; gap: 16px;">
        {{-- Instagram --}}
        <div>
            <label for="instagram-username" style="display: block; font-size: 13px; font-weight: 500; color: var(--text-primary); margin-bottom: 6px;">
                <i class="ti ti-brand-instagram" style="font-size: 14px; vertical-align: -1px; margin-right: 4px;"></i>
                Instagram
            </label>
            <div style="display: flex; align-items: stretch; border: 1px solid var(--border-default); border-radius: var(--radius-md); background: var(--surface-card); overflow: hidden;">
                <span style="display: inline-flex; align-items: center; padding: 0 12px; background: var(--surface-muted); color: var(--text-tertiary); font-size: 14px; border-right: 1px solid var(--border-default);">@</span>
                <input
                    type="text"
                    id="instagram-username"
                    wire:model.blur="instagramUsername"
                    placeholder="namaakun"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    style="flex: 1; padding: 10px 14px; font-size: 14px; border: none; background: transparent; color: var(--text-primary); outline: none;"
                />
            </div>
            @error('instagramUsername')
                <p style="font-size: 12px; color: var(--color-danger); margin: 6px 0 0;">{{ $message }}</p>
            @enderror
        </div>

        {{-- TikTok --}}
        <div>
            <label for="tiktok-username" style="display: block; font-size: 13px; font-weight: 500; color: var(--text-primary); margin-bottom: 6px;">
                <i class="ti ti-brand-tiktok" style="font-size: 14px; vertical-align: -1px; margin-right: 4px;"></i>
                TikTok
            </label>
            <div style="display: flex; align-items: stretch; border: 1px solid var(--border-default); border-radius: var(--radius-md); background: var(--surface-card); overflow: hidden;">
                <span style="display: inline-flex; align-items: center; padding: 0 12px; background: var(--surface-muted); color: var(--text-tertiary); font-size: 14px; border-right: 1px solid var(--border-default);">@</span>
                <input
                    type="text"
                    id="tiktok-username"
                    wire:model.blur="tiktokUsername"
                    placeholder="namaakun"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    style="flex: 1; padding: 10px 14px; font-size: 14px; border: none; background: transparent; color: var(--text-primary); outline: none;"
                />
            </div>
            @error('tiktokUsername')
                <p style="font-size: 12px; color: var(--color-danger); margin: 6px 0 0;">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <p style="font-size: 12px; color: var(--text-tertiary); margin: 12px 0 0; line-height: 1.5;">
        Boleh tempel link profil lengkap (misalnya <code style="font-family: var(--font-mono); font-size: 11px;">instagram.com/namaakun</code>) — saya akan ambil username-nya saja.
    </p>

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
            style="padding: 12px 24px; background: var(--chimera-500); color: var(--text-on-primary); border-radius: var(--radius-pill); border: none; cursor: pointer; font-size: 15px; font-weight: 500; display: inline-flex; align-items: center; gap: 8px;"
        >
            Lanjutkan
            <i class="ti ti-arrow-right" style="font-size: 16px;"></i>
        </button>
    </div>
</div>
