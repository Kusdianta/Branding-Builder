<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Chimera Creative — KOL Finder</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #0D1117; color: #E8F0E9; font-family: 'DM Sans', sans-serif; min-height: 100vh; }
    .topbar { border-bottom: 1px solid rgba(255,255,255,0.07); padding: 14px 28px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: #0D1117; z-index: 100; }
    .logo { display: flex; align-items: center; gap: 10px; text-decoration: none; }
    .logo-icon { width: 32px; height: 32px; background: linear-gradient(135deg,#4CAF50,#2E7D32); border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 16px; }
    .logo-name { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 15px; font-weight: 800; color: #E8F0E9; }
    .logo-sub { font-size: 10px; color: #4CAF50; letter-spacing: 0.1em; text-transform: uppercase; }
    .nav-links { display: flex; gap: 8px; }
    .nav-link { font-size: 12px; color: #556B6E; padding: 6px 14px; border-radius: 8px; text-decoration: none; border: 1px solid rgba(255,255,255,0.08); transition: all 0.2s; }
    .nav-link:hover, .nav-link.active { color: #4CAF50; border-color: rgba(76,175,80,0.4); }
    .page { max-width: 1100px; margin: 0 auto; padding: 40px 24px 80px; }
    .search-panel { background: #131B2E; border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 28px; margin-bottom: 28px; }
    .search-title { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 20px; font-weight: 800; margin-bottom: 4px; }
    .search-sub { font-size: 13px; color: #556B6E; margin-bottom: 24px; line-height: 1.6; }
    .search-grid { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr auto; gap: 12px; align-items: end; }
    .field-label { font-size: 10px; color: #556B6E; letter-spacing: 0.12em; text-transform: uppercase; display: block; margin-bottom: 7px; }
    .field-input { width: 100%; background: #0D1117; border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; padding: 11px 14px; color: #E8F0E9; font-size: 14px; font-family: 'DM Sans', sans-serif; transition: border-color 0.2s; }
    .field-input:focus { outline: none; border-color: rgba(76,175,80,0.5); }
    .btn-search { background: linear-gradient(135deg,#4CAF50,#2E7D32); border: none; border-radius: 10px; padding: 11px 24px; color: #fff; font-size: 14px; font-weight: 700; cursor: pointer; font-family: 'Plus Jakarta Sans', sans-serif; white-space: nowrap; box-shadow: 0 4px 16px rgba(76,175,80,0.25); }
    .btn-search:disabled { opacity: 0.4; cursor: not-allowed; }
    .btn-export { background: transparent; border: 1px solid rgba(76,175,80,0.4); color: #4CAF50; padding: 7px 18px; border-radius: 8px; cursor: pointer; font-size: 12px; font-family: 'DM Sans', sans-serif; font-weight: 600; transition: all 0.2s; }
    .btn-export:hover { background: rgba(76,175,80,0.1); }
    .btn-export:disabled { opacity: 0.5; cursor: not-allowed; }
    .status-bar { background: rgba(76,175,80,0.08); border: 1px solid rgba(76,175,80,0.2); border-radius: 10px; padding: 12px 16px; margin-bottom: 20px; display: none; align-items: center; gap: 12px; }
    .status-dot { width: 8px; height: 8px; border-radius: 50%; background: #4CAF50; animation: pulse 1.5s infinite; flex-shrink: 0; }
    .status-text { font-size: 13px; color: #4CAF50; }
    .filter-bar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .filter-label { font-size: 12px; color: #556B6E; margin-right: 4px; }
    .filter-btn { padding: 5px 14px; border-radius: 20px; cursor: pointer; font-size: 12px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); color: #777; transition: all 0.15s; font-family: 'DM Sans', sans-serif; }
    .filter-btn.active { background: rgba(76,175,80,0.15); border-color: rgba(76,175,80,0.5); color: #4CAF50; }
    .results-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
    .results-count { font-size: 13px; color: #556B6E; }
    .results-count span { color: #4CAF50; font-weight: 700; }
    .results-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .kol-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 14px; }
    .kol-card { background: #131B2E; border: 1px solid rgba(255,255,255,0.07); border-radius: 14px; padding: 20px; transition: border-color 0.2s, transform 0.2s; }
    .kol-card:hover { border-color: rgba(76,175,80,0.3); transform: translateY(-2px); }
    .kol-card-header { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; }
    .kol-avatar { width: 48px; height: 48px; border-radius: 50%; background: linear-gradient(135deg,#2E7D32,#4CAF50); display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700; color: #fff; flex-shrink: 0; overflow: hidden; }
    .kol-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .kol-info { flex: 1; min-width: 0; }
    .kol-name { font-size: 14px; font-weight: 700; color: #E8F0E9; font-family: 'Plus Jakarta Sans', sans-serif; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .kol-username { font-size: 12px; color: #4CAF50; margin-top: 2px; }
    .tier-badge { font-size: 9px; font-weight: 700; padding: 3px 9px; border-radius: 20px; letter-spacing: 0.1em; text-transform: uppercase; flex-shrink: 0; }
    .tier-macro { background: rgba(255,193,7,0.15); color: #FFC107; border: 1px solid rgba(255,193,7,0.3); }
    .tier-mid { background: rgba(76,175,80,0.15); color: #4CAF50; border: 1px solid rgba(76,175,80,0.3); }
    .tier-micro { background: rgba(33,150,243,0.15); color: #2196F3; border: 1px solid rgba(33,150,243,0.3); }
    .tier-nano { background: rgba(156,39,176,0.15); color: #9C27B0; border: 1px solid rgba(156,39,176,0.3); }
    .tier-unknown { background: rgba(158,158,158,0.15); color: #9E9E9E; border: 1px solid rgba(158,158,158,0.3); }
    .kol-bio { font-size: 12px; color: #8A9E8C; line-height: 1.5; margin-bottom: 14px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .kol-stats { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin-bottom: 14px; }
    .kol-stat { background: rgba(255,255,255,0.03); border-radius: 8px; padding: 8px 10px; text-align: center; }
    .kol-stat-num { font-size: 14px; font-weight: 700; color: #E8F0E9; font-family: 'Plus Jakarta Sans', sans-serif; }
    .kol-stat-num.green { color: #4CAF50; }
    .kol-stat-label { font-size: 9px; color: #556B6E; letter-spacing: 0.08em; text-transform: uppercase; margin-top: 2px; }
    .kol-actions { display: flex; gap: 7px; }
    .btn-ig { flex: 1; background: linear-gradient(135deg,rgba(193,53,132,0.2),rgba(131,58,180,0.2)); border: 1px solid rgba(193,53,132,0.3); color: #E1306C; padding: 8px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; text-align: center; text-decoration: none; display: block; transition: all 0.2s; font-family: 'DM Sans', sans-serif; }
    .btn-save { padding: 8px 14px; background: transparent; border: 1px solid rgba(76,175,80,0.3); color: #4CAF50; border-radius: 8px; font-size: 12px; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all 0.2s; }
    .btn-save:hover { background: rgba(76,175,80,0.1); }
    .btn-save.saved { background: rgba(76,175,80,0.15); }
    .empty-state { text-align: center; padding: 60px 24px; }
    .empty-icon { font-size: 48px; opacity: 0.2; margin-bottom: 16px; }
    .empty-title { font-size: 16px; font-weight: 700; margin-bottom: 8px; font-family: 'Plus Jakarta Sans', sans-serif; }
    .empty-sub { font-size: 13px; color: #556B6E; line-height: 1.6; }
    .error-box { background: rgba(224,92,92,0.07); border: 1px solid rgba(224,92,92,0.2); border-radius: 10px; padding: 14px 16px; color: #E05C5C; font-size: 13px; margin-bottom: 20px; display: none; }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }
    @keyframes fadeUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }
    .fade-up { animation: fadeUp 0.5s ease both; }
    ::-webkit-scrollbar { width: 4px; } ::-webkit-scrollbar-thumb { background: #1A2540; }
    @media (max-width: 768px) {
      .search-grid { grid-template-columns: 1fr 1fr; }
      .btn-search { grid-column: span 2; }
    }
  </style>
</head>
<body>

  <div class="topbar">
    <a href="/" class="logo">
      <div class="logo-icon">♟</div>
      <div>
        <div class="logo-name">chimera creative</div>
        <div class="logo-sub">KOL Finder Tool</div>
      </div>
    </a>
    <div class="nav-links">
      <a href="/chimera" class="nav-link">Brand Kit</a>
      <a href="/kol" class="nav-link active">KOL Finder</a>
    </div>
  </div>

  <div class="page fade-up">

    <div class="search-panel">
      <div class="search-title">KOL Finder</div>
      <div class="search-sub">Temukan influencer & KOL lokal yang relevan untuk klien laundry kamu — filter by kota, niche, dan followers range.</div>
      <div class="search-grid">
        <div>
          <label class="field-label">Kota / Area *</label>
          <input class="field-input" id="kota" placeholder="e.g. Surabaya" />
        </div>
        <div>
          <label class="field-label">Niche / Kategori</label>
          <input class="field-input" id="niche" placeholder="e.g. laundry, lifestyle" />
        </div>
        <div>
          <label class="field-label">Min Followers</label>
          <input class="field-input" id="min-followers" type="number" placeholder="1000" value="1000" />
        </div>
        <div>
          <label class="field-label">Max Followers</label>
          <input class="field-input" id="max-followers" type="number" placeholder="500000" value="500000" />
        </div>
        <div>
          <button class="btn-search" id="btn-search" onclick="startSearch()">🔍 Cari KOL</button>
        </div>
      </div>
    </div>

    <div class="error-box" id="error-box"></div>

    <div class="status-bar" id="status-bar">
      <div class="status-dot"></div>
      <div class="status-text" id="status-text">Mencari KOL...</div>
    </div>

    <div id="results-section" style="display:none">
      <div class="results-header">
        <div class="results-count">Ditemukan <span id="results-count">0</span> KOL</div>
        <div class="results-actions">
          <div class="filter-bar">
            <span class="filter-label">Filter:</span>
            <button class="filter-btn active" onclick="filterTier('all', this)">Semua</button>
            <button class="filter-btn" onclick="filterTier('Macro', this)">Macro 100K+</button>
            <button class="filter-btn" onclick="filterTier('Mid', this)">Mid 10K–100K</button>
            <button class="filter-btn" onclick="filterTier('Micro', this)">Micro 1K–10K</button>
            <button class="filter-btn" onclick="filterTier('Nano', this)">Nano &lt;1K</button>
          </div>
          <button class="btn-export" id="btn-export" onclick="exportExcel()" style="display:none">↓ Export Excel</button>
        </div>
      </div>
      <div class="kol-grid" id="kol-grid"></div>
    </div>

    <div class="empty-state" id="empty-state">
      <div class="empty-icon">🔍</div>
      <div class="empty-title">Belum ada hasil</div>
      <div class="empty-sub">Masukkan kota dan niche untuk mulai mencari KOL lokal<br/>yang relevan untuk klien laundry kamu.</div>
    </div>

  </div>

  <script>
    let allKols = [];
    let currentRunId = null;
    let pollInterval = null;
    const POLL_MS = 4000;
    const STATUS_MSGS = [
      'Memulai pencarian...','Scraping hashtag Instagram...',
      'Mengumpulkan data profil...','Menghitung engagement rate...',
      'Filtering hasil...','Hampir selesai...',
    ];
    let statusIdx = 0;

    function setStatus(show, msg) {
      const bar = document.getElementById('status-bar');
      bar.style.display = show ? 'flex' : 'none';
      if (msg) document.getElementById('status-text').textContent = msg;
    }

    function setError(msg) {
      const eb = document.getElementById('error-box');
      if (msg) { eb.textContent = msg; eb.style.display = 'block'; }
      else { eb.style.display = 'none'; }
    }

    async function startSearch() {
      const kota = document.getElementById('kota').value.trim();
      if (!kota) { alert('Kota harus diisi!'); return; }

      setError('');
      document.getElementById('results-section').style.display = 'none';
      document.getElementById('empty-state').style.display = 'none';
      document.getElementById('btn-export').style.display = 'none';
      document.getElementById('btn-search').disabled = true;
      allKols = []; statusIdx = 0;
      setStatus(true, STATUS_MSGS[0]);

      try {
        const res = await fetch('/kol/search', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
          body: JSON.stringify({
            kota,
            niche: document.getElementById('niche').value.trim() || 'laundry',
            min_followers: parseInt(document.getElementById('min-followers').value) || 1000,
            max_followers: parseInt(document.getElementById('max-followers').value) || 500000,
          }),
        });

        const data = await res.json();
        if (data.error) throw new Error(data.error);
        currentRunId = data.runId;

        pollInterval = setInterval(async () => {
          statusIdx = Math.min(statusIdx + 1, STATUS_MSGS.length - 1);
          setStatus(true, STATUS_MSGS[statusIdx]);

          const statusRes = await fetch(`/kol/status?runId=${currentRunId}`);
          const statusData = await statusRes.json();

          if (statusData.status === 'SUCCEEDED') {
            clearInterval(pollInterval);
            setStatus(true, 'Mengambil hasil...');
            await fetchResults();
          } else if (['FAILED', 'ABORTED', 'TIMED-OUT'].includes(statusData.status)) {
            clearInterval(pollInterval);
            setStatus(false);
            setError('Pencarian gagal: ' + statusData.status);
            document.getElementById('btn-search').disabled = false;
          }
        }, POLL_MS);

      } catch (e) {
        setStatus(false);
        setError('Error: ' + e.message);
        document.getElementById('btn-search').disabled = false;
      }
    }

    async function fetchResults() {
      const minFollowers = document.getElementById('min-followers').value || 1000;
      const maxFollowers = document.getElementById('max-followers').value || 500000;

      const res = await fetch(`/kol/results?runId=${currentRunId}&minFollowers=${minFollowers}&maxFollowers=${maxFollowers}`);
      const data = await res.json();

      if (data.error) {
        setStatus(false);
        setError('Gagal ambil hasil: ' + data.error);
        document.getElementById('btn-search').disabled = false;
        return;
      }

      allKols = data.data || [];
      setStatus(false);
      renderKols(allKols);
      document.getElementById('btn-search').disabled = false;
    }

    function renderKols(kols) {
      const grid = document.getElementById('kol-grid');
      document.getElementById('results-count').textContent = kols.length;

      if (kols.length === 0) {
        document.getElementById('results-section').style.display = 'none';
        document.getElementById('empty-state').style.display = 'block';
        document.getElementById('empty-state').querySelector('.empty-title').textContent = 'Tidak ada KOL ditemukan';
        document.getElementById('empty-state').querySelector('.empty-sub').textContent = 'Coba ubah kata kunci, kota, atau perlebar range followers.';
        document.getElementById('btn-export').style.display = 'none';
        return;
      }

      document.getElementById('results-section').style.display = 'block';
      document.getElementById('empty-state').style.display = 'none';
      document.getElementById('btn-export').style.display = 'block';

      grid.innerHTML = kols.map((k, i) => `
        <div class="kol-card" style="animation: fadeUp 0.4s ease ${i * 0.05}s both">
          <div class="kol-card-header">
            <div class="kol-avatar">
              ${k.profilePic ? `<img src="${k.profilePic}" onerror="this.parentElement.innerHTML='${k.username.charAt(0).toUpperCase()}'">` : k.username.charAt(0).toUpperCase()}
            </div>
            <div class="kol-info">
              <div class="kol-name">${k.fullName || k.username}${k.isVerified ? ' ✓' : ''}</div>
              <div class="kol-username">@${k.username}</div>
            </div>
            <span class="tier-badge tier-${k.tier.toLowerCase()}">${k.tier}</span>
          </div>
          ${k.bio ? `<div class="kol-bio">${k.bio}</div>` : ''}
          <div class="kol-stats">
            <div class="kol-stat">
              <div class="kol-stat-num">${formatNum(k.followers)}</div>
              <div class="kol-stat-label">Followers</div>
            </div>
            <div class="kol-stat">
              <div class="kol-stat-num green">${k.engagement}%</div>
              <div class="kol-stat-label">Engagement</div>
            </div>
            <div class="kol-stat">
              <div class="kol-stat-num">${k.postCount}</div>
              <div class="kol-stat-label">Post Ditemukan</div>
            </div>
          </div>
          <div class="kol-actions">
            <a href="${k.igUrl}" target="_blank" class="btn-ig">Buka Instagram ↗</a>
            ${k.externalUrl ? `<a href="${k.externalUrl.startsWith('http') ? k.externalUrl : 'https://' + k.externalUrl}" target="_blank" class="btn-save">Link</a>` : ''}
            <button class="btn-save" onclick="this.classList.toggle('saved'); this.textContent = this.classList.contains('saved') ? '✓ Tersimpan' : '+ Simpan'">+ Simpan</button>
          </div>
        </div>
      `).join('');
    }

    function filterTier(tier, btn) {
      document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const filtered = tier === 'all' ? allKols : allKols.filter(k => k.tier === tier);
      renderKols(filtered);
    }

    async function exportExcel() {
      const btn = document.getElementById('btn-export');
      btn.disabled = true;
      btn.textContent = 'Exporting...';

      try {
        const res = await fetch('/kol/export', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
          body: JSON.stringify({
            kols:  allKols,
            kota:  document.getElementById('kota').value.trim(),
            niche: document.getElementById('niche').value.trim() || 'lifestyle',
          }),
        });

        if (!res.ok) throw new Error('Export gagal');

        const blob = await res.blob();
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = 'kol-report.xlsx';
        a.click();
        URL.revokeObjectURL(url);

      } catch (e) {
        alert('Export gagal: ' + e.message);
      } finally {
        btn.disabled = false;
        btn.textContent = '↓ Export Excel';
      }
    }

    function formatNum(n) {
      if (!n) return '0';
      if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
      if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
      return n.toString();
    }
  </script>
</body>
</html>
