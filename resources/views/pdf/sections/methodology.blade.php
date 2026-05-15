{{-- BB43: Appendix · Metodologi — apikprimadya-style footer note. --}}

<h2 style="font-size: 14px; color: #0F1411; margin: 24px 0 8px 0;">Appendix · Metodologi</h2>

<p style="font-size: 10px; color: #5A6259; line-height: 1.65; margin: 0 0 8px 0;">
    Audit ini dihasilkan menggunakan kombinasi data publik + analisis LLM:
</p>

<ul style="margin: 0 0 12px 14px; padding: 0; font-size: 10px; color: #5A6259; line-height: 1.7;">
    <li><strong>Brand Recall</strong> &mdash; Google Maps Places API (rating, jumlah ulasan), Google Autocomplete (search recall), dan scrape ulasan terstruktur (kata kunci + sentimen).</li>
    <li><strong>Brand Konsistensi</strong> &mdash; analisis LLM (Claude Sonnet) terhadap touchpoint URL + foto outlet bila tersedia.</li>
    <li><strong>Brand Experience</strong> &mdash; analisis LLM untuk skor dasar + bonus, deteksi penalti deterministik dari korpus ulasan Google Maps.</li>
    <li><strong>Digital Presence</strong> &mdash; deteksi keberadaan touchpoint berdasarkan input form pengguna + bonus dari volume ulasan.</li>
    <li><strong>Audit Profil Instagram</strong> &mdash; scrape worker headless Chrome (profile + 12 post + 6 caption + 6 highlight) + analisis LLM dengan rubrik kalibrasi pasar laundry Indonesia.</li>
    <li><strong>Rekomendasi &amp; Quick Wins</strong> &mdash; dihasilkan oleh LLM berdasarkan seluruh konteks audit, dirank berdasarkan impact &times; urgency.</li>
</ul>

<p style="font-size: 9px; color: #8A9088; line-height: 1.55; margin: 0; font-style: italic;">
    Audit dilakukan menggunakan data yang tersedia secara publik per {{ $generatedAt }}. Data internal brand (operasional, finansial, customer database) tidak digunakan. Skor berfungsi sebagai diagnosis, bukan rekomendasi keputusan bisnis tunggal &mdash; gunakan bersama dengan pertimbangan kualitatif tim Anda.
</p>
