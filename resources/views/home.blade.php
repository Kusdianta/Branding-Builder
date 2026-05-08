@extends('layouts.app')
@section('title', 'Brand Kit Generator')

@section('content')
<div class="max-w-3xl mx-auto px-6 py-16">

    <!-- Hero -->
    <div class="mb-14">
        <div class="inline-flex items-center gap-2 bg-[#3D8948]/15 border border-[#3D8948]/30 rounded-full px-4 py-1.5 text-sm text-[#4aaa57] font-semibold mb-6 uppercase tracking-widest">
            Social Media Brand Kit
        </div>
        <h1 class="font-black text-4xl sm:text-5xl text-white leading-tight mb-4 tracking-tight">
            Bangun identitas brand<br>
            <span class="text-[#3D8948]">laundry kamu</span> di sosmed.
        </h1>
        <p class="text-slate-400 text-base leading-relaxed max-w-xl">
            Isi formulir di bawah, dan kami akan generate brand narrative, content pillars, caption contoh, dan story mapping siap pakai — dalam hitungan detik.
        </p>
    </div>

    <!-- API Key Warning -->
    @if(empty(config('services.anthropic.key')))
    <div class="mb-8 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 flex gap-3">
        <svg class="w-5 h-5 text-amber-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        <div>
            <p class="text-amber-400 font-semibold text-sm">API Key Belum Diset</p>
            <p class="text-amber-300/70 text-sm mt-0.5">Buka file <code class="bg-amber-500/20 px-1.5 py-0.5 rounded font-mono text-xs">.env</code> dan isi nilai <code class="bg-amber-500/20 px-1.5 py-0.5 rounded font-mono text-xs">ANTHROPIC_API_KEY</code>.</p>
        </div>
    </div>
    @endif

    @if($errors->has('api'))
    <div class="mb-8 rounded-xl border border-red-500/30 bg-red-500/10 p-4 flex gap-3">
        <svg class="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <div>
            <p class="text-red-400 font-semibold text-sm">Terjadi Kesalahan</p>
            <p class="text-red-300/70 text-sm mt-0.5">{{ $errors->first('api') }}</p>
        </div>
    </div>
    @endif

    <!-- Form -->
    <form action="/generate" method="POST" id="brandForm" class="space-y-5">
        @csrf

        <!-- Row 1 -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Nama Bisnis Laundry <span class="text-[#3D8948]">*</span></label>
                <input type="text" name="business_name" value="{{ old('business_name') }}" placeholder="cth. Laundry Bersih Kilat" required
                    class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-[#3D8948] focus:border-transparent transition-all text-sm font-medium">
                @error('business_name')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Lokasi / Kota <span class="text-[#3D8948]">*</span></label>
                <input type="text" name="location" value="{{ old('location') }}" placeholder="cth. Surabaya, Jawa Timur" required
                    class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-[#3D8948] focus:border-transparent transition-all text-sm font-medium">
                @error('location')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        <!-- Row 2 -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Jenis Layanan <span class="text-[#3D8948]">*</span></label>
                <select name="service_type" required
                    class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white focus:outline-none focus:ring-2 focus:ring-[#3D8948] focus:border-transparent transition-all text-sm font-medium appearance-none">
                    <option value="" class="bg-[#0b1015]">Pilih jenis layanan...</option>
                    <option value="Laundry Kiloan" {{ old('service_type') == 'Laundry Kiloan' ? 'selected' : '' }} class="bg-[#0b1015]">Laundry Kiloan</option>
                    <option value="Laundry Satuan / Dry Cleaning" {{ old('service_type') == 'Laundry Satuan / Dry Cleaning' ? 'selected' : '' }} class="bg-[#0b1015]">Laundry Satuan / Dry Cleaning</option>
                    <option value="Pickup & Delivery" {{ old('service_type') == 'Pickup & Delivery' ? 'selected' : '' }} class="bg-[#0b1015]">Pickup & Delivery</option>
                    <option value="Express Laundry (1-3 jam)" {{ old('service_type') == 'Express Laundry (1-3 jam)' ? 'selected' : '' }} class="bg-[#0b1015]">Express Laundry (1–3 jam)</option>
                    <option value="Laundry Sepatu" {{ old('service_type') == 'Laundry Sepatu' ? 'selected' : '' }} class="bg-[#0b1015]">Laundry Sepatu</option>
                    <option value="Laundry Lengkap (Kiloan + Satuan + Pickup)" {{ old('service_type') == 'Laundry Lengkap (Kiloan + Satuan + Pickup)' ? 'selected' : '' }} class="bg-[#0b1015]">Laundry Lengkap</option>
                </select>
                @error('service_type')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Segmen Harga <span class="text-[#3D8948]">*</span></label>
                <select name="price_segment" required
                    class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white focus:outline-none focus:ring-2 focus:ring-[#3D8948] focus:border-transparent transition-all text-sm font-medium appearance-none">
                    <option value="" class="bg-[#0b1015]">Pilih segmen...</option>
                    <option value="Budget / Ekonomis (di bawah Rp 6.000/kg)" {{ old('price_segment') == 'Budget / Ekonomis (di bawah Rp 6.000/kg)' ? 'selected' : '' }} class="bg-[#0b1015]">Budget / Ekonomis</option>
                    <option value="Menengah (Rp 6.000–10.000/kg)" {{ old('price_segment') == 'Menengah (Rp 6.000–10.000/kg)' ? 'selected' : '' }} class="bg-[#0b1015]">Menengah</option>
                    <option value="Premium (di atas Rp 10.000/kg)" {{ old('price_segment') == 'Premium (di atas Rp 10.000/kg)' ? 'selected' : '' }} class="bg-[#0b1015]">Premium</option>
                </select>
                @error('price_segment')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        <!-- Target Customer -->
        <div>
            <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Target Pelanggan <span class="text-[#3D8948]">*</span></label>
            <input type="text" name="target_customer" value="{{ old('target_customer') }}" placeholder="cth. Mahasiswa kost usia 18–25, tidak punya waktu banyak" required
                class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-[#3D8948] focus:border-transparent transition-all text-sm font-medium">
            @error('target_customer')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
        </div>

        <!-- Differentiator -->
        <div>
            <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Keunggulan Utama Bisnis <span class="text-[#3D8948]">*</span></label>
            <textarea name="differentiator" rows="2" placeholder="cth. Selesai dalam 3 jam, antar jemput gratis radius 2km, parfum premium, tidak merusak bahan" required
                class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-[#3D8948] focus:border-transparent transition-all text-sm font-medium resize-none">{{ old('differentiator') }}</textarea>
            @error('differentiator')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
        </div>

        <!-- Brand Personality -->
        <div>
            <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Kepribadian / Tone Brand <span class="text-[#3D8948]">*</span></label>
            <input type="text" name="brand_personality" value="{{ old('brand_personality') }}" placeholder="cth. Friendly, dekat dengan anak muda, kasual tapi profesional" required
                class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-[#3D8948] focus:border-transparent transition-all text-sm font-medium">
            @error('brand_personality')<p class="text-red-400 text-xs mt-1">{{ $message }}</p>@enderror
        </div>

        <!-- Competitors -->
        <div>
            <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Kompetitor <span class="text-slate-600 font-medium normal-case tracking-normal">(opsional)</span></label>
            <input type="text" name="competitors" value="{{ old('competitors') }}" placeholder="cth. Laundrybag, MamaBear Laundry, laundry kiloan sekitar kost"
                class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-[#3D8948] focus:border-transparent transition-all text-sm font-medium">
        </div>

        <!-- Submit -->
        <div class="pt-4">
            <button type="submit" id="submitBtn"
                class="w-full bg-[#3D8948] hover:bg-[#4aaa57] text-white font-bold py-4 px-8 rounded-xl transition-all duration-200 shadow-lg shadow-[#3D8948]/20 hover:shadow-[#3D8948]/40 disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-3 text-sm uppercase tracking-widest">
                <svg id="btnIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
                <span id="btnText">Generate Brand Kit</span>
            </button>
            <p class="text-center text-slate-600 text-xs mt-3 font-medium">Proses biasanya 1–3 menit</p>
        </div>
    </form>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="fixed inset-0 bg-[#0b1015]/95 backdrop-blur-sm z-50 hidden flex-col items-center justify-center">
    <div class="text-center px-6 max-w-sm">

        <!-- Animated rings -->
        <div class="relative w-24 h-24 mx-auto mb-10">
            <div class="absolute inset-0 rounded-full border-4 border-white/5"></div>
            <div class="absolute inset-0 rounded-full border-4 border-transparent border-t-[#3D8948] animate-spin"></div>
            <div class="absolute inset-2 rounded-full border-4 border-transparent border-t-[#3D8948]/40 animate-spin" style="animation-duration:1.5s;animation-direction:reverse;"></div>
            <div class="absolute inset-0 flex items-center justify-center">
                <svg class="w-8 h-8 text-[#3D8948]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            </div>
        </div>

        <h2 class="font-black text-2xl text-white mb-3 tracking-tight">Sedang membuat<br>brand kit kamu…</h2>
        <p class="text-slate-500 text-sm leading-relaxed mb-8">Claude AI sedang menganalisis bisnis kamu dan menyusun strategi brand lengkap. Mohon tunggu, jangan tutup halaman ini.</p>

        <!-- Steps -->
        <div class="space-y-3 text-left">
            <div class="step-item flex items-center gap-3 opacity-40 transition-all duration-500" id="step1">
                <div class="w-6 h-6 rounded-full border-2 border-slate-600 flex items-center justify-center flex-shrink-0">
                    <div class="w-2 h-2 rounded-full bg-slate-600"></div>
                </div>
                <span class="text-slate-400 text-sm font-medium">Menganalisis data bisnis</span>
            </div>
            <div class="step-item flex items-center gap-3 opacity-40 transition-all duration-500" id="step2">
                <div class="w-6 h-6 rounded-full border-2 border-slate-600 flex items-center justify-center flex-shrink-0">
                    <div class="w-2 h-2 rounded-full bg-slate-600"></div>
                </div>
                <span class="text-slate-400 text-sm font-medium">Menyusun brand narrative</span>
            </div>
            <div class="step-item flex items-center gap-3 opacity-40 transition-all duration-500" id="step3">
                <div class="w-6 h-6 rounded-full border-2 border-slate-600 flex items-center justify-center flex-shrink-0">
                    <div class="w-2 h-2 rounded-full bg-slate-600"></div>
                </div>
                <span class="text-slate-400 text-sm font-medium">Membuat content pillars</span>
            </div>
            <div class="step-item flex items-center gap-3 opacity-40 transition-all duration-500" id="step4">
                <div class="w-6 h-6 rounded-full border-2 border-slate-600 flex items-center justify-center flex-shrink-0">
                    <div class="w-2 h-2 rounded-full bg-slate-600"></div>
                </div>
                <span class="text-slate-400 text-sm font-medium">Menulis caption examples</span>
            </div>
        </div>

        <!-- Timer -->
        <p class="text-slate-700 text-xs mt-8 font-medium" id="timerText">0 detik berlalu…</p>
    </div>
</div>

<script>
document.getElementById('brandForm').addEventListener('submit', function() {
    const btn     = document.getElementById('submitBtn');
    const overlay = document.getElementById('loadingOverlay');

    btn.disabled = true;
    overlay.classList.remove('hidden');
    overlay.classList.add('flex');

    // Animate steps
    const steps    = ['step1','step2','step3','step4'];
    const delays   = [800, 4000, 10000, 20000];
    steps.forEach((id, i) => {
        setTimeout(() => {
            const el = document.getElementById(id);
            el.classList.remove('opacity-40');
            el.classList.add('opacity-100');
            el.querySelector('.w-6').style.borderColor = '#3D8948';
            el.querySelector('.w-2').style.background  = '#3D8948';
            el.querySelector('span').style.color = '#ffffff';
        }, delays[i]);
    });

    // Timer
    let secs = 0;
    setInterval(() => {
        secs++;
        document.getElementById('timerText').textContent = secs + ' detik berlalu…';
    }, 1000);
});
</script>
@endsection
