<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title . ' — ' : '' }}Branding Builder</title>

    <style>
        @font-face {
            font-family: 'Geist';
            src: url('/vendor/nema-ui-kit/fonts/Geist-Regular.woff2') format('woff2');
            font-weight: 400; font-style: normal; font-display: swap;
        }
        @font-face {
            font-family: 'Geist';
            src: url('/vendor/nema-ui-kit/fonts/Geist-Medium.woff2') format('woff2');
            font-weight: 500; font-style: normal; font-display: swap;
        }
        @font-face {
            font-family: 'Geist';
            src: url('/vendor/nema-ui-kit/fonts/Geist-SemiBold.woff2') format('woff2');
            font-weight: 600; font-style: normal; font-display: swap;
        }
    </style>

    <link rel="stylesheet" href="/vendor/nema-ui-kit/css/tokens.css">
    <link rel="stylesheet" href="/vendor/nema-ui-kit/css/components.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body style="background: var(--surface-canvas); color: var(--text-primary); font-family: var(--font-sans);" class="min-h-screen antialiased">

    <header class="sticky top-4 z-40 px-4">
        <nav class="nui-nav-pill mx-auto flex max-w-4xl items-center justify-between px-4 py-2">
            <a href="/" class="flex items-center gap-2">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-full"
                      style="background: var(--chimera-500); color: var(--text-on-primary);">
                    <i class="ti ti-brand-speedtest" style="font-size: 16px;"></i>
                </span>
                <span style="font-weight: 600; font-size: 15px;">Branding Builder</span>
            </a>
            <span style="font-size: 12px; color: var(--text-tertiary);">AI Brand Health Check</span>
        </nav>
    </header>

    <main class="mx-auto w-full max-w-4xl px-4 py-8">
        {{ $slot }}
    </main>

    <footer style="border-top: 1px solid var(--border-default); margin-top: 64px;">
        <div class="mx-auto flex w-full max-w-4xl items-center justify-between px-4 py-4"
             style="font-size: 12px; color: var(--text-tertiary);">
            <span>Branding Builder · Nema Platform</span>
            <span>© {{ date('Y') }}</span>
        </div>
    </footer>

    <div x-data="uiKitToast()" class="nui-toast-stack" x-cloak>
        <template x-for="t in toasts" :key="t.id">
            <div class="nui-card px-4 py-3"
                 :style="{ borderLeft: '3px solid ' + (t.type === 'success' ? 'var(--color-success)' : t.type === 'danger' ? 'var(--color-danger)' : 'var(--color-info)') }">
                <div class="flex items-start justify-between gap-3">
                    <p style="font-size: 14px;" x-text="t.message"></p>
                    <button @click="dismiss(t.id)" style="color: var(--text-tertiary);">&times;</button>
                </div>
            </div>
        </template>
    </div>

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script defer src="/vendor/nema-ui-kit/js/ui-kit.js"></script>
    @livewireScripts
</body>
</html>
