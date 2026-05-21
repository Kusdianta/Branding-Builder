{{--
    BB128 / BB129 — secondary layout file kept in sync with
    layouts/app.blade.php.

    Livewire 4's WrapsViewInLayout only honors Volt's `layout('...')`
    helper for *functional* components — class-based Volt components
    (`new class extends Component { ... }`) silently fall back to the
    default `layouts.app`. Every Volt page in branding-builder is
    class-based, so the actually-rendered layout is layouts/app.blade.php.
    This file remains here as the namesake for the in-Volt
    `layout('layouts.audit')` declarations so that contract isn't a
    dangling reference, and so any future component that picks it up
    explicitly (or any future Volt release that honors the call for
    class components) gets the same minimal chrome.

    Replaces the prior `<x-nui-layouts.app>` wrapper, which rendered a
    branded "Branding Builder · Semua Tools" pill nav at the top. The
    pill nav was visual clutter for an UMKM audit flow and (when the
    component's $cta slot didn't render the auth chip) made it look
    like authenticated state was missing entirely.

    Instead, the page now ships:
      - the same head bundle the nui-layouts.app component shipped
        (Geist fonts, tokens.css, components.css, Tabler icons, Vite
        when manifest/hot present, the kit's Alpine factories in sync)
      - a floating top-right widget with <x-credit-chip /> +
        <x-profile-nav /> (auth) or a Masuk button (guest)
      - the page slot
      - the toast stack from the kit (uiKitToast registered in
        ui-kit.js)

    Footer is intentionally dropped — the audit dashboard is content-
    heavy already and a footer rendered at the end of multi-second
    Livewire updates flickered into view repeatedly.
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

    {{-- Kit Alpine factories — loaded sync in <head> so the alpine:init
         listener registers BEFORE Livewire's bundled Alpine fires the
         event during HTML parsing. Same ordering rationale as
         nema-ui-kit/resources/views/components/layouts/app.blade.php. --}}
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
            <a href="{{ route('auth.google.redirect') }}"
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
