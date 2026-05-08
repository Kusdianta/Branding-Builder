@extends('layouts.app')
@section('title', 'Brand Kit — ' . $brandData['business_name'])

@section('content')
<div class="max-w-5xl mx-auto px-6 py-14">

    <!-- Header -->
    <div class="mb-10">
        <a href="/" class="inline-flex items-center gap-2 text-slate-500 hover:text-slate-300 text-xs font-semibold uppercase tracking-widest transition-colors mb-8">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Buat Brand Kit Baru
        </a>
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-6">
            <div>
                <p class="text-xs font-bold text-[#3D8948] uppercase tracking-widest mb-2">Brand Activation Kit</p>
                <h1 class="font-black text-4xl text-white tracking-tight">{{ $brandData['business_name'] }}</h1>
                <p class="text-slate-500 text-sm mt-1 font-medium">{{ $brandData['location'] }} &middot; {{ $brandData['service_type'] }}</p>
            </div>
            <a href="/download"
                class="inline-flex items-center gap-2.5 bg-[#3D8948] hover:bg-[#4aaa57] text-white font-bold px-6 py-3 rounded-xl transition-all text-sm uppercase tracking-widest shadow-lg shadow-[#3D8948]/20 flex-shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Download PDF
            </a>
        </div>
    </div>

    <div class="space-y-6">

        {{-- ===== 01 BRAND NARRATIVE ===== --}}
        @if(!empty($brandKit['brand_narrative']))
        @php $bn = $brandKit['brand_narrative']; @endphp
        <section class="bg-white/[0.03] rounded-2xl border border-white/8 overflow-hidden">
            <div class="bg-[#3D8948]/10 border-b border-white/5 px-8 py-4 flex items-center gap-3">
                <span class="text-xs font-black text-[#3D8948] uppercase tracking-widest">01</span>
                <span class="text-sm font-black text-white uppercase tracking-widest">Brand Narrative</span>
            </div>
            <div class="p-8">
                {{-- Tagline --}}
                <div class="mb-8 pb-8 border-b border-white/5">
                    <p class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-3">Tagline</p>
                    <p class="text-3xl font-black text-white leading-tight">"{{ $bn['tagline'] }}"</p>
                </div>
                {{-- Big Narrative --}}
                <div>
                    <p class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-2">Big Narrative</p>
                    <p class="text-xl font-black text-[#3D8948] mb-4 leading-tight">{{ $bn['big_narrative_title'] }}</p>
                    <div class="text-slate-300 text-sm leading-relaxed space-y-3">
                        @foreach(explode("\n", $bn['big_narrative_body']) as $para)
                            @if(trim($para)) <p>{{ trim($para) }}</p> @endif
                        @endforeach
                    </div>
                </div>
            </div>
        </section>
        @endif

        {{-- ===== 02 NARRATIVE PILLARS ===== --}}
        @if(!empty($brandKit['narrative_pillars']))
        @php $np = $brandKit['narrative_pillars']; @endphp
        <section class="bg-white/[0.03] rounded-2xl border border-white/8 overflow-hidden">
            <div class="bg-[#3D8948]/10 border-b border-white/5 px-8 py-4 flex items-center gap-3">
                <span class="text-xs font-black text-[#3D8948] uppercase tracking-widest">02</span>
                <span class="text-sm font-black text-white uppercase tracking-widest">Narrative Pillars</span>
            </div>
            <div class="grid sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-white/5">
                {{-- Problem Layer --}}
                @if(!empty($np['problem_layer']))
                @php $pl = $np['problem_layer']; @endphp
                <div class="p-6">
                    <p class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-1">Problem Layer</p>
                    <p class="font-black text-white text-lg leading-tight mb-4">{{ $pl['world_title'] }}</p>
                    <ul class="space-y-2 mb-4">
                        @foreach($pl['problems'] as $p)
                        <li class="flex items-start gap-2 text-slate-400 text-xs font-medium">
                            <span class="w-1 h-1 rounded-full bg-[#3D8948] flex-shrink-0 mt-1.5"></span>
                            {{ $p }}
                        </li>
                        @endforeach
                    </ul>
                    <p class="text-xs text-[#3D8948] font-bold uppercase tracking-wider">Tone: {{ $pl['content_tone'] }}</p>
                </div>
                @endif

                {{-- Belief Layer --}}
                @if(!empty($np['belief_layer']))
                @php $bl = $np['belief_layer']; @endphp
                <div class="p-6">
                    <p class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-1">Belief Layer</p>
                    <p class="font-black text-white text-lg leading-tight mb-4">{{ $bl['mindset_title'] }}</p>
                    <div class="space-y-3 mb-4">
                        <div class="bg-red-500/10 border border-red-500/20 rounded-lg p-3">
                            <p class="text-xs font-bold text-red-400 mb-1">Dulu</p>
                            <p class="text-slate-400 text-xs">{{ $bl['old_belief'] }}</p>
                        </div>
                        <div class="bg-[#3D8948]/10 border border-[#3D8948]/20 rounded-lg p-3">
                            <p class="text-xs font-bold text-[#3D8948] mb-1">Sekarang</p>
                            <p class="text-slate-300 text-xs">{{ $bl['new_belief'] }}</p>
                        </div>
                    </div>
                    <p class="text-xs text-white font-semibold italic">"{{ $bl['key_message'] }}"</p>
                </div>
                @endif

                {{-- Action Layer --}}
                @if(!empty($np['action_layer']))
                @php $al = $np['action_layer']; @endphp
                <div class="p-6">
                    <p class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-1">Action Layer</p>
                    <p class="font-black text-white text-lg leading-tight mb-4">{{ $al['ritual_title'] }}</p>
                    <div class="mb-4">
                        <p class="text-xs font-bold text-slate-500 mb-2">Momen Trigger</p>
                        <ul class="space-y-1.5">
                            @foreach($al['trigger_moments'] as $moment)
                            <li class="text-slate-400 text-xs font-medium flex items-start gap-2">
                                <span class="text-[#3D8948]">→</span> {{ $moment }}
                            </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="bg-[#3D8948]/10 border border-[#3D8948]/20 rounded-lg p-3">
                        <p class="text-[#3D8948] text-xs font-bold">{{ $al['cta'] }}</p>
                    </div>
                </div>
                @endif
            </div>
        </section>
        @endif

        {{-- ===== 03 CONTENT STORY MAPPING ===== --}}
        @if(!empty($brandKit['content_story_mapping']))
        @php $csm = $brandKit['content_story_mapping']; @endphp
        <section class="bg-white/[0.03] rounded-2xl border border-white/8 overflow-hidden">
            <div class="bg-[#3D8948]/10 border-b border-white/5 px-8 py-4 flex items-center gap-3">
                <span class="text-xs font-black text-[#3D8948] uppercase tracking-widest">03</span>
                <span class="text-sm font-black text-white uppercase tracking-widest">Content Story Mapping</span>
            </div>
            <div class="p-8 space-y-8">
                {{-- Macro Story --}}
                @if(!empty($csm['macro_story']))
                @php $ms = $csm['macro_story']; @endphp
                <div class="pb-8 border-b border-white/5">
                    <div class="flex items-baseline gap-3 mb-4">
                        <span class="text-xs font-black text-[#3D8948] uppercase tracking-widest">Macro Story</span>
                        <span class="text-xs text-slate-600 font-medium">Brand Level 1–2 Tahun</span>
                    </div>
                    <p class="text-2xl font-black text-white mb-6 leading-tight">"{{ $ms['umbrella_narrative'] }}"</p>
                    <div class="grid sm:grid-cols-2 gap-4">
                        @if(!empty($ms['brand_beliefs']))
                        <div>
                            <p class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-3">Brand Percaya</p>
                            <ul class="space-y-2">
                                @foreach($ms['brand_beliefs'] as $belief)
                                <li class="flex items-start gap-2 text-slate-300 text-sm font-medium">
                                    <span class="text-[#3D8948] font-black">✓</span> {{ $belief }}
                                </li>
                                @endforeach
                            </ul>
                        </div>
                        @endif
                        @if(!empty($ms['brand_stands_against']))
                        <div>
                            <p class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-3">Brand Anti</p>
                            <ul class="space-y-2">
                                @foreach($ms['brand_stands_against'] as $against)
                                <li class="flex items-start gap-2 text-slate-300 text-sm font-medium">
                                    <span class="text-red-400 font-black">✕</span> {{ $against }}
                                </li>
                                @endforeach
                            </ul>
                        </div>
                        @endif
                    </div>
                </div>
                @endif

                {{-- Micro Story Chapters --}}
                @if(!empty($csm['micro_story']))
                <div>
                    <div class="flex items-baseline gap-3 mb-5">
                        <span class="text-xs font-black text-[#3D8948] uppercase tracking-widest">Micro Story</span>
                        <span class="text-xs text-slate-600 font-medium">Campaign Level 1–3 Bulan</span>
                    </div>
                    <div class="grid sm:grid-cols-3 gap-4">
                        @foreach(['chapter_1', 'chapter_2', 'chapter_3'] as $i => $key)
                        @if(!empty($csm['micro_story'][$key]))
                        @php $ch = $csm['micro_story'][$key]; @endphp
                        <div class="bg-white/[0.03] rounded-xl border border-white/8 p-5">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-xs font-black text-slate-600 uppercase tracking-widest">Chapter {{ $i + 1 }}</span>
                                <span class="text-xs bg-[#3D8948]/15 text-[#4aaa57] font-bold px-2.5 py-1 rounded-full">{{ $ch['theme'] }}</span>
                            </div>
                            <p class="font-black text-white text-lg leading-tight mb-4">{{ $ch['title'] }}</p>
                            <ul class="space-y-2 mb-4">
                                @foreach($ch['content_ideas'] as $idea)
                                <li class="text-slate-400 text-xs font-medium flex items-start gap-2">
                                    <span class="text-[#3D8948]">—</span> {{ $idea }}
                                </li>
                                @endforeach
                            </ul>
                            <div class="border-t border-white/5 pt-4">
                                <p class="text-xs text-white font-semibold italic">{{ $ch['message'] }}</p>
                            </div>
                        </div>
                        @endif
                        @endforeach
                    </div>
                </div>
                @endif
            </div>
        </section>
        @endif

        {{-- ===== 04 BRAND VOICE ===== --}}
        @if(!empty($brandKit['brand_voice']))
        @php $bv = $brandKit['brand_voice']; @endphp
        <section class="bg-white/[0.03] rounded-2xl border border-white/8 overflow-hidden">
            <div class="bg-[#3D8948]/10 border-b border-white/5 px-8 py-4 flex items-center gap-3">
                <span class="text-xs font-black text-[#3D8948] uppercase tracking-widest">04</span>
                <span class="text-sm font-black text-white uppercase tracking-widest">Brand Voice</span>
            </div>
            <div class="p-8">
                <p class="text-slate-300 text-sm leading-relaxed mb-6">{{ $bv['tone_description'] }}</p>
                @if(!empty($bv['personality_words']))
                <div class="flex flex-wrap gap-2 mb-8">
                    @foreach($bv['personality_words'] as $word)
                    <span class="bg-[#3D8948]/15 border border-[#3D8948]/30 text-[#4aaa57] text-sm font-bold px-4 py-2 rounded-full uppercase tracking-wider">{{ $word }}</span>
                    @endforeach
                </div>
                @endif
                <div class="grid sm:grid-cols-2 gap-6">
                    @if(!empty($bv['dos']))
                    <div>
                        <p class="text-xs font-black text-green-400 uppercase tracking-widest mb-4">Yang Harus Dilakukan</p>
                        <ul class="space-y-3">
                            @foreach($bv['dos'] as $item)
                            <li class="flex items-start gap-3 text-slate-300 text-sm font-medium">
                                <span class="w-5 h-5 rounded-full bg-green-500/15 flex items-center justify-center flex-shrink-0 mt-0.5">
                                    <svg class="w-3 h-3 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                </span>
                                {{ $item }}
                            </li>
                            @endforeach
                        </ul>
                    </div>
                    @endif
                    @if(!empty($bv['donts']))
                    <div>
                        <p class="text-xs font-black text-red-400 uppercase tracking-widest mb-4">Yang Harus Dihindari</p>
                        <ul class="space-y-3">
                            @foreach($bv['donts'] as $item)
                            <li class="flex items-start gap-3 text-slate-300 text-sm font-medium">
                                <span class="w-5 h-5 rounded-full bg-red-500/15 flex items-center justify-center flex-shrink-0 mt-0.5">
                                    <svg class="w-3 h-3 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                                </span>
                                {{ $item }}
                            </li>
                            @endforeach
                        </ul>
                    </div>
                    @endif
                </div>
            </div>
        </section>
        @endif

        {{-- ===== 05 CONTENT PILLARS ===== --}}
        @if(!empty($brandKit['content_pillars']))
        <section class="bg-white/[0.03] rounded-2xl border border-white/8 overflow-hidden">
            <div class="bg-[#3D8948]/10 border-b border-white/5 px-8 py-4 flex items-center gap-3">
                <span class="text-xs font-black text-[#3D8948] uppercase tracking-widest">05</span>
                <span class="text-sm font-black text-white uppercase tracking-widest">Content Pillars</span>
            </div>
            <div class="p-8 grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($brandKit['content_pillars'] as $i => $pillar)
                <div class="bg-white/[0.03] rounded-xl border border-white/8 p-5">
                    <div class="flex items-center gap-2.5 mb-3">
                        <span class="w-7 h-7 rounded-lg bg-[#3D8948]/20 flex items-center justify-center text-xs font-black text-[#3D8948]">{{ $i + 1 }}</span>
                        <p class="font-black text-white text-sm">{{ $pillar['name'] }}</p>
                    </div>
                    <p class="text-slate-400 text-xs leading-relaxed mb-4">{{ $pillar['description'] }}</p>
                    <div class="border-t border-white/5 pt-3">
                        <p class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-1.5">Contoh Hook</p>
                        <p class="text-[#4aaa57] text-xs font-semibold italic">"{{ $pillar['example_hook'] }}"</p>
                    </div>
                </div>
                @endforeach
            </div>
        </section>
        @endif

        {{-- ===== 06 CAPTION EXAMPLES ===== --}}
        @if(!empty($brandKit['caption_examples']))
        <section class="bg-white/[0.03] rounded-2xl border border-white/8 overflow-hidden">
            <div class="bg-[#3D8948]/10 border-b border-white/5 px-8 py-4 flex items-center gap-3">
                <span class="text-xs font-black text-[#3D8948] uppercase tracking-widest">06</span>
                <span class="text-sm font-black text-white uppercase tracking-widest">Caption Examples</span>
            </div>
            <div class="p-8 grid sm:grid-cols-3 gap-4">
                @foreach($brandKit['caption_examples'] as $ex)
                <div class="bg-white/[0.03] rounded-xl border border-white/8 p-5">
                    <span class="inline-block bg-[#3D8948]/15 border border-[#3D8948]/20 text-[#4aaa57] text-xs font-bold px-3 py-1 rounded-full uppercase tracking-wider mb-4">{{ $ex['type'] }}</span>
                    <p class="text-slate-300 text-sm leading-relaxed whitespace-pre-line">{{ $ex['caption'] }}</p>
                </div>
                @endforeach
            </div>
        </section>
        @endif

    </div>

    <!-- Bottom CTA -->
    <div class="mt-10 flex flex-col sm:flex-row items-center gap-4 justify-between">
        <a href="/" class="inline-flex items-center gap-2 text-slate-500 hover:text-slate-300 text-xs font-bold uppercase tracking-widest transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Buat Brand Kit Baru
        </a>
        <a href="/download"
            class="inline-flex items-center gap-2.5 bg-[#3D8948] hover:bg-[#4aaa57] text-white font-bold px-6 py-3 rounded-xl transition-all text-xs uppercase tracking-widest shadow-lg shadow-[#3D8948]/20">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            Download PDF
        </a>
    </div>

</div>
@endsection
