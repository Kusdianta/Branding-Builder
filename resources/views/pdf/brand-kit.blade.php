<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<style>
  @page { margin: 0; size: A4 landscape; }

  * {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
    font-family: Helvetica, Arial, sans-serif;
    word-wrap: break-word;
    overflow-wrap: break-word;
    word-break: break-word;
  }

  body {
    font-size: 9pt;
    color: #1a1a1a;
    background: #ffffff;
    line-height: 1.5;
  }

  /* dompdf: use word-break:break-all on all table cells to prevent overflow */
  td {
    word-wrap: break-word;
    overflow-wrap: break-word;
    word-break: break-word;
    vertical-align: top;
  }

  /* ── COVER ── */
  .cover {
    width: 297mm;
    height: 210mm;
    background: #0b1015;
    color: #ffffff;
    padding: 44px 50px;
    page-break-after: always;
    overflow: hidden;
  }

  .cover-top { margin-bottom: 50px; }

  .cover-presented {
    font-size: 7.5pt;
    color: #6b7280;
    line-height: 1.8;
  }
  .cover-presented b { color: #9ca3af; }

  .agency-name {
    font-size: 20pt;
    font-weight: bold;
    color: #ffffff;
    line-height: 1.05;
    margin-top: 6px;
  }
  .agency-dot { color: #3D8948; }
  .agency-sub {
    font-size: 7pt;
    color: #3D8948;
    font-weight: bold;
    letter-spacing: 2px;
    text-transform: uppercase;
    margin-top: 4px;
  }

  .cover-label {
    font-size: 7pt;
    font-weight: bold;
    color: #3D8948;
    letter-spacing: 3px;
    text-transform: uppercase;
    margin-bottom: 14px;
    display: block;
  }
  .cover-h1 {
    font-size: 34pt;
    font-weight: bold;
    color: #ffffff;
    line-height: 1.05;
    letter-spacing: -1px;
    text-transform: uppercase;
    margin-bottom: 10px;
  }
  .cover-tagline {
    font-size: 10.5pt;
    color: #9ca3af;
    font-style: italic;
  }
  .cover-divider {
    border: none;
    border-top: 1px solid rgba(255,255,255,0.1);
    margin: 26px 0;
  }
  .meta-label {
    font-size: 6.5pt;
    font-weight: bold;
    color: #4b5563;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    display: block;
    margin-bottom: 3px;
  }
  .meta-value {
    font-size: 9pt;
    font-weight: bold;
    color: #d1d5db;
    display: block;
  }
  .cover-footer {
    margin-top: 22px;
    border-top: 1px solid rgba(255,255,255,0.08);
    padding-top: 10px;
    font-size: 7pt;
    color: #374151;
    letter-spacing: 1px;
  }

  /* ── CONTENT PAGES ── */
  .page {
    padding: 30px 46px 38px;
    page-break-after: always;
    background: #ffffff;
  }
  .page:last-child { page-break-after: avoid; }

  /* ── PAGE HEADER ── */
  .page-header {
    border-bottom: 2px solid #0b1015;
    padding-bottom: 10px;
    margin-bottom: 22px;
  }
  .sec-num {
    font-size: 7.5pt;
    font-weight: bold;
    color: #3D8948;
    letter-spacing: 2px;
    margin-right: 8px;
  }
  .sec-title {
    font-size: 12pt;
    font-weight: bold;
    color: #0b1015;
    letter-spacing: -0.2px;
    text-transform: uppercase;
  }
  .sec-client {
    font-size: 7pt;
    font-weight: bold;
    color: #9ca3af;
    letter-spacing: 1px;
    text-transform: uppercase;
    float: right;
    margin-top: 2px;
  }

  /* ── TYPOGRAPHY ── */
  .label {
    font-size: 6.5pt;
    font-weight: bold;
    color: #9ca3af;
    letter-spacing: 2px;
    text-transform: uppercase;
    display: block;
    margin-bottom: 4px;
  }
  .label-green {
    font-size: 6.5pt;
    font-weight: bold;
    color: #3D8948;
    letter-spacing: 2px;
    text-transform: uppercase;
    display: block;
    margin-bottom: 4px;
  }
  .body-text {
    font-size: 9pt;
    color: #374151;
    line-height: 1.65;
    margin-bottom: 7px;
  }
  .narrative-title {
    font-size: 15pt;
    font-weight: bold;
    color: #3D8948;
    line-height: 1.2;
    margin-bottom: 8px;
  }
  .tagline-big {
    font-size: 18pt;
    font-weight: bold;
    color: #ffffff;
    line-height: 1.2;
  }

  /* ── BOXES ── */
  .dark-box {
    background: #0b1015;
    border-radius: 6px;
    padding: 14px 18px;
    margin-bottom: 18px;
  }
  .green-line {
    border-left: 3px solid #3D8948;
    padding-left: 10px;
    margin-bottom: 8px;
  }
  .red-line {
    border-left: 3px solid #dc2626;
    padding-left: 10px;
    margin-bottom: 8px;
  }
  .neutral-box {
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 11px 13px;
    margin-bottom: 8px;
  }
  .hr { border: none; border-top: 1px solid #e5e7eb; margin: 16px 0; }

  /* ── LISTS ── */
  .dot-list { list-style: none; padding: 0; margin: 0; }
  .dot-list li {
    font-size: 8.5pt;
    color: #374151;
    padding: 2px 0 2px 14px;
    position: relative;
    line-height: 1.45;
  }
  .dot-list li:before { content: '-'; position: absolute; left: 0; color: #3D8948; font-weight: bold; }
  .check-list li:before { content: 'v'; color: #16a34a; font-weight: bold; }
  .cross-list li:before { content: 'x'; color: #dc2626; font-weight: bold; }

  /* ── 3-COLUMN TABLE ── */
  /*
     A4 landscape at 72dpi = ~842px wide
     Page padding: 46px * 2 = 92px
     Usable: 750px
     3 cols: 250px each
     Inter-column divider: no extra padding, just border-right
  */
  .col3-table {
    width: 750px;
    border-collapse: collapse;
    table-layout: fixed;
  }
  .col3-table td {
    width: 250px;
    padding: 0 12px 0 0;
    border-right: 1px solid #e5e7eb;
  }
  .col3-table td + td { padding-left: 12px; }
  .col3-table td:last-child { padding-right: 0; border-right: none; }

  /* ── 2-COLUMN TABLE ── */
  .col2-table {
    width: 750px;
    border-collapse: collapse;
    table-layout: fixed;
  }
  .col2-table td {
    width: 375px;
    padding: 0 16px 0 0;
  }
  .col2-table td + td { padding-left: 16px; padding-right: 0; }

  /* ── PILLAR CARD ── */
  .pillar-num {
    display: inline-block;
    width: 18px;
    height: 18px;
    background: #0b1015;
    color: #3D8948;
    font-size: 7pt;
    font-weight: bold;
    text-align: center;
    line-height: 18px;
    border-radius: 3px;
    margin-bottom: 6px;
  }
  .pillar-name {
    font-size: 9pt;
    font-weight: bold;
    color: #0b1015;
    text-transform: uppercase;
    letter-spacing: 0.2px;
    margin-bottom: 4px;
  }
  .pillar-desc {
    font-size: 7.5pt;
    color: #6b7280;
    line-height: 1.45;
    margin-bottom: 7px;
  }
  .hook-label {
    font-size: 6.5pt;
    font-weight: bold;
    color: #3D8948;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    margin-bottom: 3px;
  }
  .hook-text {
    font-size: 8pt;
    font-style: italic;
    color: #1a1a1a;
    font-weight: bold;
  }

  /* ── CHAPTER CARD ── */
  .chap-theme {
    font-size: 6.5pt;
    font-weight: bold;
    color: #3D8948;
    letter-spacing: 1px;
    text-transform: uppercase;
    margin-bottom: 5px;
    display: block;
  }
  .chap-title {
    font-size: 11pt;
    font-weight: bold;
    color: #0b1015;
    margin-bottom: 8px;
    letter-spacing: -0.2px;
  }
  .chap-msg {
    font-size: 8pt;
    font-style: italic;
    color: #4b5563;
    border-top: 1px solid #e5e7eb;
    padding-top: 7px;
    margin-top: 7px;
  }

  /* ── CAPTION ── */
  .caption-type {
    display: inline-block;
    background: #0b1015;
    color: #3D8948;
    font-size: 6.5pt;
    font-weight: bold;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    padding: 3px 9px;
    border-radius: 20px;
    margin-bottom: 8px;
  }
  .caption-text {
    font-size: 9pt;
    color: #374151;
    line-height: 1.65;
    white-space: pre-line;
  }

  /* ── BADGE ── */
  .badge-green {
    display: inline-block;
    background: #0b1015;
    color: #3D8948;
    font-size: 7pt;
    font-weight: bold;
    letter-spacing: 1px;
    text-transform: uppercase;
    padding: 2px 9px;
    border-radius: 20px;
    margin: 2px 2px 2px 0;
  }

  .cf:after { content: ''; display: table; clear: both; }
</style>
</head>
<body>

<!-- ═══════════ COVER ═══════════ -->
<div class="cover">
  <div class="cover-top">
    <div class="cover-presented">Presented by<br><b>Chimera Creative</b></div>
    <div style="margin-top:6px;">
      <div class="agency-name">chimera<span class="agency-dot">.</span>creative</div>
      <div class="agency-sub">Social Media Marketing</div>
    </div>
  </div>

  <span class="cover-label">Social Media Brand Activation Kit</span>
  <div class="cover-h1">{{ $brandData['business_name'] }}</div>
  @if(!empty($brandKit['brand_narrative']['tagline']))
  <div class="cover-tagline">"{{ $brandKit['brand_narrative']['tagline'] }}"</div>
  @endif

  <hr class="cover-divider">

  <table width="100%" style="border-collapse:collapse;table-layout:fixed;">
    <tr>
      <td width="25%" style="vertical-align:top;padding-right:10px;">
        <span class="meta-label">Jenis Layanan</span>
        <span class="meta-value">{{ $brandData['service_type'] }}</span>
      </td>
      <td width="25%" style="vertical-align:top;padding-right:10px;">
        <span class="meta-label">Lokasi</span>
        <span class="meta-value">{{ $brandData['location'] }}</span>
      </td>
      <td width="25%" style="vertical-align:top;padding-right:10px;">
        <span class="meta-label">Segmen Harga</span>
        <span class="meta-value">{{ $brandData['price_segment'] }}</span>
      </td>
      <td width="25%" style="vertical-align:top;">
        <span class="meta-label">Target</span>
        <span class="meta-value" style="font-size:8pt;">{{ $brandData['target_customer'] }}</span>
      </td>
    </tr>
  </table>

  <div class="cover-footer">
    <table width="100%" style="border-collapse:collapse;table-layout:fixed;">
      <tr>
        <td width="50%">CONFIDENTIAL &mdash; FOR CLIENT USE ONLY</td>
        <td width="50%" style="text-align:right;">{{ date('F Y') }}</td>
      </tr>
    </table>
  </div>
</div>


<!-- ═══════════ PAGE 1 — BRAND NARRATIVE ═══════════ -->
@if(!empty($brandKit['brand_narrative']))
@php $bn = $brandKit['brand_narrative']; @endphp
<div class="page">
  <div class="page-header cf">
    <span class="sec-client">{{ $brandData['business_name'] }}</span>
    <span class="sec-num">01</span>
    <span class="sec-title">Brand Narrative</span>
  </div>

  <div class="dark-box">
    <span class="label" style="color:#4b5563;margin-bottom:5px;">Tagline</span>
    <div class="tagline-big">"{{ $bn['tagline'] }}"</div>
  </div>

  <span class="label">Big Narrative</span>
  <div class="narrative-title">{{ $bn['big_narrative_title'] }}</div>
  <hr class="hr">
  @foreach(explode("\n", $bn['big_narrative_body']) as $para)
    @if(trim($para))<p class="body-text">{{ trim($para) }}</p>@endif
  @endforeach
</div>
@endif


<!-- ═══════════ PAGE 2 — NARRATIVE PILLARS ═══════════ -->
@if(!empty($brandKit['narrative_pillars']))
@php $np = $brandKit['narrative_pillars']; @endphp
<div class="page">
  <div class="page-header cf">
    <span class="sec-client">{{ $brandData['business_name'] }}</span>
    <span class="sec-num">02</span>
    <span class="sec-title">Narrative Pillars</span>
  </div>

  <table class="col3-table">
    <colgroup>
      <col width="250">
      <col width="250">
      <col width="250">
    </colgroup>
    <tr>
      {{-- Problem Layer --}}
      @if(!empty($np['problem_layer']))@php $pl = $np['problem_layer']; @endphp
      <td>
        <span class="label">Problem Layer</span>
        <div style="font-size:12pt;font-weight:bold;color:#0b1015;line-height:1.2;margin-bottom:10px;">{{ $pl['world_title'] }}</div>
        <ul class="dot-list">
          @foreach($pl['problems'] as $p)<li>{{ $p }}</li>@endforeach
        </ul>
        <div style="margin-top:12px;padding-top:8px;border-top:1px solid #e5e7eb;">
          <span class="label-green">Tone Konten</span>
          <span style="font-size:8.5pt;font-weight:bold;color:#0b1015;">{{ $pl['content_tone'] }}</span>
        </div>
      </td>
      @endif

      {{-- Belief Layer --}}
      @if(!empty($np['belief_layer']))@php $bl = $np['belief_layer']; @endphp
      <td>
        <span class="label">Belief Layer</span>
        <div style="font-size:12pt;font-weight:bold;color:#0b1015;line-height:1.2;margin-bottom:10px;">{{ $bl['mindset_title'] }}</div>
        <div class="red-line" style="margin-bottom:10px;">
          <span class="label" style="color:#dc2626;">Kepercayaan Lama</span>
          <p style="font-size:8.5pt;color:#374151;">{{ $bl['old_belief'] }}</p>
        </div>
        <div class="green-line" style="margin-bottom:10px;">
          <span class="label-green">Kepercayaan Baru</span>
          <p style="font-size:8.5pt;color:#374151;">{{ $bl['new_belief'] }}</p>
        </div>
        <p style="font-size:8pt;font-style:italic;color:#0b1015;font-weight:bold;">"{{ $bl['key_message'] }}"</p>
      </td>
      @endif

      {{-- Action Layer --}}
      @if(!empty($np['action_layer']))@php $al = $np['action_layer']; @endphp
      <td>
        <span class="label">Action Layer</span>
        <div style="font-size:12pt;font-weight:bold;color:#0b1015;line-height:1.2;margin-bottom:10px;">{{ $al['ritual_title'] }}</div>
        <span class="label">Momen Trigger</span>
        <ul class="dot-list" style="margin-bottom:12px;">
          @foreach($al['trigger_moments'] as $m)<li>{{ $m }}</li>@endforeach
        </ul>
        <span class="label">Langkah Ritual</span>
        <ul class="dot-list" style="margin-bottom:12px;">
          @foreach($al['ritual_steps'] as $s)<li>{{ $s }}</li>@endforeach
        </ul>
        <span class="label-green">CTA</span>
        <p style="font-size:8.5pt;font-weight:bold;color:#0b1015;">{{ $al['cta'] }}</p>
      </td>
      @endif
    </tr>
  </table>
</div>
@endif


<!-- ═══════════ PAGE 3 — CONTENT STORY MAPPING ═══════════ -->
@if(!empty($brandKit['content_story_mapping']))
@php $csm = $brandKit['content_story_mapping']; @endphp
<div class="page">
  <div class="page-header cf">
    <span class="sec-client">{{ $brandData['business_name'] }}</span>
    <span class="sec-num">03</span>
    <span class="sec-title">Content Story Mapping</span>
  </div>

  {{-- Macro Story --}}
  @if(!empty($csm['macro_story']))@php $ms = $csm['macro_story']; @endphp
  <div style="margin-bottom:18px;">
    <span class="label-green" style="display:inline;">MACRO STORY</span>
    <span style="font-size:7pt;color:#9ca3af;margin-left:8px;">Brand Level 1-2 Tahun</span>
    <div class="dark-box" style="margin-top:8px;">
      <div style="font-size:14pt;font-weight:bold;color:#ffffff;line-height:1.25;">"{{ $ms['umbrella_narrative'] }}"</div>
    </div>
    <table class="col2-table">
      <colgroup>
        <col width="375">
        <col width="375">
      </colgroup>
      <tr>
        @if(!empty($ms['brand_beliefs']))
        <td>
          <span class="label">Brand Percaya</span>
          <ul class="dot-list check-list">
            @foreach($ms['brand_beliefs'] as $b)<li>{{ $b }}</li>@endforeach
          </ul>
        </td>
        @endif
        @if(!empty($ms['brand_stands_against']))
        <td>
          <span class="label">Brand Anti</span>
          <ul class="dot-list cross-list">
            @foreach($ms['brand_stands_against'] as $a)<li>{{ $a }}</li>@endforeach
          </ul>
        </td>
        @endif
      </tr>
    </table>
  </div>
  @endif

  {{-- Micro Story --}}
  @if(!empty($csm['micro_story']))
  <hr class="hr">
  <span class="label-green" style="display:inline;">MICRO STORY</span>
  <span style="font-size:7pt;color:#9ca3af;margin-left:8px;">Campaign Level 1-3 Bulan</span>
  <div style="margin-top:10px;">
    <table class="col3-table">
      <colgroup>
        <col width="250">
        <col width="250">
        <col width="250">
      </colgroup>
      <tr>
        @foreach(['chapter_1','chapter_2','chapter_3'] as $i => $key)
        @if(!empty($csm['micro_story'][$key]))@php $ch = $csm['micro_story'][$key]; @endphp
        <td>
          <div class="neutral-box" style="margin-bottom:0;">
            <div style="font-size:6.5pt;font-weight:bold;color:#9ca3af;letter-spacing:2px;text-transform:uppercase;margin-bottom:3px;">Chapter {{ $i+1 }}</div>
            <span class="chap-theme">{{ $ch['theme'] }}</span>
            <div class="chap-title">{{ $ch['title'] }}</div>
            <ul class="dot-list">
              @foreach($ch['content_ideas'] as $idea)<li>{{ $idea }}</li>@endforeach
            </ul>
            <div class="chap-msg">"{{ $ch['message'] }}"</div>
          </div>
        </td>
        @endif
        @endforeach
      </tr>
    </table>
  </div>
  @endif
</div>
@endif


<!-- ═══════════ PAGE 4 — BRAND VOICE ═══════════ -->
@if(!empty($brandKit['brand_voice']))
@php $bv = $brandKit['brand_voice']; @endphp
<div class="page">
  <div class="page-header cf">
    <span class="sec-client">{{ $brandData['business_name'] }}</span>
    <span class="sec-num">04</span>
    <span class="sec-title">Brand Voice</span>
  </div>

  <p class="body-text" style="margin-bottom:10px;">{{ $bv['tone_description'] }}</p>

  @if(!empty($bv['personality_words']))
  <div style="margin-bottom:16px;">
    @foreach($bv['personality_words'] as $w)
    <span class="badge-green">{{ strtoupper($w) }}</span>
    @endforeach
  </div>
  @endif

  <table class="col2-table">
    <colgroup>
      <col width="375">
      <col width="375">
    </colgroup>
    <tr>
      @if(!empty($bv['dos']))
      <td>
        <span class="label" style="color:#16a34a;">Yang Harus Dilakukan</span>
        <ul class="dot-list check-list">
          @foreach($bv['dos'] as $item)<li>{{ $item }}</li>@endforeach
        </ul>
      </td>
      @endif
      @if(!empty($bv['donts']))
      <td>
        <span class="label" style="color:#dc2626;">Yang Harus Dihindari</span>
        <ul class="dot-list cross-list">
          @foreach($bv['donts'] as $item)<li>{{ $item }}</li>@endforeach
        </ul>
      </td>
      @endif
    </tr>
  </table>
</div>
@endif


<!-- ═══════════ PAGE 5 — CONTENT PILLARS ═══════════ -->
@if(!empty($brandKit['content_pillars']))
<div class="page">
  <div class="page-header cf">
    <span class="sec-client">{{ $brandData['business_name'] }}</span>
    <span class="sec-num">05</span>
    <span class="sec-title">Content Pillars</span>
  </div>

  @php $pillars = $brandKit['content_pillars']; $chunks = array_chunk($pillars, 3); @endphp
  @foreach($chunks as $chunk)
  <table class="col3-table" style="margin-bottom:10px;">
    <colgroup>
      <col width="250">
      <col width="250">
      <col width="250">
    </colgroup>
    <tr>
      @foreach($chunk as $idx => $pillar)
      <td>
        <div class="neutral-box" style="margin-bottom:0;">
          <div class="pillar-num">{{ array_search($pillar, $pillars) + 1 }}</div>
          <div class="pillar-name">{{ $pillar['name'] }}</div>
          <div class="pillar-desc">{{ $pillar['description'] }}</div>
          <div class="hook-label">Contoh Hook</div>
          <div class="hook-text">"{{ $pillar['example_hook'] }}"</div>
        </div>
      </td>
      @endforeach
      @for($e = count($chunk); $e < 3; $e++)
      <td></td>
      @endfor
    </tr>
  </table>
  @endforeach
</div>
@endif


<!-- ═══════════ PAGE 6 — CAPTION EXAMPLES ═══════════ -->
@if(!empty($brandKit['caption_examples']))
<div class="page">
  <div class="page-header cf">
    <span class="sec-client">{{ $brandData['business_name'] }}</span>
    <span class="sec-num">06</span>
    <span class="sec-title">Caption Examples</span>
  </div>

  @foreach($brandKit['caption_examples'] as $ex)
  <div class="neutral-box" style="margin-bottom:12px;">
    <div class="caption-type">{{ $ex['type'] }}</div>
    <div class="caption-text">{{ $ex['caption'] }}</div>
  </div>
  @endforeach

  <div style="margin-top:30px;padding-top:16px;border-top:2px solid #0b1015;">
    <table width="100%" style="border-collapse:collapse;table-layout:fixed;">
      <tr>
        <td width="50%" style="vertical-align:bottom;">
          <div style="font-size:6.5pt;color:#9ca3af;font-weight:bold;letter-spacing:2px;text-transform:uppercase;margin-bottom:4px;">Dibuat oleh</div>
          <div style="font-size:15pt;font-weight:bold;color:#0b1015;line-height:1.05;">chimera<span style="color:#3D8948;">.</span>creative</div>
          <div style="font-size:6.5pt;color:#3D8948;font-weight:bold;letter-spacing:2px;text-transform:uppercase;margin-top:4px;">Social Media Marketing</div>
        </td>
        <td width="50%" style="vertical-align:bottom;text-align:right;">
          <div style="font-size:8pt;color:#9ca3af;margin-bottom:3px;">{{ date('F Y') }}</div>
          <div style="font-size:7pt;color:#9ca3af;font-weight:bold;text-transform:uppercase;letter-spacing:1.5px;">Confidential</div>
        </td>
      </tr>
    </table>
  </div>
</div>
@endif

</body>
</html>
