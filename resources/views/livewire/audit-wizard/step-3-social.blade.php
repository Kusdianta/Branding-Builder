{{--
    BB106 — Step 3: Social handles + WhatsApp.

    Full rewrite. The BB100/BB101/BB102 Alpine state machines
    (step3Gate / igHandleChecker / whatsappValidator / ttHandleChecker)
    are gone. All state lives in the Volt component:

      - $instagramUsername / $igCheckStatus
      - $tiktokUsername    / $ttCheckStatus
      - $whatsappNumber    / $whatsappValidity

    Verification is triggered ONLY by the operator clicking "Cek dulu"
    (wire:click="checkInstagram" / "checkTiktok"). No debounced fetch,
    no x-data, no morph-race teardown hazards.

    The English↔Indonesian status copy is centralized in $socialLabels
    and $waLabels below so adding a new status (or rewording one)
    touches one place.
--}}
@php
    // BB106 — single source of truth for status copy. Add a new state
    // here and the four indicator slots pick it up automatically.
    $socialLabels = [
        'idle'      => null,   // no badge while never-checked
        'checking'  => ['text' => 'Mengecek...',     'tone' => 'warn'],
        'found'     => ['text' => 'Ditemukan',       'tone' => 'ok'],
        'not_found' => ['text' => 'Tidak ditemukan', 'tone' => 'bad'],
        'error'     => ['text' => 'Tidak bisa cek',  'tone' => 'warn'],
    ];
    $waLabels = [
        'idle'    => null,
        'valid'   => ['text' => 'Format OK',    'tone' => 'ok'],
        'invalid' => ['text' => 'Format salah', 'tone' => 'bad'],
    ];

    $igLabel = $socialLabels[$igCheckStatus] ?? null;
    $ttLabel = $socialLabels[$ttCheckStatus] ?? null;
    $waLabel = $waLabels[$whatsappValidity] ?? null;
@endphp

<div class="bb-step bb-step-3">
    <h2 class="bb-step-title">Akun sosial & WhatsApp?</h2>
    <p class="bb-step-sub">Semuanya opsional. Saya cek dulu sebelum lanjut biar audit tidak salah sasaran.</p>

    {{-- ============================================================
         Instagram — primary signal, manual verification
         ============================================================ --}}
    <div class="bb-field">
        <label for="instagram-username">
            <i class="ti ti-brand-instagram"></i> Instagram
        </label>
        <div class="bb-input-with-button">
            <div class="bb-input-prefix">
                <span class="prefix">@</span>
                <input
                    type="text"
                    id="instagram-username"
                    wire:model.live.debounce.300ms="instagramUsername"
                    placeholder="namaakun"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    @class([
                        'bb-input-ok'  => $igCheckStatus === 'found',
                        'bb-input-bad' => $igCheckStatus === 'not_found',
                    ])
                />
            </div>
            <button
                type="button"
                wire:click="checkInstagram"
                wire:loading.attr="disabled"
                wire:target="checkInstagram"
                @disabled(empty($instagramUsername) || $igCheckStatus === 'checking')
                class="bb-btn-check"
            >
                <span wire:loading.remove wire:target="checkInstagram">Cek dulu</span>
                <span wire:loading wire:target="checkInstagram">Mengecek...</span>
            </button>
        </div>

        @if ($igLabel)
            <div class="bb-status-row">
                <span class="bb-status-pill bb-status-pill--{{ $igLabel['tone'] }}">
                    {{ $igLabel['text'] }}
                </span>
            </div>
        @endif

        @if ($igCheckStatus === 'not_found')
            {{-- BB107 — `@{{ $var }}` is Blade's escape syntax (renders the
                 curlies as literal text). Build the @-prefixed handle inside
                 a single Blade expression so the literal "@" stays plain. --}}
            <p class="bb-error">
                Akun {{ '@' . $instagramUsername }} tidak ditemukan di Instagram. Periksa lagi atau kosongkan.
            </p>
        @endif
        @if ($igCheckStatus === 'error')
            <p class="bb-hint">
                Worker tidak bisa cek sekarang. Pastikan worker aktif lalu coba lagi.
            </p>
        @endif

        @error('instagramUsername')<p class="bb-error">{{ $message }}</p>@enderror
    </div>

    {{-- ============================================================
         WhatsApp Business — format-only validation, no worker call
         ============================================================ --}}
    <div class="bb-field">
        <label for="whatsapp-number">
            <i class="ti ti-brand-whatsapp"></i> WhatsApp Business
        </label>
        <div class="bb-input-prefix">
            <span class="prefix">+62</span>
            <input
                type="tel"
                id="whatsapp-number"
                wire:model.live.debounce.300ms="whatsappNumber"
                placeholder="8123456789"
                inputmode="numeric"
                autocomplete="off"
                @class([
                    'bb-input-ok'  => $whatsappValidity === 'valid',
                    'bb-input-bad' => $whatsappValidity === 'invalid',
                ])
            />
        </div>

        @if ($waLabel)
            <div class="bb-status-row">
                <span class="bb-status-pill bb-status-pill--{{ $waLabel['tone'] }}">
                    {{ $waLabel['text'] }}
                </span>
                @if ($whatsappValidity === 'valid' && $whatsappNumber)
                    <a href="https://wa.me/62{{ $whatsappNumber }}"
                       target="_blank"
                       rel="noopener"
                       class="bb-link-inline">
                        <i class="ti ti-external-link"></i>
                        Buka di WhatsApp untuk pastikan aktif
                    </a>
                @endif
            </div>
        @endif

        @if ($whatsappValidity === 'invalid')
            <p class="bb-error">
                Format tidak valid. Contoh: <code>8123456789</code> (tanpa +62 atau 0 di depan).
            </p>
        @endif
        @error('whatsappNumber')<p class="bb-error">{{ $message }}</p>@enderror
    </div>

    {{-- ============================================================
         TikTok — bonus signal, manual verification
         BB107: gated behind the wizard_show_tiktok feature flag.
                When the flag is off, $tiktokUsername stays null forever
                and the Step 3 gate's TikTok branch becomes a no-op
                (empty handle = no constraint). Server-side scoring +
                /check-handle/tiktok route + TikTokHandleChecker service
                stay live so re-enabling is a single env flip.
                NOTE: TikTokHandleChecker has the same fixture-rot
                problem as the pre-BB107 IG checker (anonymous TT HTML
                scraping no longer reliably distinguishes real from fake
                handles). Fix BB108 before flipping this flag back on.
         ============================================================ --}}
    @if (config('features.wizard_show_tiktok', false))
    <div class="bb-field bb-field--optional">
        <label for="tiktok-username">
            <i class="ti ti-brand-tiktok"></i> TikTok
            <span class="bb-badge bb-badge--muted">opsional · bonus</span>
        </label>
        <div class="bb-input-with-button">
            <div class="bb-input-prefix">
                <span class="prefix">@</span>
                <input
                    type="text"
                    id="tiktok-username"
                    wire:model.live.debounce.300ms="tiktokUsername"
                    placeholder="namaakun"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    @class([
                        'bb-input-ok'  => $ttCheckStatus === 'found',
                        'bb-input-bad' => $ttCheckStatus === 'not_found',
                    ])
                />
            </div>
            <button
                type="button"
                wire:click="checkTiktok"
                wire:loading.attr="disabled"
                wire:target="checkTiktok"
                @disabled(empty($tiktokUsername) || $ttCheckStatus === 'checking')
                class="bb-btn-check"
            >
                <span wire:loading.remove wire:target="checkTiktok">Cek dulu</span>
                <span wire:loading wire:target="checkTiktok">Mengecek...</span>
            </button>
        </div>

        @if ($ttLabel)
            <div class="bb-status-row">
                <span class="bb-status-pill bb-status-pill--{{ $ttLabel['tone'] }}">
                    {{ $ttLabel['text'] }}
                </span>
            </div>
        @endif

        @if ($ttCheckStatus === 'not_found')
            <p class="bb-error">
                Akun {{ '@' . $tiktokUsername }} tidak ditemukan di TikTok. Periksa lagi atau kosongkan.
            </p>
        @endif
        @if ($ttCheckStatus === 'error')
            <p class="bb-hint">
                Worker tidak bisa cek sekarang. Pastikan worker aktif lalu coba lagi.
            </p>
        @endif

        @error('tiktokUsername')<p class="bb-error">{{ $message }}</p>@enderror
    </div>
    @endif

    <p class="bb-hint">
        Boleh tempel link profil lengkap (misalnya <code>instagram.com/namaakun</code>) — saya akan ambil username-nya saja.
    </p>

    <div class="bb-actions between">
        {{-- "Lewati semua" nulls all three fields server-side so the
             gate passes; this is the explicit opt-out path. --}}
        <button type="button" wire:click="skipStep3" class="bb-btn-ghost">Lewati semua</button>

        {{-- Lanjutkan honours the gate via @disabled + server-side
             validateCurrentWizardStep() Step 3 branch. Both layers
             must agree; the server is authoritative. --}}
        <button type="button"
                wire:click="nextStep"
                @disabled(! $this->canAdvanceFromStep3)
                @class([
                    'bb-btn-primary',
                    'bb-btn-disabled' => ! $this->canAdvanceFromStep3,
                ])>
            Lanjutkan
            <i class="ti ti-arrow-right"></i>
        </button>
    </div>

    @if ($this->step3BlockReason)
        <p class="bb-hint bb-hint--gate">{{ $this->step3BlockReason }}</p>
    @endif
</div>
