{{--
    BB123 — auth-aware profile dropdown rendered in the layout cta slot.

    Avatar + first-name button toggles an Alpine-driven dropdown showing
    full name + email, navigation to /audits and /, and POST logout via
    the `auth.logout` route (Google OAuth controller signs the user out).

    Replaces the inline BB87 markup that previously lived in
    layouts/audit.blade.php. Markup uses CSS variables instead of
    Tailwind chimera utilities — Tailwind v4 in this repo has no
    `bg-chimera-*` colors registered (per CLAUDE.md design-token rule).
--}}
@auth
    @php($_pnUser = auth()->user())
    <div x-data="{ open: false }" style="position: relative;">
        <button
            type="button"
            @click="open = !open"
            @click.outside="open = false"
            aria-label="Menu akun"
            style="display: inline-flex; align-items: center; gap: 8px; padding: 4px 8px 4px 4px; border-radius: var(--radius-pill); border: 1px solid var(--border-default); background: var(--surface-card); cursor: pointer; font-size: 13px; color: var(--text-primary); line-height: 1;"
            onmouseover="this.style.borderColor='var(--border-strong)'"
            onmouseout="this.style.borderColor='var(--border-default)'"
        >
            @if ($_pnUser->avatar_url)
                <img
                    src="{{ $_pnUser->avatar_url }}"
                    alt=""
                    referrerpolicy="no-referrer"
                    style="width: 28px; height: 28px; border-radius: 50%; object-fit: cover; display: block;"
                />
            @else
                <span style="width: 28px; height: 28px; border-radius: 50%; background: var(--chimera-100); color: var(--chimera-700); display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600;">
                    {{ strtoupper(mb_substr((string) ($_pnUser->name ?? '?'), 0, 1)) }}
                </span>
            @endif
            <span class="hidden md:inline" style="font-weight: 500; max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                {{ \Illuminate\Support\Str::words((string) $_pnUser->name, 1, '') ?: 'Akun' }}
            </span>
            <i class="ti ti-chevron-down" style="font-size: 12px; color: var(--text-tertiary);"></i>
        </button>

        <div
            x-show="open"
            x-transition.opacity
            x-cloak
            style="position: absolute; right: 0; top: calc(100% + 8px); min-width: 232px; background: var(--surface-card); border: 1px solid var(--border-default); border-radius: var(--radius-md); box-shadow: var(--shadow-popover); z-index: 50; overflow: hidden;"
        >
            {{-- User info header --}}
            <div style="padding: 12px 14px; border-bottom: 1px solid var(--border-default);">
                <p style="font-size: 13px; font-weight: 500; color: var(--text-primary); margin: 0;">
                    {{ $_pnUser->name }}
                </p>
                <p style="font-size: 11px; color: var(--text-tertiary); margin: 2px 0 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    {{ $_pnUser->email }}
                </p>
            </div>

            {{-- Navigation --}}
            <div style="padding: 6px;">
                <a
                    href="{{ route('audits.index') }}"
                    wire:navigate
                    style="display: flex; align-items: center; gap: 8px; padding: 8px 10px; font-size: 13px; color: var(--text-primary); text-decoration: none; border-radius: var(--radius-sm);"
                    onmouseover="this.style.background='var(--surface-muted)'"
                    onmouseout="this.style.background='transparent'"
                >
                    <i class="ti ti-history" style="font-size: 14px; color: var(--text-secondary);"></i>
                    Riwayat audit
                </a>
                <a
                    href="{{ route('home') }}"
                    wire:navigate
                    style="display: flex; align-items: center; gap: 8px; padding: 8px 10px; font-size: 13px; color: var(--text-primary); text-decoration: none; border-radius: var(--radius-sm);"
                    onmouseover="this.style.background='var(--surface-muted)'"
                    onmouseout="this.style.background='transparent'"
                >
                    <i class="ti ti-plus" style="font-size: 14px; color: var(--text-secondary);"></i>
                    Audit baru
                </a>
            </div>

            {{-- Logout --}}
            <div style="border-top: 1px solid var(--border-default); padding: 6px;">
                <form method="POST" action="{{ route('auth.logout') }}" style="margin: 0;">
                    @csrf
                    <button
                        type="submit"
                        style="width: 100%; text-align: left; display: flex; align-items: center; gap: 8px; padding: 8px 10px; font-size: 13px; color: var(--color-danger); background: none; border: none; cursor: pointer; border-radius: var(--radius-sm); font-family: inherit;"
                        onmouseover="this.style.background='var(--surface-muted)'"
                        onmouseout="this.style.background='transparent'"
                    >
                        <i class="ti ti-logout" style="font-size: 14px;"></i>
                        Keluar
                    </button>
                </form>
            </div>
        </div>
    </div>
@endauth
