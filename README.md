# Aplikasi Presensi Siswa Berbasis QR Code + Device Binding

Aplikasi presensi sekolah berbasis **PHP Native (tanpa framework)**, MySQL/MariaDB,
Bootstrap 5, dan QR Code, dilengkapi sistem **Device Binding (1 Siswa = 1 Perangkat)**.

---

## 1. RINGKASAN ALUR PRESENSI (Model B — QR Gerbang)

1. Admin menampilkan halaman **QR Gerbang** (`panel/qr_gerbang.php`) di layar/monitor sekolah.
   QR ini berganti otomatis setiap beberapa menit (QR dinamis).
2. Siswa membuka `https://domainsekolah.sch.id/presensi/` di HP masing-masing.
3. Siswa men-scan QR gerbang yang tampil di layar sekolah (bukti berada di lokasi).
4. Sistem otomatis mencoba mengenali siswa dari **perangkat yang sudah terdaftar**
   (device binding) — jika cocok, siswa langsung diarahkan ke halaman konfirmasi.
5. Jika perangkat belum dikenali (pemakaian pertama kali), siswa diminta men-scan
   **QR Code pribadi** (kartu siswa) satu kali untuk identifikasi + aktivasi perangkat.
6. Siswa menekan tombol **KONFIRMASI PRESENSI** (atau **DAFTARKAN PERANGKAT & PRESENSI**
   jika perangkat baru).
7. Sistem menyimpan presensi dengan jam & tanggal dari **server**, bukan dari HP siswa.

Jika siswa mencoba presensi dari HP lain yang tidak terdaftar, sistem akan menolak
dengan pesan **"PERANGKAT TIDAK DIKENALI"** dan mencatatnya di Log Keamanan.

---

## 2. STRUKTUR FOLDER

```
presensi-siswa/
├── index.php, login.php, logout.php
├── config/database.php
├── panel/            → seluruh halaman panel untuk semua role: panel & operator (CRUD siswa, QR, presensi, rekap,
│                        device management, security log, pengaturan)
├── presensi/          → halaman publik untuk siswa (tanpa login)
├── api/                → endpoint JSON (scan.php, presensi.php, pasangan.php, cek_perangkat.php, beranda.php)
├── includes/           → fungsi inti (auth, device_binding, qr_dinamis, presensi_core)
├── assets/             → css & js
├── uploads/             → foto siswa (upload) — PHP dinonaktifkan di folder ini
└── database/database.sql
```

---

## 3. PANDUAN INSTALASI — XAMPP (Lokal)

1. Salin folder `presensi-siswa/` ke dalam `htdocs`, sehingga path-nya menjadi:
   `C:\xampp\htdocs\presensi-siswa\`
2. Jalankan **Apache** dan **MySQL** dari XAMPP Control Panel.
3. Buka `http://localhost/phpmyadmin`.
4. Buat database kosong (mis. `siapabsen`), pilih database itu, klik menu **Import**,
   pilih file `database/database.sql`, lalu klik **Go**.
   (File ini membuat seluruh tabel terbaru, akun admin default, dan pengaturan awal.
   Tidak ada data siswa dummy. Nama database harus sama dengan `DB_NAME` di langkah 5.)
5. Buka `config/database.php`, sesuaikan jika perlu:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'presensi_siswa');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   define('BASE_URL', 'http://localhost/presensi-siswa');
   ```
6. Buka `http://localhost/presensi-siswa/login.php`.
7. Login dengan akun default:
   - **Username:** `admin`
   - **Password:** `admin123`

   ⚠️ **Segera ganti password ini setelah login pertama** (lewat tabel `users` atau
   tambahkan halaman ubah password sesuai kebutuhan).

8. Halaman presensi siswa dapat diakses di `http://localhost/presensi-siswa/presensi/`.

### Catatan Kamera & Lokasi di Localhost

Browser modern mengizinkan akses kamera/`getCurrentPosition()` pada `http://localhost`
tanpa HTTPS khusus untuk keperluan development. Untuk pengujian dari HP lain di
jaringan yang sama, gunakan tunnel HTTPS (misalnya `ngrok`) karena kamera & GPS
browser **mewajibkan secure context (HTTPS)** di luar localhost.

---

## 4. PANDUAN INSTALASI — aaPanel (Hosting/VPS Produksi)

1. **Buat Website**
   - Masuk ke aaPanel → menu **Website** → **Add site**.
   - Isi domain (misal `presensi.domainsekolah.sch.id`), pilih PHP versi **8.1/8.2**.
2. **Buat Database**
   - Menu **Database** → **Add database**.
   - Catat nama database, username, dan password yang dibuat.
3. **Upload Source Code**
   - Kompres folder `presensi-siswa/` menjadi `.zip`.
   - Menu **Files**, masuk ke folder root website (`/www/wwwroot/domain-anda/`),
     upload lalu ekstrak file zip di sana.
4. **Konfigurasi Database**
   - Buka `config/database.php`, isi sesuai kredensial dari langkah 2.
   - Set `BASE_URL` sesuai domain HTTPS Anda, contoh:
     ```php
     define('BASE_URL', 'https://presensi.domainsekolah.sch.id');
     ```
   - Import `database/database.sql` melalui **phpMyAdmin** (tersedia di aaPanel App Store)
     atau menu **Database → Import** di aaPanel.
5. **Permission Folder**
   - Pastikan folder `uploads/siswa/` dan `uploads/qr/` dapat ditulis oleh web server:
     ```
     chown -R www:www /www/wwwroot/domain-anda/uploads
     chmod -R 755 /www/wwwroot/domain-anda/uploads
     ```
6. **Pengaturan PHP**
   - Menu **Website → Setting → PHP**, pastikan ekstensi berikut aktif:
     `pdo_mysql`, `fileinfo`, `mbstring`, `gd` (opsional untuk pengolahan gambar lanjutan).
   - Set `upload_max_filesize` dan `post_max_size` minimal `4M`.
7. **SSL / HTTPS (WAJIB)**
   - Menu **Website → SSL**, aktifkan **Let's Encrypt** gratis atau upload sertifikat sendiri.
   - Aktifkan opsi **Force HTTPS**.
   - **Kamera** (`getUserMedia`) dan **Geolocation API** browser modern **menolak berjalan**
     di halaman non-HTTPS (selain localhost) — sehingga HTTPS wajib untuk fitur scan QR
     dan GPS presensi berfungsi di HP siswa.
8. Akses `https://domainsekolah.sch.id/login.php` untuk admin, dan
   `https://domainsekolah.sch.id/presensi/` untuk siswa.

---

## 5. URL PENTING SETELAH INSTALASI

| Fungsi                                  | URL                       |
| --------------------------------------- | ------------------------- |
| Login Admin                             | `/login.php`              |
| Dashboard Admin                         | `/panel/dashboard.php`    |
| Halaman Presensi Siswa                  | `/presensi/`              |
| QR Gerbang (tampilkan di layar sekolah) | `/panel/qr_gerbang.php`   |
| Cetak QR Kartu Siswa                    | `/panel/qr_print.php`     |
| Manajemen Perangkat (Device Binding)    | `/panel/device.php`       |
| Log Keamanan                            | `/panel/security_log.php` |

---

## 6. AKUN & DATA DEFAULT

- **Admin:** `admin` / `admin123` (⚠️ ganti setelah instalasi)
- Tidak ada data siswa bawaan; tambahkan lewat menu Data Siswa atau Import.
- **Pengaturan awal:** jam masuk `07:00`, batas terlambat `07:15`, GPS nonaktif secara
  default, Device Binding **aktif** secara default, durasi QR gerbang 120 detik,
  maksimal reset perangkat 2x/bulan per siswa.

---

## 7. RINGKASAN FITUR DEVICE BINDING (Poin Penting)

- Setiap siswa hanya bisa presensi dari **1 perangkat** yang sudah terdaftar
  (tabel `device_bindings`, relasi 1:1 dengan `siswa` via `UNIQUE(siswa_id)`).
- Identitas perangkat dibentuk dari **UUID acak** yang dibuat browser (disimpan di
  `localStorage`) dikombinasikan dengan User-Agent, lalu **di-hash SHA-256**
  sebelum disimpan (`device_hash`) — identifier mentah **tidak pernah** disimpan.
- Pendaftaran perangkat pertama terjadi otomatis saat presensi pertama kali,
  dengan peringatan eksplisit kepada siswa sebelum tombol dikonfirmasi.
- Presensi dari perangkat lain akan **ditolak** dengan pesan generik (tanpa membocorkan
  detail teknis device_hash) dan dicatat sebagai `DEVICE_MISMATCH` di Log Keamanan.
- Admin dapat **memblokir**, **mengaktifkan kembali**, atau **mereset** perangkat siswa
  melalui menu **Manajemen Perangkat**, dengan pembatasan jumlah reset per bulan
  yang dapat diubah di menu Pengaturan.
- Semua aktivitas terkait keamanan presensi & device (match, mismatch, blokir, reset,
  presensi sukses/ditolak) tercatat di tabel `security_logs` dan dapat dilihat admin
  di menu **Log Keamanan**.
- Sistem ini **bukan pengaman mutlak** (browser tidak memiliki akses hardware ID
  permanen) — jika digabungkan dengan QR dinamis gerbang, geofencing GPS, dan
  validasi presensi ganda, kombinasi ini secara signifikan mempersulit praktik
  titip presensi dibandingkan sistem QR biasa.

---

## 8b. LIBRARY QR CODE — SUDAH DI-BUNDLE LOKAL (TIDAK BUTUH CDN)

Aplikasi ini **tidak bergantung pada CDN eksternal** untuk fitur QR Code.
File library `qrcode.js` (generate QR) dan `html5-qrcode.min.js` (scan QR via
kamera) sudah disertakan langsung di `assets/js/vendor/` dan di-load dari
server Anda sendiri. Ini membuat fitur QR tetap berfungsi meskipun:

- Jaringan sekolah memblokir/membatasi CDN pihak ketiga,
- Server tidak memiliki akses internet sama sekali,
- CDN yang dipakai sedang down.

Jika suatu saat ingin memperbarui versi library ini, cukup ganti isi file di
`assets/js/vendor/qrcode.js` atau `assets/js/vendor/html5-qrcode.min.js` dengan
versi baru yang kompatibel — tidak perlu mengubah kode PHP mana pun.

## 8. KEAMANAN YANG SUDAH DITERAPKAN

- PDO Prepared Statements di seluruh query (tidak ada string SQL yang digabung
  langsung dengan input pengguna).
- `password_hash()` / `password_verify()` untuk akun admin.
- CSRF token pada seluruh form admin (`csrf_field()` / `csrf_require()`).
- Validasi & sanitasi input, `htmlspecialchars()` pada seluruh output.
- Validasi upload foto: cek MIME type asli (`finfo`), batas ukuran 2MB, ekstensi
  di-generate ulang (bukan nama asli dari user).
- Folder `uploads/` dikonfigurasi agar **PHP tidak dapat dieksekusi** di dalamnya.
- Semua validasi krusial (token QR, device binding, jam, presensi ganda, geofence)
  dilakukan **di server (PHP)**, bukan hanya di JavaScript — JavaScript pada aplikasi
  ini hanya digunakan untuk kamera QR scanner dan pengambilan koordinat GPS.
- Jam & tanggal presensi selalu diambil dari **waktu server**, tidak pernah dipercaya
  dari input klien.

---

## Catatan Keamanan

- **Izin per role ditegakkan di `require_login()`** (`includes/auth.php`), sehingga berlaku untuk
  semua jalur akses (router `index.php` maupun akses langsung ke `/panel/xxx.php`).
- **Pembatas login:** 5 kali gagal per username atau 20 kali gagal per IP dalam 15 menit
  akan mengunci login sementara (tabel `login_attempts`, dibuat otomatis).
- **Hapus siswa** hanya lewat POST + token CSRF.
- **Header keamanan & cookie sesi** diatur di `includes/keamanan.php`. CSP belum dipasang
  karena aplikasi memakai script/style inline dan CDN.
- Ganti password `admin` bawaan dan password database (`config/database.php`) sebelum dipakai.
