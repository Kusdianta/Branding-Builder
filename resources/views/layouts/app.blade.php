<x-nui-layouts.app
    :title="$title ?? 'Branding Builder — Brand Health Check Laundry'"
    brand="Branding Builder"
    :nav-home-url="config('app.hub_url', '/')"
>
    <x-slot name="nav">
        <a href="{{ config('app.hub_url', '/') }}"
           style="color: var(--text-secondary); font-size: 14px;">
            Semua Tools
        </a>
    </x-slot>

    @push('head')
        @livewireStyles
    @endpush

    @push('scripts')
        @livewireScripts
    @endpush

    {{ $slot }}
</x-nui-layouts.app>
