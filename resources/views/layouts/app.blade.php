<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Brand Kit Generator') — Chimera Creative</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        * { font-family: 'Montserrat', sans-serif; }
    </style>
</head>
<body class="min-h-full bg-[#0b1015] text-slate-100 antialiased">

    <!-- Nav -->
    <header class="border-b border-white/5 bg-[#0b1015]/90 backdrop-blur-sm sticky top-0 z-50">
        <div class="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between">
            <a href="/" class="flex items-center gap-3 group">
                <!-- Chimera Creative logo (SVG recreation) -->
                <div class="flex items-center gap-2.5">
                    <svg width="36" height="36" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <!-- Rook chess piece simplified -->
                        <rect x="25" y="10" width="12" height="15" rx="2" fill="#3D8948"/>
                        <rect x="43" y="10" width="14" height="15" rx="2" fill="#3D8948"/>
                        <rect x="63" y="10" width="12" height="15" rx="2" fill="#3D8948"/>
                        <rect x="25" y="22" width="50" height="10" rx="2" fill="#3D8948"/>
                        <rect x="28" y="30" width="44" height="35" rx="3" fill="#3D8948"/>
                        <!-- Face -->
                        <circle cx="40" cy="46" r="4" fill="#2a6335"/>
                        <circle cx="60" cy="46" r="4" fill="#2a6335"/>
                        <path d="M40 56 Q50 61 60 56" stroke="#2a6335" stroke-width="2.5" stroke-linecap="round" fill="none"/>
                        <!-- Body/base -->
                        <rect x="22" y="63" width="56" height="8" rx="2" fill="#4aaa57"/>
                        <rect x="18" y="70" width="64" height="8" rx="3" fill="#3D8948"/>
                        <!-- Shadow -->
                        <ellipse cx="50" cy="83" rx="28" ry="5" fill="#2a6335" opacity="0.5"/>
                    </svg>
                    <div class="leading-tight">
                        <div class="font-black text-white text-lg leading-none tracking-tight">chimera</div>
                        <div class="font-black text-white text-lg leading-none tracking-tight">creative</div>
                    </div>
                </div>
            </a>
            <div class="flex items-center gap-3">
                <span class="text-xs text-slate-500 font-medium uppercase tracking-widest hidden sm:block">Brand Kit Generator</span>
                <span class="w-1.5 h-1.5 rounded-full bg-[#3D8948] hidden sm:block"></span>
                <span class="text-xs text-slate-500 font-medium">Powered by Claude AI</span>
            </div>
        </div>
    </header>

    <main>
        @yield('content')
    </main>

    <footer class="border-t border-white/5 mt-20">
        <div class="max-w-6xl mx-auto px-6 py-6 flex flex-col sm:flex-row items-center justify-between gap-3">
            <span class="text-xs text-slate-600">© {{ date('Y') }} Chimera Creative. All rights reserved.</span>
            <span class="text-xs text-slate-600">Social Media Marketing untuk Bisnis Laundry</span>
        </div>
    </footer>

</body>
</html>
