{{--
    BB99 — Step 3: Social handles.

    Both fields optional. updatedInstagramUsername / updatedTiktokUsername
    hooks normalize on blur (strip URLs, @, fragments). Visual @ prefix
    sits inside the input chrome.
--}}
<div class="bb-step bb-step-3">
    <h2 class="bb-step-title">Akun sosialmu?</h2>
    <p class="bb-step-sub">Opsional. Bisa di-skip kalau belum ada.</p>

    <div class="bb-field">
        <label for="instagram-username">
            <i class="ti ti-brand-instagram"></i> Instagram
        </label>
        <div class="bb-input-prefix">
            <span class="prefix">@</span>
            <input
                type="text"
                id="instagram-username"
                wire:model.blur="instagramUsername"
                placeholder="namaakun"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
            />
        </div>
        @error('instagramUsername')<p class="bb-error">{{ $message }}</p>@enderror
    </div>

    <div class="bb-field">
        <label for="tiktok-username">
            <i class="ti ti-brand-tiktok"></i> TikTok
        </label>
        <div class="bb-input-prefix">
            <span class="prefix">@</span>
            <input
                type="text"
                id="tiktok-username"
                wire:model.blur="tiktokUsername"
                placeholder="namaakun"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
            />
        </div>
        @error('tiktokUsername')<p class="bb-error">{{ $message }}</p>@enderror
    </div>

    <p class="bb-hint">
        Boleh tempel link profil lengkap (misalnya <code>instagram.com/namaakun</code>) — saya akan ambil username-nya saja.
    </p>

    <div class="bb-actions between">
        <button type="button" wire:click="nextStep" class="bb-btn-ghost">Lewati</button>
        <button type="button" wire:click="nextStep" class="bb-btn-primary">
            Lanjutkan
            <i class="ti ti-arrow-right"></i>
        </button>
    </div>
</div>
