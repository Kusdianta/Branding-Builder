<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Brand Health Check — Recommendation Templates
|--------------------------------------------------------------------------
| Keyed by sub_bucket_slug (matches config/branding.php pillar_sub_buckets).
| AggregateAuditJob picks the 3 largest improvement gaps (cap - actual),
| excluding base and penalty sub-buckets, then fills templates with brand context.
|
| priority: tinggi | penting | opsional
| body: use {{brand_name}} as placeholder
*/

return [

    // ── Konsistensi ───────────────────────────────────────────────────────────

    'kehadiran_digital' => [
        'priority' => 'tinggi',
        'title'    => 'Tambah Kehadiran Digital',
        'body'     => 'Daftarkan {{brand_name}} di semua platform utama: Google Maps, Instagram, website, WhatsApp Business, dan TikTok. Setiap channel yang aktif menambah kepercayaan pelanggan baru dan memperluas jangkauan organik.',
    ],

    'konsistensi_visual' => [
        'priority' => 'tinggi',
        'title'    => 'Seragamkan Identitas Visual',
        'body'     => 'Gunakan logo, warna, dan font yang sama di semua channel {{brand_name}}. Buat "brand kit" sederhana: 1 logo utama + 2 warna primer + 1 tipografi. Terapkan secara konsisten di semua foto konten, packaging, dan seragam karyawan.',
    ],

    'kelengkapan_layanan' => [
        'priority' => 'penting',
        'title'    => 'Lengkapi Informasi Layanan',
        'body'     => 'Pastikan semua jenis layanan {{brand_name}} tercantum lengkap di bio Instagram, deskripsi Google Maps, dan halaman website: cuci kiloan, cuci satuan, express, antar-jemput, setrika, dry-clean.',
    ],

    'transparansi_harga' => [
        'priority' => 'penting',
        'title'    => 'Tampilkan Daftar Harga',
        'body'     => 'Pasang price list yang jelas di highlight Instagram, foto utama Google Maps, dan landing page {{brand_name}}. Pelanggan yang mengetahui harga di awal 3× lebih mungkin melakukan pemesanan pertama.',
    ],

    // ── Recall ────────────────────────────────────────────────────────────────

    'review_count' => [
        'priority' => 'tinggi',
        'title'    => 'Tingkatkan Jumlah Ulasan Google',
        'body'     => 'Minta setiap pelanggan puas meninggalkan ulasan di Google Maps {{brand_name}}. Kirim link ulasan via WhatsApp setelah pesanan selesai — target minimal 50 ulasan untuk meningkatkan visibilitas pencarian lokal secara signifikan.',
    ],

    'keyword_saturation' => [
        'priority' => 'penting',
        'title'    => 'Arahkan Pelanggan Menulis Ulasan Spesifik',
        'body'     => 'Kirim pesan WhatsApp ke setiap pelanggan yang selesai dilayani: "Halo, terima kasih sudah mempercayakan cucian ke {{brand_name}}! Boleh share pengalaman di Google Maps? Ceritakan soal kebersihan, kecepatan, atau keramahan tim kami — calon pelanggan lain sangat terbantu dengan ulasan deskriptif seperti itu."',
    ],

    'sentiment_quality' => [
        'priority' => 'tinggi',
        'title'    => 'Audit dan Tindak Lanjuti Keluhan Terbaru',
        'body'     => 'Baca ulang 10–20 ulasan Google Maps {{brand_name}} yang memberi bintang ≤3. Catat tema keluhan berulang, lalu buat perbaikan konkret dalam 2 minggu: jadwal konfirmasi pengambilan cucian, pengecekan kualitas sebelum serah terima, dan telepon langsung ke pelanggan yang kecewa untuk tawaran kompensasi.',
    ],

    // ── Experience ────────────────────────────────────────────────────────────

    'bonus_sop_keluhan' => [
        'priority' => 'tinggi',
        'title'    => 'Buat SOP Penanganan Keluhan',
        'body'     => 'Buat prosedur tertulis penanganan keluhan {{brand_name}}: (1) akui masalah dalam 1 jam via WA, (2) tawarkan solusi konkret, (3) lakukan follow-up kepuasan. Tampilkan jaminan ini di highlight Instagram agar pelanggan merasa aman memesan.',
    ],

    'bonus_ekspres' => [
        'priority' => 'penting',
        'title'    => 'Tambahkan Layanan Express',
        'body'     => 'Layanan express (selesai dalam 3–6 jam) membuka segmen pelanggan baru yang bersedia membayar 30–50% lebih mahal. Mulai dengan slot terbatas dan promosikan di story Instagram {{brand_name}} dan status WhatsApp.',
    ],

    'bonus_antar_jemput' => [
        'priority' => 'penting',
        'title'    => 'Aktifkan Layanan Antar-Jemput',
        'body'     => 'Layanan antar-jemput meningkatkan konversi pelanggan tanpa kendaraan. Mulai dengan radius 3 km dan terima pemesanan via WhatsApp Business {{brand_name}}. Ini adalah diferensiasi utama dari kompetitor tanpa layanan pengiriman.',
    ],

    'bonus_variasi_layanan' => [
        'priority' => 'penting',
        'title'    => 'Perluas Variasi Layanan',
        'body'     => 'Tambahkan minimal 2 layanan baru di {{brand_name}} yang belum ada: cuci sepatu, dry-clean jas, laundry sprei/bedcover, atau setrika saja. Variasi layanan meningkatkan nilai transaksi rata-rata dan menarik segmen baru.',
    ],

    'bonus_price_list' => [
        'priority' => 'penting',
        'title'    => 'Buat dan Pasang Daftar Harga',
        'body'     => 'Desain price list yang bersih dan mudah dibaca untuk {{brand_name}}. Pasang di: highlight Instagram "Harga", foto utama Google Maps, dan pinned message WhatsApp. Review dan update setiap 3 bulan agar selalu akurat.',
    ],

    // ── Digital ───────────────────────────────────────────────────────────────

    'has_gmaps' => [
        'priority' => 'tinggi',
        'title'    => 'Daftarkan di Google Maps',
        'body'     => 'Buat Google Business Profile untuk {{brand_name}} di business.google.com. Isi jam operasional, foto outlet, nomor WA, dan deskripsi layanan. Google Maps adalah sumber utama pelanggan baru untuk bisnis laundry lokal — tidak terdaftar berarti tidak terlihat.',
    ],

    'has_instagram' => [
        'priority' => 'tinggi',
        'title'    => 'Buat Akun Instagram Bisnis',
        'body'     => 'Buat akun Instagram bisnis untuk {{brand_name}} dengan foto profil logo, bio yang lengkap, dan minimal 9 postingan awal sebelum mulai promosi. Instagram adalah channel visual utama untuk membangun kepercayaan dan brand awareness bisnis laundry.',
    ],

    'has_wa' => [
        'priority' => 'tinggi',
        'title'    => 'Aktifkan WhatsApp Business',
        'body'     => 'Aktifkan WhatsApp Business untuk {{brand_name}} dengan auto-reply pesan selamat datang, katalog layanan, dan jam operasional. WA Business menambah kepercayaan, mempermudah pemesanan, dan memungkinkan pelanggan menemukan layanan tanpa harus menelepon.',
    ],

    'has_tiktok' => [
        'priority' => 'opsional',
        'title'    => 'Buat Akun TikTok',
        'body'     => 'TikTok adalah channel pertumbuhan organik tercepat untuk bisnis lokal. Mulai dengan konten "behind the scenes" proses laundry {{brand_name}} — video 15–30 detik dengan musik trending. Satu video viral bisa mendatangkan ratusan pelanggan baru tanpa biaya iklan.',
    ],

];
