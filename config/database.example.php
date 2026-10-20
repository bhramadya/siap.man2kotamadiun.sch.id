<?php
/**
 * CONTOH konfigurasi lokal. Salin file ini menjadi config/database.local.php lalu isi nilainya.
 * database.local.php DIABAIKAN git (lihat .gitignore), jadi kredensial asli tidak pernah ikut
 * ter-commit dan tidak tertimpa saat kode diperbarui (git pull / upload ulang).
 */
return [
    // Koneksi database
    'db_host' => 'localhost',
    'db_name' => 'siapabsen',
    'db_user' => 'siapabsen',
    'db_pass' => 'GANTI_DENGAN_PASSWORD_DATABASE',

    // Alamat aplikasi TANPA slash di akhir (slash di akhir otomatis dibuang). WAJIB diisi: bila kosong,
    // situs menampilkan halaman 503 "isi base_url di config/database.local.php".
    // Produksi: 'https://siap.man2kotamadiun.sch.id'   ·   XAMPP: 'http://localhost/siap'
    'base_url' => 'https://siap.man2kotamadiun.sch.id',

    // Kunci rahasia server (minimal 32 karakter acak), dipakai menandatangani QR kelas.
    // Buat dengan: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    // Boleh dikosongkan: aplikasi membuat kunci sendiri dan menyimpannya di tabel settings.
    'app_secret' => '',

    // IP/CIDR reverse proxy tepercaya (mis. Cloudflare/nginx di depan PHP). Hanya bila permintaan
    // datang dari alamat ini, header X-Forwarded-For dipakai sebagai IP klien. Kosongkan bila
    // PHP menerima koneksi langsung dari pengunjung (paling aman).
    'trusted_proxies' => [],
];
