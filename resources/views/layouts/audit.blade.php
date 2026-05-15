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
        @if (request()->routeIs('audit.show'))
            <a href="{{ route('home') }}"
               style="font-size: 13px; font-weight: 500; color: var(--text-secondary); text-decoration: none; padding: 6px 14px; border-radius: var(--radius-pill); border: 1px solid var(--border-default); display: inline-flex; align-items: center; gap: 6px; background: var(--surface-card);"
               onmouseover="this.style.color='var(--text-primary)'; this.style.borderColor='var(--border-strong)';"
               onmouseout="this.style.color='var(--text-secondary)'; this.style.borderColor='var(--border-default)';">
                <i class="ti ti-arrow-left" style="font-size: 13px;"></i>
                Mulai audit baru
            </a>
        @endif
    </x-slot>

    @push('head')
        @livewireStyles
    @endpush

    @push('scripts')
        @livewireScripts
    @endpush

    {{ $slot }}
</x-nui-layouts.app>
