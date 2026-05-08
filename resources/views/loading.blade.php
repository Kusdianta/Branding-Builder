@extends('layouts.app')
@section('title', 'Generating Brand Kit...')

@section('content')
<div class="max-w-xl mx-auto px-6 py-32 text-center">

    <div id="spinner" class="mb-8 flex justify-center">
        <div class="w-16 h-16 rounded-full border-4 border-white/10 border-t-[#3D8948] animate-spin"></div>
    </div>

    <div id="checkmark" class="mb-8 hidden justify-center">
        <div class="w-16 h-16 rounded-full bg-[#3D8948]/20 border-2 border-[#3D8948] flex items-center justify-center">
            <svg class="w-8 h-8 text-[#3D8948]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
            </svg>
        </div>
    </div>

    <h1 class="font-black text-2xl text-white mb-3" id="statusTitle">Sedang membuat brand kit kamu…</h1>
    <p class="text-slate-500 text-sm" id="statusDesc">Claude AI sedang menganalisis bisnis kamu dan menyusun strategi brand. Biasanya selesai dalam 2–5 menit.</p>

    <div class="mt-10 flex justify-center gap-1.5" id="dots">
        <span class="w-2 h-2 rounded-full bg-[#3D8948] animate-bounce" style="animation-delay:0ms"></span>
        <span class="w-2 h-2 rounded-full bg-[#3D8948] animate-bounce" style="animation-delay:150ms"></span>
        <span class="w-2 h-2 rounded-full bg-[#3D8948] animate-bounce" style="animation-delay:300ms"></span>
    </div>

    <div id="errorBox" class="hidden mt-8 bg-red-500/10 border border-red-500/30 rounded-xl p-5">
        <p class="text-red-400 font-semibold text-sm mb-2">Terjadi Kesalahan</p>
        <p class="text-red-300/70 text-sm" id="errorMsg"></p>
        <a href="/" class="inline-block mt-4 text-xs font-bold uppercase tracking-widest text-slate-400 hover:text-white transition-colors">← Coba Lagi</a>
    </div>

</div>

<script>
const token = "{{ $token }}";
let attempts = 0;

function poll() {
    fetch('/status?token=' + token)
        .then(r => r.json())
        .then(data => {
            attempts++;

            if (data.status === 'done') {
                document.getElementById('spinner').classList.add('hidden');
                document.getElementById('checkmark').classList.remove('hidden');
                document.getElementById('checkmark').classList.add('flex');
                document.getElementById('dots').classList.add('hidden');
                document.getElementById('statusTitle').textContent = 'Brand kit selesai!';
                document.getElementById('statusDesc').textContent = 'Mengalihkan ke halaman hasil…';
                setTimeout(() => { window.location.href = '/results'; }, 800);

            } else if (data.status === 'error') {
                document.getElementById('spinner').classList.add('hidden');
                document.getElementById('dots').classList.add('hidden');
                document.getElementById('statusTitle').textContent = 'Gagal generate';
                document.getElementById('statusDesc').textContent = '';
                document.getElementById('errorBox').classList.remove('hidden');
                document.getElementById('errorMsg').textContent = data.message || 'Unknown error';

            } else if (data.status === 'expired') {
                window.location.href = '/';

            } else {
                // still pending — poll again
                const delay = attempts < 10 ? 3000 : 5000;
                setTimeout(poll, delay);
            }
        })
        .catch(() => setTimeout(poll, 5000));
}

// Start polling after 3 seconds
setTimeout(poll, 3000);
</script>
@endsection
