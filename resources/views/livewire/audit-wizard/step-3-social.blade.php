{{--
    BB100/BB101/BB102 — Step 3: Social handles + WhatsApp.

    Each block owns its own Alpine component:
      - igHandleChecker   : POSTs to /check-handle/instagram on debounce
      - ttHandleChecker   : POSTs to /check-handle/tiktok on debounce
      - whatsappValidator : pure client-side Indonesian-mobile format check

    Alpine owns the input value and the visual status indicator. Each
    component pushes the verified value to the Livewire wire model on
    blur via $wire.set so the server-side normalisers
    (updatedInstagramUsername / updatedTiktokUsername / updatedWhatsappNumber)
    still run and the value persists into submit()'s deriveTouchpoints().

    The CSRF token is passed in via @js so we don't depend on a global
    <meta name="csrf-token"> tag (the nema-ui-kit layout does not emit one).
--}}
@php
    $csrfToken = csrf_token();
@endphp

<div class="bb-step bb-step-3">
    <h2 class="bb-step-title">Akun sosial & WhatsApp?</h2>
    <p class="bb-step-sub">Semuanya opsional. Saya cek dulu sebelum lanjut biar audit tidak salah sasaran.</p>

    {{-- ============================================================
         BB100 — Instagram (primary signal, availability check)
         ============================================================ --}}
    <div class="bb-field" x-data="igHandleChecker(@js($instagramUsername), @js($csrfToken))" x-init="init()">
        <label for="instagram-username">
            <i class="ti ti-brand-instagram"></i> Instagram
        </label>
        <div class="bb-input-prefix">
            <span class="prefix">@</span>
            <input
                type="text"
                id="instagram-username"
                x-model="username"
                @input.debounce.500ms="check()"
                @blur="commit()"
                placeholder="namaakun"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                :class="{ 'bb-input-ok': status === 'found', 'bb-input-bad': status === 'not_found' }"
            />
            <span class="bb-status-icon"
                  x-show="status !== 'idle'"
                  x-text="statusIcon()"
                  :class="statusClass()"></span>
        </div>

        <div class="bb-handle-preview" x-show="status === 'found'" x-cloak>
            <template x-if="profilePicUrl">
                <img :src="profilePicUrl" alt="" referrerpolicy="no-referrer" />
            </template>
            <div class="bb-handle-preview__text">
                <strong x-text="displayName || ('@' + username)"></strong>
                <p x-text="followerLine()"></p>
            </div>
        </div>

        <p class="bb-error" x-show="status === 'not_found'" x-cloak>
            Akun @<span x-text="username"></span> tidak ditemukan di Instagram. Periksa lagi atau lewati.
        </p>
        <p class="bb-hint" x-show="status === 'error'" x-cloak>
            Tidak bisa cek otomatis sekarang — saya tetap lanjut pakai username yang kamu isi.
        </p>

        @error('instagramUsername')<p class="bb-error">{{ $message }}</p>@enderror
    </div>

    {{-- ============================================================
         BB102 — WhatsApp Business number
         ============================================================ --}}
    <div class="bb-field" x-data="whatsappValidator(@js($whatsappNumber))" x-init="init()">
        <label for="whatsapp-number">
            <i class="ti ti-brand-whatsapp"></i> WhatsApp Business
        </label>
        <div class="bb-input-prefix">
            <span class="prefix">+62</span>
            <input
                type="tel"
                id="whatsapp-number"
                x-model="number"
                @input.debounce.300ms="validate()"
                @blur="commit()"
                placeholder="8123456789"
                inputmode="numeric"
                autocomplete="off"
                :class="{ 'bb-input-ok': status === 'valid', 'bb-input-bad': status === 'invalid' }"
            />
            <span class="bb-status-icon"
                  x-show="status === 'valid' || status === 'invalid'"
                  x-text="status === 'valid' ? '✓' : '✗'"
                  :class="status === 'valid' ? 'ok' : 'bad'"></span>
        </div>

        <div class="bb-handle-preview" x-show="status === 'valid'" x-cloak>
            <div class="bb-handle-preview__text">
                <strong>Nomor terformat: <span x-text="'+62 ' + number"></span></strong>
                <p>
                    <a :href="waLink()" target="_blank" rel="noopener">
                        <i class="ti ti-external-link"></i>
                        Buka wa.me untuk verifikasi manual
                    </a>
                </p>
            </div>
        </div>

        <p class="bb-error" x-show="status === 'invalid'" x-cloak>
            Format tidak valid. Contoh: <code>8123456789</code> (tanpa +62 atau 0 di depan).
        </p>
        @error('whatsappNumber')<p class="bb-error">{{ $message }}</p>@enderror
    </div>

    {{-- ============================================================
         BB101 — TikTok (bonus signal, availability check)
         ============================================================ --}}
    <div class="bb-field bb-field--optional" x-data="ttHandleChecker(@js($tiktokUsername), @js($csrfToken))" x-init="init()">
        <label for="tiktok-username">
            <i class="ti ti-brand-tiktok"></i> TikTok
            <span class="bb-badge bb-badge--muted">opsional · bonus</span>
        </label>
        <div class="bb-input-prefix">
            <span class="prefix">@</span>
            <input
                type="text"
                id="tiktok-username"
                x-model="username"
                @input.debounce.500ms="check()"
                @blur="commit()"
                placeholder="namaakun"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                :class="{ 'bb-input-ok': status === 'found', 'bb-input-bad': status === 'not_found' }"
            />
            <span class="bb-status-icon"
                  x-show="status !== 'idle'"
                  x-text="statusIcon()"
                  :class="statusClass()"></span>
        </div>

        <div class="bb-handle-preview" x-show="status === 'found'" x-cloak>
            <template x-if="profilePicUrl">
                <img :src="profilePicUrl" alt="" referrerpolicy="no-referrer" />
            </template>
            <div class="bb-handle-preview__text">
                <strong x-text="displayName || ('@' + username)"></strong>
                <p x-text="followerLine()"></p>
            </div>
        </div>

        <p class="bb-error" x-show="status === 'not_found'" x-cloak>
            Akun @<span x-text="username"></span> tidak ditemukan di TikTok.
        </p>
        <p class="bb-hint" x-show="status === 'error'" x-cloak>
            Tidak bisa cek TikTok sekarang — tetap saya simpan kalau kamu mau lanjut.
        </p>

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

@once
    @push('scripts')
    <script>
    (function () {
        // Shared handle-check fetcher used by both Instagram and TikTok blocks.
        async function fetchHandle(endpoint, username, csrfToken) {
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ username }),
            });
            if (response.status === 422) {
                return { status: 'not_found', exists: false };
            }
            if (!response.ok) {
                return { status: 'error', exists: false };
            }
            return await response.json();
        }

        function formatFollowers(count) {
            if (!Number.isFinite(count) || count <= 0) return null;
            if (count >= 1_000_000) return (count / 1_000_000).toFixed(1).replace(/\.0$/, '') + 'M';
            if (count >= 1_000)     return (count / 1_000).toFixed(1).replace(/\.0$/, '') + 'K';
            return String(count);
        }

        function buildHandleComponent(endpoint, wireKey) {
            return function handleChecker(initialValue, csrfToken) {
                return {
                    username: initialValue || '',
                    status: 'idle',          // idle | checking | found | not_found | error
                    displayName: '',
                    profilePicUrl: '',
                    followerCount: 0,
                    _lastChecked: null,
                    _abort: null,
                    init() {
                        // Run an initial check if the wizard was mounted with
                        // a pre-filled value (back-navigated from Step 4).
                        if (this.username && this.username.length >= 2) {
                            this.check();
                        }
                    },
                    statusIcon() {
                        return {
                            checking:  '…',
                            found:     '✓',
                            not_found: '✗',
                            error:     '!',
                        }[this.status] || '';
                    },
                    statusClass() {
                        return {
                            ok:  this.status === 'found',
                            bad: this.status === 'not_found',
                            warn: this.status === 'error',
                        };
                    },
                    followerLine() {
                        const followers = formatFollowers(this.followerCount);
                        if (followers) return '@' + this.username + ' · ' + followers + ' pengikut';
                        return '@' + this.username;
                    },
                    async check() {
                        const value = (this.username || '').trim().replace(/^@/, '');
                        if (value === '' || value.length < 2) {
                            this.status = 'idle';
                            return;
                        }
                        if (value === this._lastChecked) return;
                        this._lastChecked = value;
                        this.status = 'checking';
                        try {
                            if (this._abort) this._abort.abort();
                            const controller = new AbortController();
                            this._abort = controller;
                            const data = await fetchHandle(endpoint, value, csrfToken);
                            if (controller.signal.aborted) return;
                            if (data.exists === true && data.status === 'found') {
                                this.status = 'found';
                                this.displayName  = data.display_name    || '';
                                this.profilePicUrl = data.profile_pic_url || '';
                                this.followerCount = data.follower_count  || 0;
                            } else if (data.status === 'not_found') {
                                this.status = 'not_found';
                                this.displayName = '';
                                this.profilePicUrl = '';
                                this.followerCount = 0;
                            } else {
                                this.status = 'error';
                            }
                        } catch (e) {
                            if (e.name === 'AbortError') return;
                            this.status = 'error';
                        }
                    },
                    commit() {
                        // Push current value into Livewire so the server-side
                        // normaliser hook fires and the value survives submit().
                        if (this.$wire && typeof this.$wire.set === 'function') {
                            const value = this.username && this.username.trim() !== ''
                                ? this.username
                                : null;
                            this.$wire.set(wireKey, value);
                        }
                    },
                };
            };
        }

        window.igHandleChecker = buildHandleComponent('/check-handle/instagram', 'instagramUsername');
        window.ttHandleChecker = buildHandleComponent('/check-handle/tiktok',    'tiktokUsername');

        window.whatsappValidator = function (initialValue) {
            return {
                number: initialValue || '',
                status: 'idle',     // idle | valid | invalid
                init() {
                    if (this.number) this.validate();
                },
                validate() {
                    const digits = (this.number || '').replace(/[^\d]/g, '').replace(/^(?:62|0)/, '');
                    if (digits === '') {
                        this.status = 'idle';
                        this.number = '';
                        return;
                    }
                    this.number = digits;
                    this.status = /^8\d{8,11}$/.test(digits) ? 'valid' : 'invalid';
                },
                waLink() {
                    return 'https://wa.me/62' + this.number;
                },
                commit() {
                    if (this.$wire && typeof this.$wire.set === 'function') {
                        const digits = (this.number || '').replace(/[^\d]/g, '').replace(/^(?:62|0)/, '');
                        const ok = /^8\d{8,11}$/.test(digits);
                        this.$wire.set('whatsappNumber', ok ? digits : null);
                    }
                },
            };
        };
    })();
    </script>
    @endpush
@endonce
