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

    // BB130 — compact human-readable follower count. The checker
    // returns the absolute number (e.g. 12_400); the wizard shows a
    // single short label next to "Ditemukan" so operators can spot
    // the wrong account before submitting.
    $formatFollowers = static function (?int $count): ?string {
        if ($count === null || $count < 0) {
            return null;
        }
        if ($count < 1000) {
            return $count . ' followers';
        }
        if ($count < 1_000_000) {
            $thousands = $count / 1000;
            $label = $thousands >= 100 ? number_format($thousands, 0) : number_format($thousands, 1);
            return rtrim(rtrim($label, '0'), '.') . 'K followers';
        }
        $millions = $count / 1_000_000;
        $label    = $millions >= 100 ? number_format($millions, 0) : number_format($millions, 1);
        return rtrim(rtrim($label, '0'), '.') . 'M followers';
    };
@endphp

<div class="bb-step bb-step-3">
    <h2 class="bb-step-title">Akun sosial & WhatsApp?</h2>
    <p class="bb-step-sub">Semuanya opsional. Saya cek dulu sebelum lanjut biar audit tidak salah sasaran.</p>

    {{-- ============================================================
         BB112 — Operasional outletmu (3 checkboxes).
         User-declared signals. Verified later against scraped review
         keywords + price-list detection. Source on the dashboard reads
         "Sumber: deklarasi operator (form audit) + verifikasi otomatis".
         ============================================================ --}}
    <div class="bb-operational-fields">
        <h3 class="bb-substep-label">Operasional outletmu</h3>
        <p class="bb-substep-hint" style="display:block;margin-bottom:8px;">Centang yang berlaku. Akan diverifikasi dengan data Google Maps & Instagram.</p>

        <label class="bb-op-checkbox">
            <input type="checkbox" wire:model.live="declEkspres" />
            <span class="bb-op-checkbox__body">
                <strong>Layanan Ekspres / Same-day</strong>
                <span class="bb-op-checkbox__hint">Selesai dalam 24 jam atau kurang.</span>
            </span>
        </label>

        <label class="bb-op-checkbox">
            <input type="checkbox" wire:model.live="declAntarJemput" />
            <span class="bb-op-checkbox__body">
                <strong>Antar-Jemput</strong>
                <span class="bb-op-checkbox__hint">Kurir mengambil & mengantar pakaian.</span>
            </span>
        </label>

        <label class="bb-op-checkbox">
            <input type="checkbox" wire:model.live="declSopKeluhan" />
            <span class="bb-op-checkbox__body">
                <strong>SOP Keluhan + Kompensasi</strong>
                <span class="bb-op-checkbox__hint">Ada prosedur tertulis jika pakaian hilang atau rusak.</span>
            </span>
        </label>

        {{-- BB137 — fourth operational signal added. Feeds Brand
             Experience bonus_price_list when paired with the auto-
             detected price_list_detection from PriceListDetector. --}}
        <label class="bb-op-checkbox">
            <input type="checkbox" wire:model.live="declPriceList" />
            <span class="bb-op-checkbox__body">
                <strong>Daftar harga dipublikasikan</strong>
                <span class="bb-op-checkbox__hint">Daftar tarif terpajang di outlet, website, atau sosmed.</span>
            </span>
        </label>
    </div>

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
                {{-- BB130 — follower count next to the "Ditemukan" badge so
                     operators can confirm they grabbed the right account
                     before submitting the audit. --}}
                @if ($igCheckStatus === 'found' && ($followerLabel = $formatFollowers($igFollowerCount ?? null)))
                    <span class="bb-status-meta" style="font-size: 12px; color: var(--text-secondary); margin-left: 8px;">
                        · {{ $followerLabel }}
                    </span>
                @endif
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
            {{-- BB131 — checker uses a direct HTTP call to instagram.com, not
                 the worker, so the prior "Worker tidak bisa cek" copy was
                 misleading. Reword to describe what actually happened
                 (Instagram rate-limited / blocked the unauthenticated probe)
                 and reassure that the field is still usable. --}}
            <p class="bb-hint">
                Belum bisa cek otomatis sekarang (Instagram membatasi pengecekan tanpa login). Lanjutkan saja — handle ini akan tetap diaudit pada tahap analisis.
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
         BB138 — Website URL with HTTP liveness check.
         Operator can paste with or without scheme; checkWebsite()
         prepends https:// before probing. The wizard probe is UX
         feedback; ScorePillarsJob re-runs WebsiteLivenessScorer at
         scoring time against touchpoints.website_url, so a site that
         flickered down between submit and scoring is reflected in the
         final pillar — not in this badge.
         ============================================================ --}}
    @php
        $websiteStatusLabel = match ($websiteCheckStatus) {
            'checking' => ['text' => 'Mengecek...',            'tone' => 'warn'],
            'live'     => ['text' => 'Aktif',                  'tone' => 'ok'],
            'dead'     => ['text' => 'Tidak aktif',            'tone' => 'bad'],
            'error'    => ['text' => 'Belum bisa cek',         'tone' => 'warn'],
            default    => null,
        };
    @endphp
    <div class="bb-field bb-field--optional">
        <label for="wizard-website-url">
            <i class="ti ti-world"></i> Website
        </label>
        <div class="bb-input-with-button">
            <input
                type="text"
                id="wizard-website-url"
                wire:model.live.debounce.400ms="wizardWebsiteUrl"
                placeholder="https://"
                inputmode="url"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                @class([
                    'bb-input',
                    'bb-input-ok'  => $websiteCheckStatus === 'live',
                    'bb-input-bad' => $websiteCheckStatus === 'dead',
                ])
                style="flex: 1;"
            />
            <button
                type="button"
                wire:click="checkWebsite"
                wire:loading.attr="disabled"
                wire:target="checkWebsite"
                @disabled(empty(trim($wizardWebsiteUrl)) || $websiteCheckStatus === 'checking')
                class="bb-btn-check"
            >
                <span wire:loading.remove wire:target="checkWebsite">Cek dulu</span>
                <span wire:loading wire:target="checkWebsite">Mengecek...</span>
            </button>
        </div>

        @if ($websiteStatusLabel)
            <div class="bb-status-row">
                <span class="bb-status-pill bb-status-pill--{{ $websiteStatusLabel['tone'] }}">
                    {{ $websiteStatusLabel['text'] }}
                </span>
                @if ($websiteCheckStatus === 'live')
                    <span class="bb-status-meta" style="font-size: 12px; color: var(--text-secondary); margin-left: 8px;">
                        @if ($websiteCheckHttpStatus)
                            · HTTP {{ $websiteCheckHttpStatus }}
                        @endif
                        @if ($websiteCheckHost)
                            · {{ $websiteCheckHost }}
                        @endif
                    </span>
                @elseif ($websiteCheckStatus === 'dead' && $websiteCheckHttpStatus)
                    <span class="bb-status-meta" style="font-size: 12px; color: var(--text-secondary); margin-left: 8px;">
                        · HTTP {{ $websiteCheckHttpStatus }}
                    </span>
                @endif
            </div>
        @endif

        @if ($websiteCheckStatus === 'dead')
            <p class="bb-hint">
                Website tidak merespons saat dicek. Audit tetap lanjut, tapi sub-skor Website (20 poin) tidak akan diberi.
            </p>
        @endif
        @if ($websiteCheckStatus === 'error')
            <p class="bb-hint">
                Belum bisa cek otomatis sekarang. Boleh dilanjut — server kita akan cek ulang saat audit dijalankan.
            </p>
        @endif

        @error('wizardWebsiteUrl')<p class="bb-error">{{ $message }}</p>@enderror
    </div>

    {{-- ============================================================
         TikTok — manual verification
         BB135: un-gated. TikTokHandleChecker (BB113) calls TikTok's
         /api/user/detail/ JSON endpoint, which the desktop SPA uses
         after the shell loads — real vs fake handles disambiguate
         reliably. "opsional · bonus" badge removed: TikTok contributes
         +10 pts to Digital Presence, it's a genuine touchpoint, not
         a tie-breaker bonus. The field stays leave-blank-friendly.
         ============================================================ --}}
    <div class="bb-field bb-field--optional">
        <label for="tiktok-username">
            <i class="ti ti-brand-tiktok"></i> TikTok
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
                @if ($ttCheckStatus === 'found' && ($followerLabel = $formatFollowers($ttFollowerCount ?? null)))
                    <span class="bb-status-meta" style="font-size: 12px; color: var(--text-secondary); margin-left: 8px;">
                        · {{ $followerLabel }}
                    </span>
                @endif
            </div>
        @endif

        @if ($ttCheckStatus === 'not_found')
            <p class="bb-error">
                Akun {{ '@' . $tiktokUsername }} tidak ditemukan di TikTok — periksa ejaan, atau kosongkan kalau memang belum punya.
            </p>
        @endif
        @if ($ttCheckStatus === 'error')
            {{-- BB135 — TikTok aggressively blocks the unauthenticated
                 JSON probe (CAPTCHA / 4xx). The check is best-effort.
                 When TikTok rate-limits us, we tell the user the check
                 is temporarily unavailable and let them proceed; the
                 wizard gate treats 'error' as advisory, not blocking.
                 If the handle was real, server-side scoring still picks
                 up the URL via touchpoints.tiktok_url (bonus 10pt is
                 awarded only when ttCheckStatus === 'found'; an
                 unverified handle scores 0 from this bucket). --}}
            <p class="bb-hint">
                Belum bisa cek otomatis sekarang (TikTok membatasi pengecekan tanpa login). Lanjutkan saja — handle akan tercatat, tapi bonus 10 poin baru aktif ketika verifikasi berhasil.
            </p>
        @endif

        @error('tiktokUsername')<p class="bb-error">{{ $message }}</p>@enderror
    </div>

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
