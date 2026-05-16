<x-nui-layouts.app
    :title="$title ?? 'Branding Builder — Brand Health Check Laundry'"
    brand="Branding Builder"
    :nav-home-url="config('app.hub_url', '/')"
>
    {{-- BB33: minimal audit-focused nav.
         The 'Semua Tools' link from the prior layouts.app pulled users
         away from their in-progress audit results. The middle nav slot
         is intentionally left empty here; the right-side cta slot only
         renders 'Mulai audit baru' on the results route — on the form
         route ('home') the chrome stays clean since the user is already
         starting a new audit. --}}
    <x-slot name="cta">
        <div style="display: inline-flex; align-items: center; gap: 12px;">
            @if (request()->routeIs('audit.show'))
                <a href="{{ route('home') }}"
                   style="font-size: 13px; font-weight: 500; color: var(--text-secondary); text-decoration: none; padding: 6px 14px; border-radius: var(--radius-pill); border: 1px solid var(--border-default); display: inline-flex; align-items: center; gap: 6px; background: var(--surface-card);"
                   onmouseover="this.style.color='var(--text-primary)'; this.style.borderColor='var(--border-strong)';"
                   onmouseout="this.style.color='var(--text-secondary)'; this.style.borderColor='var(--border-default)';">
                    <i class="ti ti-arrow-left" style="font-size: 13px;"></i>
                    Mulai audit baru
                </a>
            @endif

            {{-- BB87: auth-aware header chip. Credits badge + avatar dropdown
                 when signed in; "Masuk" CTA when guest. --}}
            @auth
                @php($user = auth()->user())
                <span title="Sisa kredit untuk audit baru" style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: var(--radius-pill); background: var(--chimera-50); color: var(--chimera-700); border: 1px solid var(--chimera-100);">
                    <i class="ti ti-coin" style="font-size: 12px;"></i>
                    {{ (int) $user->credits_balance }} kredit
                </span>

                <div x-data="{ open: false }" style="position: relative;">
                    <button
                        type="button"
                        @click="open = !open"
                        @click.outside="open = false"
                        style="display: inline-flex; align-items: center; gap: 8px; padding: 4px 6px 4px 4px; border-radius: var(--radius-pill); border: 1px solid var(--border-default); background: var(--surface-card); cursor: pointer; font-size: 13px; color: var(--text-primary);"
                    >
                        @if ($user->avatar_url)
                            <img src="{{ $user->avatar_url }}" alt="" style="width: 26px; height: 26px; border-radius: 50%; object-fit: cover;" referrerpolicy="no-referrer" />
                        @else
                            <span style="width: 26px; height: 26px; border-radius: 50%; background: var(--chimera-100); color: var(--chimera-700); display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600;">
                                {{ strtoupper(mb_substr($user->name ?? '?', 0, 1)) }}
                            </span>
                        @endif
                        <span style="max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ explode(' ', (string) $user->name)[0] ?? 'Akun' }}</span>
                        <i class="ti ti-chevron-down" style="font-size: 12px; color: var(--text-tertiary);"></i>
                    </button>

                    <div
                        x-show="open"
                        x-transition.opacity
                        x-cloak
                        style="position: absolute; right: 0; top: calc(100% + 6px); min-width: 200px; background: var(--surface-card); border: 1px solid var(--border-default); border-radius: var(--radius-md); box-shadow: var(--shadow-popover); padding: 6px; z-index: 50;"
                    >
                        <a href="{{ route('audits.index') }}" wire:navigate style="display: flex; align-items: center; gap: 8px; padding: 8px 10px; font-size: 13px; color: var(--text-primary); text-decoration: none; border-radius: var(--radius-sm);"
                           onmouseover="this.style.background='var(--surface-muted)'"
                           onmouseout="this.style.background='transparent'">
                            <i class="ti ti-history"></i> Riwayat audit
                        </a>
                        <a href="{{ route('home') }}" wire:navigate style="display: flex; align-items: center; gap: 8px; padding: 8px 10px; font-size: 13px; color: var(--text-primary); text-decoration: none; border-radius: var(--radius-sm);"
                           onmouseover="this.style.background='var(--surface-muted)'"
                           onmouseout="this.style.background='transparent'">
                            <i class="ti ti-plus"></i> Audit baru
                        </a>
                        <form method="POST" action="{{ route('auth.logout') }}" style="margin: 0;">
                            @csrf
                            <button type="submit" style="width: 100%; text-align: left; display: flex; align-items: center; gap: 8px; padding: 8px 10px; font-size: 13px; color: var(--color-danger); background: none; border: none; cursor: pointer; border-radius: var(--radius-sm);"
                                onmouseover="this.style.background='var(--surface-muted)'"
                                onmouseout="this.style.background='transparent'">
                                <i class="ti ti-logout"></i> Keluar
                            </button>
                        </form>
                    </div>
                </div>
            @endauth

            @guest
                <a href="{{ route('auth.google.redirect') }}"
                   style="font-size: 13px; font-weight: 500; color: var(--text-on-inverse); text-decoration: none; padding: 7px 16px; border-radius: var(--radius-pill); background: var(--surface-inverse); display: inline-flex; align-items: center; gap: 6px;">
                    <i class="ti ti-brand-google" style="font-size: 13px;"></i>
                    Masuk
                </a>
            @endguest
        </div>
    </x-slot>

    @push('head')
        @livewireStyles
    @endpush

    @push('scripts')
        @livewireScripts
    @endpush

    {{ $slot }}
</x-nui-layouts.app>
