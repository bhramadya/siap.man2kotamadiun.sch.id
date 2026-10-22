# SIAP — Sistem Presensi Siswa Berbasis QR Code + Device Binding

Aplikasi presensi sekolah berbasis **PHP Native (tanpa framework)**, MySQL/MariaDB,
Bootstrap 5, dan QR Code, dilengkapi **Device Binding (1 Siswa = 1 Perangkat)**
untuk mencegah titip presensi. Berjalan di browser HP maupun di aplikasi Android
pembungkus WebView (`SIAPApp`).

**Terakhir diperbarui:** 16 Oktober 2026

---

## 1. RINGKASAN ALUR PRESENSI (QR Gerbang)

1. Admin menampilkan **QR Gerbang** (`panel/qr_gerbang.php`) di layar/monitor sekolah.
   QR berganti otomatis (QR dinamis, default 120 detik).
2. Siswa membuka halaman depan situs (atau aplikasi SIAP) di HP masing-masing.
3. Siswa men-scan QR gerbang yang tampil di layar sekolah (bukti berada di lokasi).
4. Sistem mengenali siswa dari **perangkat yang sudah terdaftar**. Jika cocok, siswa
   langsung masuk ke halaman konfirmasi.
5. Jika perangkat belum terdaftar, siswa mendaftarkan perangkat (lihat bagian 8).
6. Siswa menekan **KONFIRMASI PRESENSI**.
7. Sistem menyimpan presensi dengan jam & tanggal dari **server**, bukan dari HP siswa.

Presensi dari HP yang tidak terdaftar ditolak dan dicatat di Log Keamanan.

---

## 2. FITUR UTAMA

**Halaman publik (tanpa login)**
- Beranda: ringkasan kehadiran hari ini dan riwayat realtime. Perangkat belum dikenali
  melihat nama yang disamarkan ("Abhista K. A."); perangkat terdaftar melihat data
  kelasnya sendiri.
- Presensi, konfirmasi, dan pendaftaran perangkat (`/presensi/`).
- **Portal Siswa** (`/portal`): siswa masuk dengan NISN lewat halaman login yang sama dengan petugas, untuk melihat kehadiran sendiri, **mengajukan izin/sakit**, dan menerima notifikasi keputusan petugas.

**Panel admin / operator**
- Dashboard, Papan Kehadiran Langsung (`papan`), Data Siswa (detail, tambah, ubah, hapus, import Excel/CSV).
- Master Kelas dan Kenaikan Kelas.
- QR Siswa, QR Kelas, QR Gerbang (lihat, cetak, regenerate).
- Presensi harian (ekspor & cetak), Rekap bulanan (cetak).
- Manajemen Perangkat, Kode/QR Pasangan, Kode Pasangan Massal, Reset Massal.
- **Akun Siswa**: menerbitkan kode aktivasi, reset, dan blokir akun portal siswa (lihat bagian 8b).
- **Pengajuan Izin**: antrean persetujuan izin/sakit siswa untuk petugas piket (lihat bagian 8c).
- Log Keamanan, Log Aktivitas, Log Cetak.
- Pengaturan, Pengguna, dan **Hak Akses per role** (`admin` = semua izin;
  `operator` diatur lewat menu Hak Akses).

---

## 3. STRUKTUR FOLDER

```
├── index.php            → router URL bersih (/siswa, /absensi, ...) + beranda publik
├── beranda.php, login.php, logout.php
├── config/database.php
├── config/production.php.example → contoh override BASE_URL untuk server produksi
├── ngrok.bat              → klik 2x untuk membuka tunnel ngrok (bagian 4b)
├── tools/ngrok-siap.ps1         → buka/tutup tunnel ngrok untuk uji dari HP (bagian 4b)
├── tools/ngrok-watchdog.ps1     → penjaga tunnel otomatis (opsional, bagian 4b)
├── tools/server-siap.ps1        → helper start Apache + MySQL lokal (dipakai ngrok.bat & watchdog)
├── panel/               → halaman panel admin & operator
├── portal_siswa/        → halaman portal siswa (aktivasi, beranda, izin, notifikasi, password); hanya lewat router
├── presensi/            → halaman publik siswa (scan, konfirmasi, daftar_hp, berhasil)
├── api/                 → endpoint JSON: scan, presensi, pasangan, cek_perangkat, beranda
├── includes/            → auth, functions, device_binding, presensi_core, qr_dinamis,
│                          keamanan, xlsx_reader, header, footer
├── assets/              → css, js (device.js, app.js), vendor (bootstrap, qrcode, html5-qrcode)
├── database/            → database.sql + file migrasi
├── uploads/             → foto siswa (PHP dinonaktifkan di folder ini)
├── .well-known/assetlinks.json   → verifikasi aplikasi Android
└── 403.html, 404.html, 502.html, 503.html (gangguan database)
```

---

## 4. INSTALASI — XAMPP (Lokal)

1. Salin folder aplikasi ke `C:\xampp\htdocs\siap\`.
2. Jalankan **Apache** dan **MySQL** dari XAMPP.
3. Buka `http://localhost/phpmyadmin`, buat database kosong (mis. `siapabsen`).
4. Pilih database itu → **Import** → pilih `database/database.sql` → **Go**.
   (Membuat seluruh tabel, akun admin default, dan pengaturan awal. Tanpa data siswa dummy.)
5. Salin `config/database.example.php` menjadi **`config/database.local.php`** lalu isi:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'siapabsen');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```
   `BASE_URL` **tidak perlu ditulis manual** — aplikasi menentukannya sendiri dari
   request (lihat bagian 4b). Kalau aplikasi dipasang di dalam subfolder, isi
   `define('BASE_PATH', '/siap');` (default-nya sudah otomatis dideteksi).
6. Buka `http://localhost/siap/login.php` dan login dengan akun default (bagian 7).

**Kamera & lokasi:** browser mengizinkan kamera/GPS di `http://localhost`. Untuk uji
dari HP lain gunakan tunnel HTTPS (mis. `ngrok`), karena kamera & GPS mewajibkan HTTPS
→ lihat **bagian 4b**.

---

## 4b. UJI DARI HP / APLIKASI ANDROID (TUNNEL NGROK)

Kamera (scan QR) dan GPS **hanya berjalan di halaman HTTPS**. Untuk menguji dari HP —
atau dari aplikasi Android pembungkus WebView (`SIAPApp`) — tanpa mengupload ke
hosting, jalankan aplikasi lokal lewat tunnel **ngrok** (URL publik HTTPS).

### Prasyarat

- Apache Laragon jalan (port 80) dan MySQL jalan.
- ngrok v3 terpasang + authtoken sudah diisi:
  ```
  ngrok config add-authtoken <TOKEN>
  ```
  Authtoken tersimpan di `%LOCALAPPDATA%\ngrok\ngrok.yml` (sudah terisi di
  mesin ini). Periksa dengan `ngrok config check`.

### Menjalankan — klik 2x `ngrok.bat`

Cara paling cepat: **klik dua kali `ngrok.bat`** di folder aplikasi
(`D:\laragon\www\siap\ngrok.bat`).

```
==============================================================
  SIAP - Tunnel ngrok
  URL publik : https://magma-nautical-dividing.ngrok-free.dev/siap/
==============================================================

[SIAP] Menyiapkan server lokal (Apache + MySQL)...
[SIAP] Membuka tunnel ngrok ke http://localhost:80 ...
```

Yang terjadi:

1. Apache + MySQL lokal otomatis dinyalakan bila belum hidup.
2. Tunnel ngrok dibuka ke `http://localhost:80` dengan URL yang di-pin.
3. Jendela cmd **tetap terbuka** sambil menampilkan dashboard ngrok — selama
   jendela ini hidup, website bisa diakses orang lain dari luar jaringan.

**Cara menghentikan:** tutup jendela cmd (atau tekan `Ctrl+C` di jendela itu).
Tunnel langsung mati dan URL publik kembali `offline (ERR_NGROK_3200)`.

Kalau diklik dua kali, jendela kedua akan mendeteksi tunnel yang sudah aktif
lalu menampilkan URL-nya (tidak membuka agent kedua, yang akan gagal dengan
`ERR_NGROK_334`).

### Cara manual (PowerShell)

```powershell
powershell -ExecutionPolicy Bypass -File tools\ngrok-siap.ps1
```

Script akan memastikan Apache listen di port 80, menjalankan
`ngrok http 80`, menunggu tunnel siap, lalu menampilkan URL publik beserta
tautan siap pakai (beranda, presensi, login, QR gerbang). Subfolder aplikasi
pada URL publik (mis. `/siap`) ikut dideteksi otomatis. Tunnel berjalan di
background (tidak ada jendela yang harus dibiarkan terbuka).

URL publik **di-pin** ke reserved domain ngrok milik sekolah:

```
https://magma-nautical-dividing.ngrok-free.dev/siap
```

Jadi alamatnya tidak berubah-ubah antar restart dan QR Code yang sudah dicetak
tetap berlaku. Penempinannya dilakukan di parameter `-Url` (default) pada
`tools/ngrok-siap.ps1`; script memakai flag `--url` (ngrok ≥ 3.30) dan otomatis
beralih ke `--domain` bila memakai versi lama. Ingin mengganti domain:
ubah parameter `-Url`, atau jalankan `ngrok http 80 --url https://domain-baru`.

Perintah lain:

```powershell
... -Action status        # URL tunnel yang sedang aktif
... -Action status-url    # cetak URL saja (dipakai ngrok.bat)
... -Action stop          # tutup tunnel
... -Action restart       # tutup lalu buka lagi
... -Action ensure-server # pastikan Apache + MySQL lokal hidup
```

Buka URL publik di HP. **Halaman pertama di tiap perangkat** akan menampilkan
peringatan ngrok “Visit Site” — tekan tombolnya **sekali** (cookie berlaku 7 hari).
Aplikasi Android/WebView dapat melewati peringatan ini dengan memakai
*custom User-Agent* (mis. `SIAPApp/1.0`).

### Mode otomatis (opsional)

Tunnel normalnya **on-demand** (hanya hidup saat `ngrok.bat` dijalankan).
Bila ingin tunnel tetap hidup tanpa perlu klik, pakai penjaga:

`tools/ngrok-watchdog.ps1` — setiap 60 detik memastikan Apache + MySQL hidup
dan tunnel ngrok masih terhubung; bila mati, service dinyalakan / tunnel
dibuka kembali otomatis (berguna setelah komputer sleep atau ngrok crash).
Log penjaga: `tools/ngrok-watchdog.log`.

Untuk menjalankannya otomatis sejak login Windows, daftarkan sebagai
Task Scheduler **“SIAP-Ngrok-Tunnel”** (tidak didaftarkan secara bawaan):

```powershell
$a = New-ScheduledTaskAction -Execute 'powershell.exe' `
       -Argument '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "D:\laragon\www\siap\tools\ngrok-watchdog.ps1"'
Register-ScheduledTask -TaskName 'SIAP-Ngrok-Tunnel' -Action $a `
       -Trigger (New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME)
```

Lalu atur auto-start Laragon (`D:\laragon\usr\laragon.ini` → `RunAtStartup=-1`)
agar Apache + MySQL ikut hidup setelah restart.

> Task penjaga **tidak** menyalakan Laragon; MySQL/Apache tetap perlu hidup.

> **URL tanpa `/siap`?** Tunnel memakai default vhost Laragon, jadi aplikasinya
> berada di `/siap`. Agar berada di root domain, buat vhost Apache untuk domain
> ngrok di `D:\laragon\etc\apache2\sites-enabled\` dengan
> `DocumentRoot "D:/laragon/www/siap"` + `ServerName <domain-ngrok>`, lalu
> restart Apache. Base URL dan QR Code tetap benar otomatis.

### Yang terjadi di belakang layar

- **`config/database.php`** menentukan `BASE_URL` otomatis dari request: skema
  (`https`) + host yang diminta browser + subfolder aplikasi (dibandingkan dengan
  `DOCUMENT_ROOT`). Jadi QR Code, tautan, dan redirect selalu memakai URL yang
  benar — baik di `http://siap.test` maupun lewat tunnel ngrok.
  Host diambil dari header `Host` (nilainya sudah dijamin benar: ngrok menolak
  request dengan `Host` yang tidak cocok dengan endpoint-nya), **bukan** dari
  `X-Forwarded-Host` yang bisa dipalsukan klien.
- **`.htaccess`** memakai `SetEnvIf` untuk membaca header `X-Forwarded-Proto`
  dari ngrok, sehingga redirect `.php` → URL bersih tetap mengarah ke **URL
  publik HTTPS** (bukan `http://...` yang diblokir browser sebagai mixed content).
- **`includes/keamanan.php`** otomatis memakai cookie sesi `Secure` + HSTS saat
  request datang lewat tunnel HTTPS.

### Catatan penting

- Tunnel ini membuat aplikasi **terbuka untuk siapa pun yang tahu URL-nya**.
  Ganti password admin bawaan (`admin` / `admin123`) lewat menu **Profil** sebelum
  siswa/karyawan memakainya.
- Free plan ngrok: **1 GB transfer + 20.000 request per bulan**, maksimal 3 endpoint
  aktif, dan **1 domain dev** (alamat domainnya tetap sama antar restart).
- QR Code yang sudah dicetak memuat URL publik. Karena domain di-pin di
  `tools/ngrok-siap.ps1` (parameter `-Url`), alamatnya tetap sama selama
  authtoken & reserved domain tidak berubah; bila berganti akun/domain, QR
  perlu dicetak ulang.
- Untuk produksi tetap pakai domain sekolah + hosting (bagian 5), dan set
  `BASE_URL` secara eksplisit di server lewat `config/production.php`
  (contoh: `config/production.php.example`) atau environment variable
  `SIAP_BASE_URL`.

---

## 5. INSTALASI — aaPanel (Hosting/VPS Produksi)

1. **Buat Website** di aaPanel (domain sekolah, PHP **8.1/8.2**).
2. **Buat Database**, catat nama, user, dan password.
3. **Upload source** ke `/www/wwwroot/<domain-anda>/` lalu ekstrak.
4. **Konfigurasi** `config/database.php` (kredensial DB). Import `database/database.sql`
   lewat phpMyAdmin. `BASE_URL` dideteksi otomatis dari request, jadi di server
   produksi tetap sebaiknya di-*override* supaya URL tidak pernah bergantung pada
   header: salin `config/production.php.example` → `config/production.php` lalu isi
   domain HTTPS-nya (mis. `https://siap.man2kotamadiun.sch.id`), atau set environment
   variable `SIAP_BASE_URL`.
5. **Permission** folder `uploads/`:
   ```
   chown -R www:www /www/wwwroot/<domain-anda>/uploads
   chmod -R 755 /www/wwwroot/<domain-anda>/uploads
   ```
6. **PHP**: aktifkan `pdo_mysql`, `fileinfo`, `mbstring`, `gd` (opsional);
   `upload_max_filesize` dan `post_max_size` minimal `4M`.
7. **open_basedir**: file `.user.ini` membatasi PHP ke folder situs dan `/tmp/`.
   **Ubah path di `.user.ini` jika domain/folder Anda berbeda**, atau aplikasi akan error.
8. **SSL/HTTPS (WAJIB)**: aktifkan Let's Encrypt + Force HTTPS. Kamera dan Geolocation
   tidak berjalan di halaman non-HTTPS.
9. **URL bersih**: `.htaccess` menyembunyikan `.php` dari address bar (butuh `mod_rewrite`).

---

## 6. URL BERSIH (ROUTING)

Seluruh halaman panel memakai URL bersih tanpa `.php` dan tanpa `?modul=`.
Semua dilayani `index.php` (front controller) lewat aturan di `.htaccess`.

| Halaman | URL |
|---|---|
| Dashboard | `/dashboard` |
| Daftar siswa | `/siswa` — filter: `/siswa?q=sajida&kelas=X%20B`, `/siswa?kelas=X%20B&limit=20` |
| Detail siswa | `/siswa/2026001` |
| Tambah siswa | `/siswa/tambah` |
| Edit siswa | `/siswa/2026001/edit` |
| QR Code siswa | `/siswa/2026001/qr` |
| Import / hapus siswa | `/siswa/import`, `/siswa/hapus` (POST) |
| Data kelas | `/kelas` · kenaikan kelas: `/kelas/kenaikan` |
| Riwayat absensi | `/absensi` · ekspor `/absensi/ekspor?type=csv` · cetak `/absensi/cetak` |
| Rekap | `/rekap` · cetak `/rekap/cetak` |
| Papan langsung | `/papan` |
| Perangkat | `/perangkat` · kode pasangan `/perangkat/kode?siswa_id=…` · massal `/perangkat/kode-massal` |
| QR | `/qr/siswa` · `/qr/siswa/cetak` · `/qr/kelas` · `/qr/kelas/cetak` · `/qr/gerbang` |
| Log | `/log/keamanan` · `/log/aktivitas` · `/log/cetak` |
| Pengaturan | `/pengaturan` |
| Akun siswa (admin) | `/akun-siswa` |
| Pengajuan izin (petugas) | `/izin` · tinjau `/izin/{id}` · lampiran `/izin/{id}/lampiran` |
| Portal siswa | `/portal` · `/portal/aktivasi` · `/portal/password` · `/portal/izin` · `/portal/izin/baru` · `/portal/izin/{id}` · `/portal/notifikasi` (masuk lewat `/login`, lihat bagian 8b) |
| Pengguna & hak akses | `/pengguna` · `/pengguna/hak-akses` · profil `/profil` |
| Beranda publik, login | `/` · `/login` |
| Presensi siswa (publik) | `/presensi/` (tidak berubah, QR kartu siswa tetap berlaku) |

**Aturan penting**
- `{nis}` pada URL adalah **NIS siswa**, bukan id database. Jika NIS diubah, URL siswa itu ikut berubah.
- Jumlah baris per halaman memakai parameter **`limit`** (pilihan 10/20/50/100). Nama lama `per_page` masih diterima.
- URL lama (`index.php?modul=siswa&...`, `/panel/siswa.php`) otomatis dialihkan (301) ke URL bersih, jadi bookmark lama tidak rusak.
- Peta rute ada di satu tempat: konstanta `RUTE_MODUL` di `includes/functions.php`. Untuk membuat tautan **selalu**
  pakai `modul_url('nama_modul', ['param' => 'nilai'])`; jangan menempelkan `&param=...` secara manual.
- Jangan membuat rute berawalan nama folder nyata (`api`, `assets`, `config`, `includes`, `panel`, `presensi`, `uploads`, `database`).
- Server **Apache/LiteSpeed** (aaPanel default) cukup memakai `.htaccess` bawaan. **Nginx mengabaikan `.htaccess`**,
  jadi semua perlindungan folder harus ditulis di konfigurasi situs (lihat blok di bawah).

**Nginx — bagian A: aaPanel** (Website → situs → *URL rewrite*). Kotak ini disisipkan aaPanel **di dalam** blok
`server` miliknya, jadi tempel **hanya** isi berikut (tanpa `server { }` dan tanpa `location ~ \.php$`, karena PHP
sudah diatur aaPanel lewat `enable-php-XX.conf`). Folder memakai `^~` supaya menang atas lokasi PHP aaPanel yang
muncul lebih dulu:

```nginx
# 1) Folder internal: tidak boleh dibuka dari browser (dipakai PHP lewat filesystem saja)
location ^~ /config/       { deny all; return 404; }
location ^~ /includes/     { deny all; return 404; }
location ^~ /database/     { deny all; return 404; }
location ^~ /portal_siswa/ { deny all; return 404; }

# 2) Berkas sensitif di mana pun: dump SQL, dokumentasi, .htaccess/.user.ini/.git, backup
location ~* \.(sql|md|ini|log|bak|sh)$ { deny all; return 404; }
location ~ /\.(?!well-known/) { deny all; return 404; }

# 3) Unggahan: surat izin/dokter (data kesehatan) ditolak total; folder lain hanya berkas statis,
#    PHP TIDAK PERNAH dieksekusi di bawah /uploads/
location ^~ /uploads/izin/ { deny all; return 404; }
location ^~ /uploads/ {
    location ~ \.(php|phtml|phar|php\d)$ { deny all; return 404; }
}

# 4) URL bersih: /login -> login.php, /siswa/123 -> index.php (router)
location / { try_files $uri $uri/ @siap; }
location @siap {
    if (-f $document_root$uri.php) { rewrite ^ $uri.php last; }
    rewrite ^ /index.php last;
}
```

Simpan, lalu pastikan `nginx -t` lolos (aaPanel menolak menyimpan bila ada galat).

**Nginx — bagian B: vhost tulis sendiri** (tanpa aaPanel). Seluruh blok `server`; isi bagian 1–4 sama persis dengan
bagian A, ditambah lokasi PHP. Sesuaikan `root` dan `fastcgi_pass`:

```nginx
server {
    # listen, server_name, ssl_certificate ...
    root  /www/wwwroot/siap.man2kotamadiun.sch.id;
    index index.php;

    # --- tempel bagian 1–4 dari blok A di sini ---

    # 5) PHP
    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass unix:/tmp/php-cgi-82.sock;   # sesuaikan versi/socket PHP
        fastcgi_index index.php;
        include fastcgi.conf;
    }
}
```

Jangan memakai `try_files ... $uri.php ...` langsung di `location /`: Nginx akan mengirim isi file PHP sebagai teks.
Setelah import, **hapus `database/database.sql` dari server produksi** (cukup simpan di komputer admin). Selain aturan
server, setiap file di `includes/`, `config/`, `panel/`, dan `portal_siswa/` juga menolak dibuka langsung dari PHP-nya
sendiri (pemeriksaan `APP_ROUTER` / `SCRIPT_FILENAME`), jadi kesalahan konfigurasi server tidak langsung membuka
halaman internal.

---

## 7. AKUN & DATA DEFAULT

- **Admin awal:** username `admin`, password bawaan dari `database.sql`. Saat login pertama aplikasi **memaksa
  penggantian password**: semua halaman panel dialihkan ke Profil sampai password baru disimpan (kolom
  `users.must_change_password`). Akun mana pun yang login dengan password bawaan (mis. `admin123`) ikut ditandai.
- Tidak ada data siswa bawaan; tambahkan lewat Data Siswa atau Import.
- **Pengaturan awal:** jam masuk `07:00`, batas terlambat `07:15`, **GPS aktif** (koordinat contoh — **wajib
  diganti** dengan koordinat sekolah di Pengaturan, karena presensi di luar radius ditolak), QR Kelas **bertanda
  waktu** (bukan permanen), validasi IP nonaktif, Device Binding **aktif**, QR gerbang 120 detik,
  maksimal reset perangkat 2x/bulan per siswa, hari efektif Senin–Jumat.
- Retensi Log Keamanan bisa diatur di Pengaturan (0 = tidak pernah dihapus otomatis).

---

## 8. DEVICE BINDING (Poin Penting)

**Identitas perangkat**
- **Bukti utama = rahasia dari server.** Saat perangkat didaftarkan (otomatis, Kode Pasangan, atau QR Pasangan),
  server membuat rahasia acak 256-bit dan menyimpannya di cookie **`SIAP_PERANGKAT`** (`HttpOnly`, `SameSite=Lax`,
  `Secure` di HTTPS, 400 hari dan diperpanjang setiap presensi). Database hanya menyimpan hash-nya
  (`device_bindings.secret_hash`). Skrip di halaman tidak bisa membaca cookie ini, dan menyalin UUID saja tidak lagi
  cukup untuk presensi atas nama siswa lain.
- Browser/WebView tetap membuat **UUID acak** di `localStorage` + cookie (`assets/js/device.js`); server menyimpan
  `device_hash` = `sha256('v2|' + uuid)` untuk referensi, mencegah satu UUID dipakai dua siswa, dan untuk migrasi.
- **Migrasi perangkat lama (dua tahap), siswa tidak perlu daftar ulang:**
  1. Perangkat yang terdaftar sebelum upgrade (belum punya `secret_hash`) masih dikenali dari UUID.
  2. Rahasia server dikirim ke HP itu **setelah presensi berhasil penuh** (lolos cek presensi ganda, jam, hari libur,
     GPS), atau saat HP membuka beranda / cek perangkat (`api/beranda`, `api/cek_perangkat`) dan tidak membawa cookie
     milik perangkat lain. Presensi yang **ditolak tidak pernah** mengganti kunci. Status binding: *menunggu*
     (UUID **dan** cookie masih diterima, jadi HP asli tidak terkunci bila orang lain sempat mengambil cookie lewat
     beranda, atau bila WebView kehilangan cookie).
  3. Status menjadi *aktif* (UUID saja tidak diterima lagi) hanya bila cookie dipakai dalam **presensi yang berhasil**
     **dan** cookie itu diterbitkan oleh presensi berhasil pada **hari sebelumnya** (`secret_terbit_at`,
     `secret_terbit_oleh`). Cookie dari beranda/cek perangkat (*pasif*) tidak pernah langsung diaktifkan: presensi
     berhasil dengan cookie itu hanya menggantinya dengan cookie baru bertanda *checkin*. Jadi orang yang menyalin UUID
     tidak bisa mengambil alih perangkat dalam satu sesi; ia perlu presensi berhasil pada dua hari berbeda tanpa HP asli
     presensi di antaranya. Biasanya HP asli menjadi *aktif* pada presensi hari berikutnya.
  - **Batas masa transisi (opsional):** Pengaturan → *Batas pengenalan perangkat lama* (`settings.device_legacy_until`).
    Kosong (bawaan) = tanpa batas. Bila diisi dan tanggalnya lewat, perangkat yang belum *aktif* tidak dikenali lagi
    dari UUID dan harus direset admin. Tanggalnya ditentukan sekolah; jumlah perangkat yang belum termigrasi tampil
    di **Manajemen Perangkat**.
- **User-Agent TIDAK ikut di-hash** (UA berubah setiap Chrome/WebView ter-update).
**Aturan**
- 1 siswa = 1 perangkat (`UNIQUE(siswa_id)`), dan 1 perangkat = 1 siswa (`UNIQUE(device_hash)`).
- Perangkat baru didaftarkan lewat salah satu cara (dibuat admin, sekali pakai):
  1. **QR Pasangan** — siswa scan QR dari admin (`presensi/daftar_hp.php`).
  2. **Kode Pasangan 6 angka** — diketik siswa saat konfirmasi presensi.
- Berlaku 10 menit (pembuatan massal bisa tanpa batas waktu), terkunci setelah 5 kali salah.
  Yang tersimpan di database hanya hash.
- Pengaturan **"Wajib Kode Pasangan"**: bila aktif, perangkat baru tidak didaftarkan otomatis.
- Admin dapat **memblokir**, **mengaktifkan kembali**, atau **mereset** perangkat
  (satu per satu atau massal per kelas/jenjang/pilihan). Reset individual dibatasi per bulan;
  reset massal tidak menghabiskan jatah tersebut.
- Semua kejadian (match, mismatch, blokir, reset, pairing gagal, presensi sukses/ditolak)
  tercatat di `security_logs`.

**Migrasi dari versi lama**
Hash skema lama (v1 = UUID + User-Agent) dimigrasikan otomatis ke v2 lewat kolom `device_uuid`; tidak ada file SQL
terpisah. Siswa yang sudah terdaftar **tidak perlu daftar ulang**. Baris tanpa `device_uuid` (seharusnya tidak ada)
perlu direset lalu didaftarkan ulang.

**Keterbatasan (jujur)**
Ini bukan hardware ID permanen. Bila siswa menghapus cookie/data browser, memakai incognito, ganti browser, atau ganti
HP, rahasianya hilang dan perangkat **tidak cocok lagi** (admin perlu mereset perangkat siswa itu). Pemilik HP yang
sengaja mengekspor cookie-nya sendiri ke HP teman masih bisa menitip absen; karena itu tetap aktifkan GPS dan QR kelas
bertanda waktu. Selama masa transisi, orang yang menyalin UUID perangkat yang belum *aktif* masih bisa presensi atas
nama siswa itu (seperti sebelum pembaruan) dan, bila presensinya berhasil penuh, mengambil alih binding; isi batas masa
transisi setelah sebagian besar perangkat termigrasi.

**QR Kelas: permanen atau bertanda waktu** (Pengaturan → *QR Kelas permanen*)
- **Mati (bawaan instalasi baru, disarankan):** isi QR kelas adalah token `k1.<id>.<kedaluwarsa>.<hmac>` yang
  ditandatangani server (HMAC-SHA256, kunci `app_secret` atau `settings.kunci_rahasia`) dan berlaku sebesar durasi QR
  gerbang + 3 menit. Halaman QR kelas memuat ulang sendiri, jadi tampilkan di layar/proyektor kelas. Foto QR yang
  dibagikan cepat kedaluwarsa; tombol cetak disembunyikan dan poster lama tidak berlaku.
- **Hidup (perilaku lama):** QR kelas tetap dan boleh dicetak. Instalasi lama otomatis berada di mode ini setelah
  upgrade agar poster yang sudah ditempel tidak mendadak mati; Dashboard dan Pengaturan menampilkan peringatan merah
  sampai mode ini dimatikan. Peringatan yang sama muncul bila validasi GPS nonaktif.

---

## 8b. PORTAL SISWA (AKUN & KODE AKTIVASI)

Siswa dapat memiliki akun sendiri di `/portal` (username = **NISN**). Halaman masuknya **hanya satu**, yaitu
`/login` (kolom *Username / NISN*): sistem membedakan sendiri. Bila ada petugas dengan username itu, ia masuk sebagai
petugas; bila tidak, input dianggap NISN siswa dan diarahkan ke `/portal` (sesi terpisah). Bila username petugas
kebetulan sama dengan sebuah NISN, **petugas yang menang**, jadi hindari username petugas berupa angka.
Siswa **tidak mendaftar sendiri** dan
**admin tidak pernah membuat/mengetahui password**:

1. Admin membuka **Akun Siswa** → *Buat kode* (satuan, dicentang, atau seluruh kelas/sekolah).
2. Sistem menampilkan **kode aktivasi 8 karakter** (mis. `K7M2-9QXA`) **sekali saja** — cetak kartunya atau unduh CSV.
   Yang tersimpan di database hanya hash (PBKDF2 + garam); kode berlaku **7 hari** dan hanya untuk satu siswa.
3. Siswa membuka `/portal/aktivasi`, mengisi NISN + kode, lalu **membuat password sendiri**
   (minimal 8 karakter, huruf + angka, tidak boleh sama dengan NISN/NIS atau password umum).
4. Lupa password / akun diambil alih → admin menekan **Reset**: password lama dihapus, semua sesi berhenti,
   siswa aktivasi ulang dengan kode baru.

**Pemisahan dari admin**
- Sesi sendiri (cookie `SIAP_SISWA`, path `/portal`); cookie admin tidak dibaca portal dan sebaliknya.
- Tabel sendiri (`siswa_akun`), bukan tabel `users` → tidak ada jalan naik ke panel admin.
- Pembatas percobaan sendiri (`portal_attempts`) agar kegagalan siswa di Wi-Fi sekolah tidak mengunci login admin.

**Keamanan yang diterapkan**
- Gagal login dibatasi per NISN dari IP yang sama (5x / 15 menit, kunci) dan per IP (100x, kunci; IP yang pernah
  login berhasil ke NISN itu dikecualikan). Di atas 30 gagal per NISN dari IP mana pun, IP tak dikenal mendapat jeda
  bertahap (1–60 detik), bukan kunci. Salah kode aktivasi 5x mengunci 15 menit.
- Pesan galat sengaja umum, dan pesan serta waktu respons jalur petugas, siswa, dan username tak dikenal disamakan
  (terukur ±380 ms semua), supaya username petugas maupun NISN yang punya akun tidak bisa ditebak.
- Login siswa **bukan pengganti Device Binding**: presensi tetap lewat perangkat terdaftar.
- Akun otomatis tidak bisa dipakai bila siswa dinonaktifkan atau diblokir; sesi lama langsung berakhir.
- Mengganti password mengeluarkan perangkat lain. Keluar hanya lewat POST + token CSRF.
- Siswa **tanpa NISN atau dengan NISN ganda** tidak bisa dibuatkan akun (ditandai di daftar).
- Admin dapat menutup seluruh portal sementara lewat tombol *Tutup portal*.

**Izin (menu Hak Akses):** `akun_siswa.lihat` (melihat daftar) dan `akun_siswa.kelola` (menerbitkan kode, reset,
blokir, buka/tutup portal). Izin ini **tidak** diberikan otomatis ke Operator; berikan lewat Hak Akses.

Alamat lama `/portal/masuk` otomatis dialihkan ke `/login`. File `portal_siswa/masuk.php` dari versi sebelumnya sudah tidak dipakai dan boleh dihapus.

**Instalasi:** sudah termasuk di `database/database.sql`; pada instalasi lama tabel dibuat otomatis saat pertama dipakai. Bila akun database tidak punya hak `CREATE`,
jalankan `database/migration_akun_siswa.sql` sekali.

**Batas yang perlu diketahui:** sesi PHP berakhir sesuai `session.gc_maxlifetime` server (biasanya ±24 menit tanpa
aktivitas), jadi siswa bisa diminta masuk ulang. Penguncian keras hanya per NISN + IP dan per IP, jadi orang lain
tidak bisa mengunci seorang siswa dari jaringan lain; serangan dari banyak IP hanya memperlambat (jeda), tidak mengunci.

---

## 8c. PENGAJUAN IZIN / SAKIT & NOTIFIKASI

**Alur:** siswa mengajukan di `/portal/izin/baru` → petugas piket memutuskan di `/izin` → siswa mendapat
notifikasi di portal. Izin yang **disetujui** ditulis ke tabel `presensi` (status `IZIN`/`SAKIT`, metode
`PENGAJUAN`, tanpa jam masuk) untuk **hari efektif** dalam rentang, sehingga Data Presensi, Rekap, Dashboard, dan
ekspor yang sudah ada otomatis ikut tanpa diubah.

**Aturan pengajuan** (konstanta di `includes/izin.php`): mundur maksimal 3 hari, maju maksimal 30 hari; satu pengajuan
maksimal 14 hari; **sakit lebih dari 1 hari sekolah wajib melampirkan surat** (JPG/PNG/WEBP/PDF, maks. 3 MB); satu
pengajuan aktif per tanggal; maksimal 5 pengajuan menunggu sekaligus; siswa boleh membatalkan selama masih menunggu.

**Keputusan petugas:** *Setujui* (catatan opsional), *Tolak* (alasan **wajib**, dibaca siswa), *Cabut persetujuan*
(alasan wajib; hanya baris presensi `PENGAJUAN` yang dihapus, presensi kehadiran asli tidak pernah disentuh), dan
*Setujui yang dicentang* untuk persetujuan massal. Semuanya tercatat di Log Aktivitas.

**Bila siswa yang izinnya disetujui tetap hadir:** presensi QR tetap diterima dan **HADIR yang berlaku**, baris izin
hari itu diganti, dan izin hari itu ditandai `dipakai_hadir`.

**Notifikasi:** siswa melihat lencana di menu **Notifikasi** dan banner di beranda (disetujui/ditolak/dicabut);
petugas melihat lencana jumlah di menu **Pengajuan Izin** dan pengingat di Dashboard. Notifikasi di dalam portal saja;
belum ada WhatsApp/email. Maksimal 100 notifikasi terbaru per siswa disimpan.

**Izin akses (Hak Akses):** `izin.lihat` (melihat pengajuan & lampiran) dan `izin.proses` (setujui/tolak/cabut,
hapus lampiran lama). **Tidak diberikan otomatis ke Operator**, jadi centang untuk petugas piket di menu Hak Akses.

**Lampiran = data kesehatan.** Disimpan di `uploads/izin/` dengan nama acak, **tidak bisa dibuka langsung dari web**
(`.htaccess` menolak; pada **Nginx** pakai blok lengkap di bagian 6), hanya lewat skrip yang
memeriksa hak akses: pemilik pengajuan dan petugas ber-izin `izin.lihat`. PDF dikirim sebagai unduhan, gambar
dengan `nosniff` + CSP. Setiap pembukaan lampiran oleh petugas dicatat di **Log Keamanan**
(`IZIN_LAMPIRAN_DILIHAT`). Tombol **Hapus lampiran lama** (halaman Pengajuan Izin) menghapus berkas dari
pengajuan yang sudah diproses lebih dari N hari (bawaan 90) — data pengajuannya tetap ada. Tentukan kebijakan
retensi bersama pihak madrasah.

**Instalasi:** tabel `izin_pengajuan`, `izin_hari`, `portal_notifikasi` dibuat otomatis saat pertama dipakai; bila
akun database tidak punya hak `CREATE`, jalankan `database/migration_izin.sql`. Pastikan `upload_max_filesize` dan
`post_max_size` minimal `4M` dan folder `uploads/` dapat ditulis server web.

---

## 9. LIBRARY QR CODE — DI-BUNDLE LOKAL

`qrcode.js` (generate) dan `html5-qrcode.min.js` (scan kamera) sudah ada di
`assets/js/vendor/`, begitu juga Bootstrap, Bootstrap Icons, dan font. Tidak ada
ketergantungan CDN, sehingga tetap berfungsi bila jaringan sekolah membatasi CDN atau
server tanpa internet. Untuk memperbarui, cukup ganti file di folder `vendor/`.

---

## 10. KEAMANAN YANG DITERAPKAN

- PDO Prepared Statements di seluruh query.
- `password_hash()` / `password_verify()` untuk akun.
- CSRF token pada seluruh form admin.
- Validasi & sanitasi input; `htmlspecialchars()` pada output.
- Upload foto: cek MIME asli (`finfo`), batas 2MB, nama file di-generate ulang.
- `uploads/` tidak dapat mengeksekusi PHP; `config/`, `includes/`, `database/` dilindungi `.htaccess`;
  file `.sql` dan `.md` tidak dapat diakses dari browser. Untuk Nginx gunakan blok lengkap di bagian 6.
- File internal menolak akses langsung walau aturan server terlewat: `includes/*` dan `config/*` hanya bisa
  di-include, halaman `panel/*` dan `portal_siswa/*` hanya jalan lewat router (`APP_ROUTER`). Endpoint lampiran
  izin memeriksa login + izin `izin.lihat` sendiri (petugas) atau kepemilikan pengajuan (siswa).
- **Izin per role ditegakkan di `require_login()`** (`includes/auth.php`) untuk semua jalur
  (router maupun akses langsung ke `/panel/xxx.php`).
- **Pembatas login** (tabel `login_attempts` + `login_ip_dikenal`, dibuat otomatis; sama untuk portal siswa):
  1. **Kunci 15 menit** setelah 5 gagal untuk satu username dari IP yang sama (berlaku untuk semua IP).
  2. **Kunci 15 menit** setelah 20 gagal dari satu IP (semua username; portal siswa: 100). Untuk IP yang dalam 30 hari
     terakhir pernah login berhasil ke username itu ("IP dikenal") batasnya lebih longgar tetapi tetap ada:
     **300 gagal / 15 menit dari IP itu (semua akun)**. Siswa di balik NAT Wi-Fi sekolah tidak mudah mengunci petugas
     yang biasa login dari jaringan itu, dan tebakan dari IP bersama tetap terbatas (mis. ±300 per 15 menit, bukan
     5 per akun untuk ribuan akun).
  3. Lebih dari 100 gagal untuk satu username dari IP mana pun **tidak mengunci**: IP yang tidak dikenal hanya boleh
     satu pemeriksaan password untuk username itu tiap 1–60 detik (jeda naik bertahap tiap 20 kegagalan). Ini membatasi
     tebakan terdistribusi (botnet, banyak alamat IPv6) sekitar 1.440 per hari, sementara pemilik akun dari IP dikenal
     tidak terpengaruh.
  - IPv6 dihitung per blok **/64**; alamat IPv4-mapped (`::ffff:1.2.3.4`) dibaca sebagai IPv4 biasa. Login berhasil
    hanya menghapus riwayat gagal dari IP itu sendiri.
  - **Aman dari permintaan paralel:** pemeriksaan batas dan pencatatan percobaan dilakukan di bawah kunci MySQL
    `GET_LOCK` (per IP lalu per akun) **sebelum** password diperiksa; setiap percobaan dicatat dulu sebagai gagal
    dan dihapus bila password benar. Jadi 40 permintaan serentak dari satu IP tetap hanya mendapat 5 pemeriksaan
    password, dan saat jeda aktif hanya satu permintaan per jeda yang diperiksa.
  - **Batas yang tersisa (jujur):** orang yang memakai IP yang sama dengan petugas (mis. satu NAT sekolah) tetap bisa
    mengunci username itu dari IP tersebut selama 15 menit dengan 5 password salah (aturan 1), dan saat diserang
    besar-besaran petugas yang login dari perangkat/jaringan **baru** bisa tidak kebagian giliran: penyerang yang
    mengirim satu percobaan setiap jeda ikut memakai setiap giliran pemeriksaan (hingga 60 detik). Password yang
    benar sengaja **tidak** diloloskan saat jeda, karena itu justru membuat jeda tidak berguna (setiap tebakan tetap
    diperiksa). Pemulihan tercepat: login dari jaringan yang biasa dipakai (IP dikenal, tidak kena jeda), atau tunggu
    sampai serangan berhenti / 15 menit.
- **IP klien = `REMOTE_ADDR`.** Header `X-Forwarded-For` hanya dibaca bila permintaan datang dari proxy yang
  terdaftar di `trusted_proxies` (`config/database.local.php`), sehingga pembatas login/portal tidak bisa diakali
  dengan header palsu. Bila situs berada di belakang Cloudflare/reverse proxy, isi `trusted_proxies` dengan
  IP/CIDR proxy tersebut; bila tidak, semua pengunjung akan terlihat ber-IP proxy.
- **Header keamanan & cookie sesi** (`HttpOnly`, `SameSite=Lax`, `Secure` di HTTPS, strict mode,
  regenerasi ID sesi berkala) diatur di `includes/keamanan.php`. CSP belum dipasang karena
  aplikasi memakai script/style inline.
- Semua validasi krusial (token QR, device binding, jam, presensi ganda, geofence) dilakukan
  **di server**. Jam & tanggal presensi selalu dari waktu server.
- Hapus siswa hanya lewat POST + token CSRF.
- **Halaman gangguan database (503):** bila koneksi database gagal, pengguna melihat halaman rapi
  `503.html` (bukan teks polos), dengan tombol "Coba Lagi" dan percobaan ulang otomatis tiap 30 detik.
  Detail galat **tidak** ditampilkan; hanya *kode kejadian* acak (mis. `DB-8D9316`) yang muncul di halaman
  dan **ditulis juga ke error log server**, sehingga laporan pengguna bisa dicocokkan dengan lognya.
  Permintaan API / AJAX dijawab JSON (`{"success":false,"code":"DB_UNAVAILABLE",...}`), dan popup formulir
  hanya menampilkan pesan singkat. Logikanya ada di `tampilkan_error_database()` di `config/database.php`.
- Ganti password `admin` bawaan dan password database sebelum dipakai produksi.

---

## 11. UPGRADE DARI VERSI SEBELUMNYA

Lakukan berurutan, **sebelum** menimpa kode lama.

> **Cek sebelum upload:** apakah `config/database.local.php` sudah ada di server? Bila belum, situs langsung
> menampilkan 503 dengan kode kejadian berisi "config/database.local.php belum dibuat".

1. **Pindahkan konfigurasi.** Catat nilai `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, dan `BASE_URL` dari
   `config/database.php` yang lama, lalu buat `config/database.local.php` (salin dari `config/database.example.php`)
   berisi nilai itu (`base_url` **wajib**; tanpa itu situs menampilkan 503 "isi base_url di config/database.local.php").
   Setelah itu `config/database.php` boleh ditimpa versi baru. Tanpa file lokal, aplikasi memakai
   bawaan `root`/tanpa password dan halaman akan menampilkan 503. Ganti password database yang pernah ikut ter-commit.
2. **Jalankan migrasi database** (phpMyAdmin → pilih database → Import), berurutan. Semua file aman dijalankan
   ulang (idempoten) dan sama persis dengan tabel yang dibuat otomatis oleh aplikasi:
   - `database/migration_akun_siswa.sql` — tabel `siswa_akun`, `portal_attempts`, kolom `settings.portal_siswa_aktif`.
   - `database/migration_izin.sql` — tabel `izin_pengajuan`, `izin_hari`, `portal_notifikasi`.
   - `database/migration_keamanan.sql` — kolom wajib ganti password (dan kolom keamanan lain, lihat isi file).
   Instalasi baru cukup import `database/database.sql` (sudah berisi semuanya); jangan import ulang file itu ke
   database yang sudah berisi data.
3. **Nginx**: bila server memakai Nginx, ganti aturan lama dengan blok di bagian 6 (A untuk kotak *URL rewrite* aaPanel,
   B untuk vhost tulis sendiri), lalu reload Nginx.
   Hapus `database/*.sql` dari server produksi setelah migrasi.
4. **Password admin bawaan**: setiap akun yang masih memakai password bawaan akan dipaksa menggantinya saat login
   berikutnya (menu lain terkunci sampai password diganti).
5. **GPS & QR Kelas**: upgrade **tidak** mengubah pengaturan GPS maupun QR kelas yang sudah ada. Dashboard menampilkan
   peringatan merah bila GPS nonaktif atau QR Kelas masih permanen; aktifkan GPS (dengan koordinat sekolah) dan matikan
   "QR Kelas permanen" di Pengaturan bila layar kelas sudah siap.
6. **Perangkat siswa**: tidak ada yang perlu dilakukan siswa. Saat HP membuka beranda atau presensi berhasil, perangkat
   yang sudah terdaftar otomatis diikat ke cookie rahasia server (`SIAP_PERANGKAT`), lihat bagian 8. Pantau jumlah yang
   belum termigrasi di Manajemen Perangkat, lalu (opsional) isi *Batas pengenalan perangkat lama* di Pengaturan.
   Siswa yang menghapus cookie browser setelah perangkatnya *aktif* perlu direset perangkatnya oleh admin.
