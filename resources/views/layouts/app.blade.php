{{--
    BB128 / BB129 — wizard + audit dashboard layout (default).

    Livewire 4's WrapsViewInLayout resolves Volt class-based components
    (`new class extends Component { ... }`) to this file regardless of
    any `layout('...')` call inside the Volt module — that helper only
    fires for *functional* Volt components. The wizard, audits index,
    and audit dashboard are all class-based, so the actually-effective
    layout is this one. Keep `layouts/audit.blade.php` aligned so any
    future component that picks it up explicitly stays consistent.

    Prior content here wrapped the page in `<x-nui-layouts.app>` with
    a "Branding Builder · Semua Tools" pill nav. That pill nav was
    visual clutter for an UMKM-facing audit flow. It is gone here.

    Page chrome now ships:
      - the same head bundle the nui-layouts.app component shipped
        (Geist fonts, tokens.css, components.css, Tabler icons, Vite
        when manifest/hot present, and the kit's Alpine factories in
        sync so uiKitModal/Toast register before Livewire's bundled
        Alpine fires alpine:init during HTML parsing)
      - a floating top-right widget with <x-credit-chip /> +
        <x-profile-nav /> (auth) or a Masuk button (guest), plus the
        "Mulai audit baru" pill on the audit.show route
      - the page slot
      - the toast stack from the kit (uiKitToast registered by
        ui-kit.js)

    Footer is intentionally dropped — the audit dashboard is already
    content-heavy and a footer that flickered into view after every
    multi-second Livewire update added no value.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Branding Builder — Brand Health Check Laundry' }}</title>

    {{-- Geist + Geist Mono, self-hosted via the kit's vendor path. --}}
    <style>
        @font-face {
            font-family: 'Geist';
            src: url('/vendor/nema-ui-kit/fonts/Geist-Regular.woff2') format('woff2');
            font-weight: 400;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: 'Geist';
            src: url('/vendor/nema-ui-kit/fonts/Geist-Medium.woff2') format('woff2');
            font-weight: 500;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: 'Geist';
            src: url('/vendor/nema-ui-kit/fonts/Geist-SemiBold.woff2') format('woff2');
            font-weight: 600;
            font-style: normal;
            font-display: swap;
        }
        @font-face {
            font-family: 'Geist Mono';
            src: url('/vendor/nema-ui-kit/fonts/GeistMono-Regular.woff2') format('woff2');
            font-weight: 400;
            font-style: normal;
            font-display: swap;
        }
    </style>

    <link rel="stylesheet" href="/vendor/nema-ui-kit/css/tokens.css">
    <link rel="stylesheet" href="/vendor/nema-ui-kit/css/components.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif

    <script src="/vendor/nema-ui-kit/js/ui-kit.js"></script>

    @livewireStyles
    @stack('head')
</head>
<body data-bb128-layout="audit-minimal" style="background: var(--surface-canvas); color: var(--text-primary); font-family: var(--font-sans); min-height: 100vh;">

    {{-- BB128 / BB129 — floating top-right corner widget. No branded
         nav, no "Semua Tools". Just the auth chrome (avatar dropdown +
         credit balance) so the user can sign out and see remaining
         credits from any wizard/audit page. The audit.show route also
         picks up a "Mulai audit baru" pill so users on the result page
         can start a fresh audit without going to the menu. --}}
    <div style="position: absolute; top: 20px; right: 24px; z-index: 40; display: inline-flex; align-items: center; gap: 12px;">
        @if (request()->routeIs('audit.show'))
            <a href="{{ route('home') }}"
               style="font-size: 13px; font-weight: 500; color: var(--text-secondary); text-decoration: none; padding: 6px 14px; border-radius: var(--radius-pill); border: 1px solid var(--border-default); display: inline-flex; align-items: center; gap: 6px; background: var(--surface-card);"
               onmouseover="this.style.color='var(--text-primary)'; this.style.borderColor='var(--border-strong)';"
               onmouseout="this.style.color='var(--text-secondary)'; this.style.borderColor='var(--border-default)';">
                <i class="ti ti-arrow-left" style="font-size: 13px;"></i>
                Mulai audit baru
            </a>
        @endif

        <x-credit-chip />
        <x-profile-nav />

        @guest
            <a href="{{ route('login') }}"
               style="font-size: 13px; font-weight: 500; color: var(--text-on-inverse); text-decoration: none; padding: 7px 16px; border-radius: var(--radius-pill); background: var(--surface-inverse); display: inline-flex; align-items: center; gap: 6px;">
                <i class="ti ti-brand-google" style="font-size: 13px;"></i>
                Masuk
            </a>
        @endguest
    </div>

    <main class="mx-auto w-full max-w-6xl px-4" style="padding-top: 72px; padding-bottom: 64px;">
        {{ $slot }}
    </main>

    {{-- Kit toast stack — Alpine x-data factory registered by ui-kit.js. --}}
    <div x-data="uiKitToast()" class="nui-toast-stack" x-cloak>
        <template x-for="t in toasts" :key="t.id">
            <div class="nui-card px-4 py-3"
                 :style="{ borderLeft: '3px solid ' + (t.type === 'success' ? 'var(--color-success)' : t.type === 'danger' ? 'var(--color-danger)' : 'var(--color-info)') }">
                <div class="flex items-start justify-between gap-3">
                    <p style="font-size: 14px;" x-text="t.message"></p>
                    <button @click="dismiss(t.id)" style="color: var(--text-tertiary); font-size: 14px;"
                            aria-label="Tutup notifikasi">&times;</button>
                </div>
            </div>
        </template>
    </div>

    @livewireScripts
    @stack('scripts')
</body>
</html>
