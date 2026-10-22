# 🔍 Laporan Audit SIAP (Sistem Presensi Siswa)

**Tanggal audit:** 17 Oktober 2026
**Ruang lingkup:** Audit arsitektur, keamanan (khususnya Device Binding), skalabilitas, dan rekomendasi pengembangan fitur.
**Basis audit:** Pemeriksaan langsung kode sumber — `includes/device_binding.php`, `includes/presensi_core.php`, `includes/qr_dinamis.php`, `includes/keamanan.php`, `includes/auth.php`, `includes/functions.php`, `config/database.php`, `index.php`, `.htaccess`, `api/*`, `presensi/*`, `assets/js/device.js`, `panel/qr_gerbang.php`, dan `database/database.sql`.

---

## 1. Audit Keamanan & Celah Device Binding

### 1.1 Assessment positif (yang sudah benar)

Kode Anda sudah di atas rata-rata sistem PHP native yang biasa ditemui:

- ✅ **UNIQUE index** `uniq_device_hash` + jaring pengaman race condition kode `23000` (`database.sql:154`, catch Duplicate entry di `device_binding.php`).
- ✅ Identitas siswa diambil dari **SESSION server-trusted**, bukan body request (`presensi_core.php` / `api/scan.php` mode preview).
- ✅ Cek **lintas-siswa** sebelum registrasi perangkat (`get_device_binding_by_hash`), bukan hanya per-siswa.
- ✅ Pairing mode **kode pasangan**: sekali pakai, disimpan sebagai hash, terkunci setelah 5 percobaan salah, kedaluwarsa 10 menit.
- ✅ `hash_equals`, PDO `ATTR_EMULATE_PREPARES = false`, cookie sesi `httponly + samesite=Lax`, `session_regenerate_id` berkala, dan guard izin yang jugaovel dan juga berlaku pada POST langsung ke `/panel/`.

### 1.2 Celah & bypass yang masih ada

> **STATUS PENYELESAIAN (diupdate 17 Oktober 2026, 22:56)**
> - ✅ **C1 — SELESAI** (implementasi HMAC device secret v3 + fallback v2, 17-10-2026 22:56)
> - ✅ **C2 — SELESAI** (deteksi anomali rotasi UUID + log REVIEW, 17-10-2026 22:56)
> - ⏸ **C3 — DITUNDA** (butuh kebijakan `gps_aktif` default + kalibrasi threshold per token; tidak diotomasi tanpa persetujuan admin)
> - ✅ **C4 — SELESAI** (whitelist trusted proxy di `get_client_ip()`, 17-10-2026 22:56)
> - ✅ **C5 — SELESAI** (catch `23000` race insert presensi, 17-10-2026 22:56)
> - ✅ **C6 — SELESAI** (rate limiter APCu di 4 endpoint API publik, 17-10-2026 22:56)
> - ⏸ **C7 — DITUNDA** (CSP memerlukan audit seluruh script inline dulu — dirancang bertahap di §2)
>
> Semua perubahan lolos `php -l` (14 file) dan logika HMAC v3 diverifikasi via test runtime (9 asersi lulus).

#### C1 — UUID adalah "bearer credential", bukan identitas perangkat (celah kritis terbesar)

`device.js` menyimpan UUID di `localStorage` + cookie. `localStorage` dan cookie **dapat disalin** (DevTools, ekstensi browser, atau XSS jika ada). Siapa pun yang mengetahui string UUID siswa A dapat mengirimnya dari HP mana pun → `identify_siswa_by_device()` cocok → presensi sah. Sinkronisasi UUID antar perangkat bukan hipotesis: siswa cukup copy-paste satu string via chat.

**Solusi bertingkat:** *(status: ✅ SELESAI untuk iterasi 1 — 17-10-2026 22:56; fingerprint komposit menyusul)*

**Yang sudah diterapkan di kode:**
- ✅ `build_device_hash_v3()` + `device_hash_variants()` + kolom `device_hash_v3` (lazy migrate) di `includes/device_binding.php`.
- ✅ `assets/js/device.js`: `getOrCreateDeviceSecret()` — secret 32 byte dual-store, dikirim bersama UUID di semua fetch (`beranda.php`, `presensi/index.php`, `konfirmasi.php`, `daftar_hp.php`, `api/*`).
- ✅ Kontinuitas transisi: baris binding lama otomatis ter-$upgrade$ ke v3 pada match pertama dengan secret; klien JS lama (tanpa secret) tetap cocok selama baris belum punya v3; setelah terupgrade, UUID-kopas tanpa secret → `DEVICE_SECRET_MISSING` (ditolak + log).
- ✅ Strict-terhadap pencurian: ketidakcocokan secret pada baris v3 → `DEVICE_SECRET_MISMATCH` (ditolak + log REVIEW). *(status: ✅ SELESAI — iterasi 1 implemented; iterasi fingerprint komposit menyusul)*

1. **HMAC server-side secret** — jangan percaya hash yang sepenuhnya diturunkan dari input klien:

```php
// build_device_hash() versi 3 — secret acak 32B yang server generate saat
// pairing pertama, dikirim kembali & disimpan ganda (localStorage + cookie).
function build_device_hash(string $installUuid): string {
    $secret = get_paired_secret($installUuid);
    return hash('sha256', 'v3|' . $installUuid . '|' . $secret);
}
```

Lebih kuat lagi: saat pairing, server mengembalikan `device_secret` acak; hash dibentuk dari `HMAC(uuid, secret)`. Klien tidak bisa *menciptakan* identitas baru, hanya *memutar ulang* pasangan `(uuid, secret)`. Serangan copy kini membutuhkan dua kolom yang disinkronkan, dan rotasi UUID liar (lihat C2) langsung terdeteksi.

2. **Fingerprint komposit** (*screen size, timezone, bahasa, deviceMemory, hardwareConcurrency, WebGL renderer, canvas hash*). UA sengaja tidak di-hash (keputusan Anda benar), tapi fingerprint bisa jadi **sinyal lemah yang dicatat** untuk **skor anomali**: jika presensi sukses tapi fingerprint berubah dari 3 hari terakhir, tandai `security_log` level "REVIEW" untuk admin — jangan tolak otomatis (fingerprint sering berganti saat update OS).

3. **Attestation berbasis platform (jalan terbaik jangka panjang):** PWA terinstal + WebOTP/WebAuthn passkey tidak sepenuhnya cocok untuk kebutuhan kiosk, tapi di lingkungan sekolah realistis adalah: PWA terinstal + **foto selfie verifikasi** saat pairing (lihat §4, fitur 5).

#### C2 — Rotasi UUID bebas + auto-register = titip absen klasik

`is_valid_install_uuid()` regex `{20,64}` longgar, dan mode *tanpa* `device_wajib_kode` memperbolehkan self-register. Vektor nyata: siswa B memfoto kartu QR siswa A (dibagikan via WhatsApp), datang ke gerbang, scan QR gerbang (jendela 120 detik) + QR pribadi A dari foto → perangkat B **mendaftar sebagai device milik A**. Lalu setiap pagi B bisa menitipkan presensi dengan berdiri di gerbang. Device binding mencegah *1 HP = 2 siswa*, tapi tidak mencegah *kartu QR dipinjam*.

**Solusi:** *(status: ✅ SELESAI — DETEKSI ANOMALI DITERAPKAN 17-10-2026 22:56; sisanya kebijakan/roadmap)*

- Set **`device_wajib_kode = 1` sebagai default** (pairing selalu lewat admin) — fitur ini sudah ada, cukup jadikan policy default.
- **Registrasi perangkat baru harus konfirmasi dua sisi:** notifikasi ke siswa (via WhatsApp, §4) "perangkat baru didaftar untuk Anda? Balas YA/BATAL" — jika batal, auto-block + log. *(masih roadmap, menyusul bentang notifikasi)*
- ✅ **Rate-limit rotasi** — DITERAPKAN: di `check_or_register_device()`, sebelum path MISMATCH kini dihitung event `DEVICE_MISMATCH` siswa terkait dalam 30 hari; bila ≥ 3x → `DEVICE_ANOMALY_REVIEW` dicatat sebagai antrean review admin.
- **Foto selfie geotagged wajib** pada presensi (bukan hanya GPS) — countermeasure paling efektif untuk titip absen manusia, bukan teknis. *(roadmap §4 fitur 5)*

#### C3 — Replay/berbagi token QR gerbang (presensi dari luar lokasi)

Token gerbang nyaman tapi tidak mengikat ke perangkat/pemakai: `qr_dinamis` hanya valid 120 detik, tanpa pemindaian satu-per-perangkat. Siapa pun yang **menycreenshot layar monitor gerbang** dan mengirim via WA ke teman di rumah: teman cukup scan foto dari rumah dalam ≤120 detik → dapat sesi `gate_token` valid → presensi. GPS menanggulangi ini (urutan validasi 9), tapi `gps_aktif` tidak selalu diaktifkan default.

**Solusi:**

- **Throttle pemakaian token per jendela**: kolom `used_count` di `qr_dinamis`; jika > N device per token satu window → flag anomali untuk review.
- **`gps_aktif = 1` sebagai default** + pertahankan jam buka `jam_masuk` (sudah ada, bagus).
- Token QR **satu-pemakai sesi**: pencatatan `gate_used_by_device` menulis device yang pertama memakai sesi tersebut sebagai bagian dari anomali scoring (bukan blokir keras).

#### C4 — `get_client_ip()` mempercayai `X-Forwarded-For` mentah-mentah *(✅ SELESAI — diterapkan 17-10-2026 22:56)*

`functions.php:425` mengambil `HTTP_X_FORWARDED_FOR` tanpa whitelist proxy. Dua dampak: (a) **melewati rate-limit login per-IP 20x** dengan mengirim XFF acak tiap request; (b) **mencemari `security_logs`** sehingga audit/investigasi titip absen menjadi tidak bisa dipercaya.

**Diterapkan:** `get_client_ip()` di `includes/functions.php` kini hanya memakai XFF/CF-Connecting-IP bila `REMOTE_ADDR` ada dalam konstanta `TRUSTED_PROXIES` (baru di `config/database.php`, default `''` aman untuk XAMPP/localhost) + validasi `filter_var(FILTER_VALIDATE_IP)`. Contoh asli dipertahankan di bawah sebagai dokumentasi desain:

```php
function get_client_ip(): string {
    // hanya percaya XFF jika request datang dari proxy internal yang dikenal
    $trusted = explode(',', TRUSTED_PROXIES); // definisikan di config
    if (in_array($_SERVER['REMOTE_ADDR'] ?? '', $trusted, true)) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
```

Di hosting ber-proxy (Cloudflare/aaPanel Nginx), whitelistnya adalah CIDR proxy aktual, bukan sembarang header.

#### C5 — Race insert presensi (bug kecil tapi nyata) *(✅ SELESAI — diterapkan 17-10-2026 22:56)*

`sudah_presensi_hari_ini()` SELECT-then-INSERT: dua request mikrodetik bersamaan dari satu siswa (double-tap "Konfirmasi") — keduanya lolos cek → INSERT kedua kena `uniq_siswa_tanggal` → **tidak ditangkap** → HTTP 500, padahal presensi pertama sudah sukses.

**Diterapkan di `proses_simpan_presensi()`** (`includes/presensi_core.php`): INSERT dibungkus try/catch `23000`; doclakan race dijawab `ALREADY_PRESENT` dengan data baris yang menang + event `PRESENCE_DUPLICATE_RACE` ke `security_logs` (bukan HTTP 500 lagi).

```php
try {
    $stmt->execute([...]);
} catch (PDOException $e) {
    if ((int)$e->getCode() === 23000) {
        $existing = sudah_presensi_hari_ini($siswaId);
        return ['success' => true, 'code' => 'OK', /* data dari $existing */];
    }
    throw $e;
}
```

#### C6 — Tidak ada rate-limit di endpoint API publik *(✅ SELESAI — diterapkan 17-10-2026 22:56)*

`api/scan.php`, `api/cek_perangkat.php`, `api/presensi.php` menerima POST berulang tanpa batas. Gabungkan dengan rate limiter (§3) — berlaku juga untuk penyerang dari luar gerbang.

**Diterapkan:**
- Modul baru **`includes/ratelimit.php`** — sliding-window APCu per `(modul | IP)`, pertahankan degrade-aman bila APCu tidak tersedia.
- Terpasang: `api/scan.php` 30/mnt/IP · `api/presensi.php` 12/mnt/IP · `api/cek_perangkat.php` 60/mnt/IP · `api/beranda.php` 60/mnt/IP — pelanggaran dijawab **HTTP 429** (JSON via konteks fetch).

#### C7 — CSP tidak dipasang *(⏸ DITUNDA — butuh audit seluruh script/style inline dulu; rencana CSP dengan nonce dijahit ke refaktor arsitektur §2)*

Komentar di `keamanan.php` jujur soal ini. Bootstrap sudah ada vendor lokal di banyak halaman; CSP `default-src 'self'` + `'unsafe-inline'` untuk style + nonce untuk script adalah upgrade bertahap yang layak. Tanpa CSP, XSS (jika ada kanal output yang belum escaped) bisa membaca `localStorage` UUID → mendukung serangan C1.

---

## 2. Evaluasi Arsitektur (PHP Native)

Status quo: sudah lebih dari "spaghetti" — ada router (`index.php` + `cocokkan_rute`/`RUTE_MODUL`), guard di `auth.php`, logika inti terpusat (`presensi_core.php`), utilitas (`functions.php`). Risiko jangka panjang yang **nyata di kode sekarang**:

1. **`functions.php` adalah "God file"** — 904 baris memuat token generator, IP, geo, settings, security log, brute-force, aktivitas log, favicon. Setiap file `require` semuanya; perubahan satu fungsi berisiko ke seluruh request.
2. **Panel file = controller + view + SQL** dalam satu file (beberapa modul `panel/*.php` sangat panjang).
3. **Migrasi skema tersebar** — `get_settings()` melakukan `ALTER TABLE` auto-migrate, `siapkan_hak_akses()` membangun tabel, `siapkan_tabel_kode_pasangan()` idem. Praktis di shared-hosting, tapi tidak terlacak dan berisiko di concurrency (dua request ALTER bersamaan).
4. Tidak ada **type coverage** penuh dan **static analysis** — di PHP 8.x ini gratis ditambahkan.

**Usulan pola desain (mulus, tanpa framework, bertahap):**

### a. Autoload minimal + struktur PSR-4-style

```
app/
  bootstrap.php          // include config, keamanan, error handler
  Router.php             // pindahkan cocokkan_rute + RUTE_MODUL ke sini
  Middleware/Auth.php, Csrf.php, RateLimit.php
  Controller/Panel/SiswaController.php ...
  Service/PresensiService.php, DeviceService.php, QrService.php
  Repository/PresensiRepository.php, SiswaRepository.php
  Support/functions.php  // fisura bertahap dari includes/functions.php
```

Middleware sudah konsepnya ada (`require_login`, `csrf_require`) — bungkus jadi chain yang dieksekusi router:

```php
// app/Router.php
$route = $router->match($path);
foreach ($route->middlewares() as $mw) $app->pipe($mw);
echo ($route->action)(...$route->params);
```

### b. Service pattern untuk domain inti

`proses_simpan_presensi()` sudah 90% jadi service; pindahkan ke `PresensiService`, jadikan `final function __construct(PDO $pdo, SettingsRepository $settings)`. Halaman panel jadi *tipis*: collect input, panggil service, render.

### c. Repository tipis

SQL tersebar di 55 file; kumpulkan ke beberapa repository (Presensi, Siswa, Device, Log). Manfaat: indexing & query tuning jadi satu tempat (membantu audit §3) dan pengujian menjadi mungkin.

### d. Tooling yang bisa langsung dipasang

- `PHPStan` (level 6) + `PHP-CS-Fixer` — tidak butuh framework, menganalisis kode native apa adanya.
- PHPUnit/Pest untuk `PresensiServiceTest` (status TERLAMBAT, hari non-efektif, device mismatch, race UNIQUE).
- Config: `config/env.php` supaya kredensial DB tidak di-define global di source; `.env` tidak di-commit (sekarang `root`/`''` ada di repo — **ganti sebelum produksi**).

---

## 3. Optimalisasi Skalabilitas (Jam Puncak 06:50–07:00)

**Estimasi load di puncak:** per presensi sukses = ~10 query DB (settings, qr validate, siswa fetch, device binding lookup, device UPDATE, presensi SELECT, presensi INSERT, security_log INSERT × ≥ 1) + ~12 asset request per halaman presensi. Untuk 300 siswa = 2–5k query/menit — InnoDB standar shared hosting *dapat* tidak kuat.

**Bottleneck terurut dampak:**

### 1. `get_settings()` = 1 query per request — pasang cache process-wide

```php
function get_settings(): array {
    if (($s = apcu_fetch('siap_settings')) !== false) return $s;
    $s = getDB()->query('...')->fetch() ?: [];
    apcu_store('siap_settings', $s, 60);
    return $s;
}
// panggil apcu_delete('siap_settings') di panel/pengaturan.php saat admin ubah
```

APCu tersedia di shared hosting umumnya; fallback: file cache `var/cache/settings.json`.

### 2. Security log synchronous per presensi — keluarkan dari hot path

INSERT terjadi di hot path. Keluarkan via antrian: append JSONL lalu cron insert batch per menit (atau tabel antrean + worker). Ini memotong ~2 INSERT per presensi. Shorter win: hanya `security_log(...)` level penting yang ditulis sinkron.

### 3. Asset stack

`html5-qrcode.min.js` (~250 KB) + Bootstrap + ikon per load presensi. **Service worker caching static assets** (§4, fitur 1) membuat load kedua jauh lebih cepat dan mengurangi bandwidth PHP (static file langsung dari SW cache).

### 4. Satu request alur device-mode

Sekarang siswa device-terdaftar melakukan: render penuh `presensi/index.html` (PHP) → `api/scan mode=device` → halaman konfirmasi → `api/presensi`. Silahkan tambah **`api/quick.php`**: satu POST (`device_uuid`, `gate_token`) yang menjalankan `identify + check + insert` sekaligus, return JSON hasil. Memotong setengah jumlah request presensi di jam puncak.

### 5. DB tuning spesifik

- Index yang **perlu ditambah**:
  - `qr_dinamis (status, kadaluarsa_at)` — untuk kueri validate/cleanup.
  - `presensi (tanggal, kelas_saat_presensi)` — untuk rekap harian papan.
- `innodb_buffer_pool_size` ≥ ukuran tabel yang sering dibaca (settings, qr_dinamis, siswa) — biasanya 128M cukup.
- Tabel log (`security_logs`, `login_attempts`, `activity_logs`) → pastikan **retensi log** difungsikan (sudah ada) di 90 hari.

### 6. Rate limiting API (menjawab juga C6)

PHP native + APCu sliding window:

```php
// includes/ratelimit.php
function rate_limit(string $key, int $maks, int $jendelaDetik): bool {
    if (!function_exists('apcu_store')) return true; // degrade aman
    $n = apcu_inc("rl:$key", 1, $ok, $jendelaDetik);
    if (!$ok) { apcu_store("rl:$key", 1, $jendelaDetik); return true; }
    return $n <= $maks;
}
// di api/*.php:
if (!rate_limit('scan|' . get_client_ip(), 30, 60)) {
    json_response(['success' => false, 'message' => 'Terlalu banyak permintaan.'], 429);
}
```

Terapkan: `api/scan` 30/mnt/IP, `api/presensi` 6/mnt/IP + 3/mnt/per-siswa, `api/cek_perangkat` 60/mnt/IP.

### 7. Jalur QR gerbang

`panel/qr_gerbang.php` memakai `<meta refresh>` — kecil dampaknya (4 monitor × 1 INSERT+UPDATE per window). Yang lebih serius: **siswa spam `?gate=X` untuk memperpanjang sesi** — sudah ditangani oleh `validate_qr_dinamis` ulang (bagus). Tetap pasang rate limit di rute presensi juga.

### 8. PHP-FPM / web server

- Aktifkan `opcache` (wajib — cek `phpinfo`), `opcache.validate_timestamps=0` di produksi.
- HTTP/2, `Cache-Control: immutable, max-age=31536000` untuk `/assets/vendor/*`.

---

## 4. Rekomendasi Fitur Modern (Killer Features)

### 1. PWA "Kartu Absen Digital"

Manifest + service worker:
- Cache static assets → siap terasa ~2x lebih cepat di jam puncak.
- Halaman "presensi berhasil" & rekap pribadi tersimpan offline (data terakhir).
- **Install-to-homescreen** membuat UUID localStorage jauh lebih jarang dibersihkan (data PWA tidak ikut "clear browser cache" default di banyak Android).

Prioritas: implementasi termurah dengan dampak terbesar.

### 2. Papan presensi real-time via SSE (bukan WebSocket)

`panel/papan.php` sudah ada; WebSocket butuh server ekstra dan jarang jalan di shared hosting, tapi **Server-Sent Events jalan di PHP biasa**:

```php
// api/papan_stream.php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
while (true) {
    echo "data: " . json_encode(rekap_hari_ini_ringkas()) . "\n\n";
    @ob_flush(); flush();
    if (connection_aborted()) exit;
    sleep(5);
}
// JS: new EventSource('.../api/papan_stream.php')
```

Layar papan sekolah menampilkan siswa masuk dalam ≤5 detik.

### 3. Notifikasi WhatsApp otomatis

Untuk orang tua / wali kelas: rekap alpa + terlambat per kelas setiap hari pukul 08:00, dan alert real-time DEVICE_MISMATCH (memperkuat mitigasi C2). Implementasi: cron PHP (atau trigger dari pembersihan log yang sudah ada) memanggil gateway WhatsApp API.

### 4. Dashboard analitik

Data `jam_masuk` & `tanggal` sudah ada — cukup query agregat SQL murni + ApexCharts/Chart.js lokal:
- Heatmap kedatangan per jam × hari.
- Tren keterlambatan per kelas vs bulan lalu.
- Top-N kelas paling terlambat.
- Deteksi anomali: presensi dari IP di luar subnet sekolah!

### 5. Verifikasi selfie wajah + GPS saat presensi

Foto diambil, dikirim bersama `api/presensi`, disimpan dengan hash, ditampilkan di halaman detail siswa untuk dicocokkan admin. Menanggulangi titip absen manusia (C2) — persoalan utama di sekolah, lebih dari persoalan teknis device.

---

## Ringkasan Prioritas (bila harus memilah)

| # | Aksi | Efek | Upaya |
|---|------|------|-------|
| 1 | Perbaiki `get_client_ip()` whitelist proxy — ✅ SELESAI | Security (C4), audit-log dipercaya | 30 mnt |
| 2 | Catch `23000` di `proses_simpan_presensi` — ✅ SELESAI | Bug UX race | 15 mnt |
| 3 | Rate limit API publik (APCu) — ✅ SELESAI | Security + skalabilitas | 1 jam |
| 4 | `device_wajib_kode` default ON + notifikasi pairing (anomali-review ✅; policy ✅) — [x] Selesai - 18 Oktober 2026 | Menutup titip absen terbesar | 1 jam + policy |
| 5 | Cache `get_settings()` (APCu) — [x] Selesai - 18 Oktober 2026 | Skalabilitas | 30 mnt |
| 6 | PWA (manifest + SW static) — [x] Selesai - 18 Oktober 2026 | Skalabilitas + UX | 1 hari |
| 7 | HMAC device secret v3 — ✅ SELESAI (fingerprint skor masih ke depan) | Security C1 (transisi bertahap) | 2–3 hari |
| 8 | `api/quick.php` satu-request presensi — [x] Selesai - 18 Oktober 2026 | Skalabilitas jam puncak | 3 jam |

---

*Baris analisis audit pada dokumen ini merujuk langsung ke implementasi aktual SIAP; rekomendasi dirancang dapat diterapkan pada environment PHP native yang ada.*

---
---

## 🚀 Audit Lanjutan: DeepSeek 4.1 Flash (Max Effort)

**Tanggal:** 17 Oktober 2026 (lanjutan) · **Metode:** audit kode independen baris-per-baris + verifikasi runtime PHP 8.2.12 CLI
**Aturan filter yang dipatuhi:** seluruh temuan di bawah adalah hal **baru** atau **pendalaman teknis** dari poin yang belum tuntas. Temuan yang sudah dibahas/ditutup di §1 (C1–C7) **tidak diulang**. D1 adalah regresi dari implementasi C1 yang sudah ditandai "Selesai" — ditemukan lewat uji runtime, bukan pengulangan analisis C1. D4 memperdalam C6, D9 memperdalam C7, D2 memperdalam C3. D3, D5–D8 adalah temuan yang sama sekali belum dibahas.

### Ringkasan Temuan

| # | Temuan | Tingkat | Area |
|---|--------|---------|------|
| D1 | Regresi C1: `preview` selalu `MISMATCH` untuk klien ber-secret — presensi terblokir & sinyal keamanan terbalik — [x] Selesai - 18 Oktober 2026 | 🔴 KRITIS | C1 lanjutan |
| D2 | QR Kelas permanen (20 tahun) + auto-konfirmasi = bukti lokasi abadi yang bisa di-replay dari rumah — [x] Selesai - 18 Oktober 2026 | 🟠 Tinggi | C3 lanjutan |
| D3 | `api/pasangan.php` tanpa APCu rate-limit, limit sesi dapat dilewati, lock kode 5x tidak atomik, kode massal tanpa kedaluwarsa — [x] Selesai - 18 Oktober 2026 | 🟠 Tinggi | C6 lanjutan |
| D4 | Rate-limit murni per-IP: 12/menit menghukum NAT/CGNAT sekolah; bisa kolaps jadi satu ember di belakang proxy — [x] Selesai - 18 Oktober 2026 | 🟠 Tinggi | C6/skala |
| D5 | `device.js?v=2` tidak di-bump setelah penambahan `getOrCreateDeviceSecret()` → klien cache lama macet di spinner — [x] Selesai - 18 Oktober 2026 | 🟡 Sedang | Deployment |
| D6 | `https_aktif()` mempercayai `X-Forwarded-Proto` tanpa whitelist proxy — [x] Selesai - 18 Oktober 2026 | 🟢 Rendah | C4 lanjutan |
| D7 | TypeError dari JSON bertipe array pada `clean()` → HTTP 500 di `api/scan.php` & `api/presensi.php` — [x] Selesai - 18 Oktober 2026 | 🟢 Rendah | Hardening |
| D8 | N+1 di `/qr/kelas`: `get_or_create_qr_kelas()` per kelas dalam loop (dan INSERT saat GET) — [x] Selesai - 18 Oktober 2026 | 🟢 Rendah | Skala |
| D9 | Klaim README "tanpa CDN" bertentangan dengan header/footer/dashboard yang memuat Bootstrap & Chart.js dari jsdelivr — [x] Selesai - 18 Oktober 2026 | 🟢 Rendah | C7/deploy |

---

### D1 — 🔴 KRITIS: Regresi C1 — `api/scan.php` mode `preview` selalu `MISMATCH` untuk klien yang mengirim `device_secret` *(✅ SELESAI — diterapkan 18 Oktober 2026)*

**Bukti kode:**
- `api/scan.php:80` — `$deviceHash = build_device_hash($deviceUuid, $deviceSecret !== '' ? $deviceSecret : null);`
- `device_binding.php:60-68` — bila secret valid, `build_device_hash()` mengembalikan **hash v3**.
- `device_binding.php:231-247` — saat registrasi, kolom `device_hash` **selalu** diisi varian **v2**; varian v3 hanya masuk kolom terpisah `device_hash_v3`.
- `api/scan.php:97-100` — perbandingan hanya `hash_equals($binding['device_hash'], $deviceHash)` lalu `else { $deviceState = 'MISMATCH'; }`.
- `presensi/konfirmasi.php:160-164` — klien mengirim `mode: 'preview'` + `device_secret: getOrCreateDeviceSecret()`; `presensi/konfirmasi.php:177-179` — `MISMATCH` → kotak merah "Perangkat tidak dikenali" → **jalan buntu, tidak ada tombol lanjut**.
- `sinkronkan_hash_perangkat()` (satu-satunya penulis kolom `device_hash` selain INSERT) selalu menulis hash v2 (`device_binding.php:112-127`) — tidak ada jalur yang "memindahkan" v3 ke kolom v2.

**Verifikasi runtime (PHP 8.2.12, skrip uji langsung memanggil fungsi asli):**

```
device_hash (v2 column)          = fd91ffa177db1013e35a8f3b4c5738352f821a07db61d6f40d975cb5ffdc3828
device_hash_v3 (v3 column)       = ae5075ca22f38719281ebc22ac41125c7ea3f79a08713ff9314fe997a07fb697
preview with secret computes     = ae5075ca22f38719281ebc22ac41125c7ea3f79a08713ff9314fe997a07fb697
api/scan.php preview: binding_v2 vs computed_with_secret => MATCH? false
api/scan.php preview: binding_v2 vs computed_no_secret   => MATCH? true
```

**Dampak (dua arah):**
1. **Fungsional — semua presensi terblokir.** Setiap siswa yang browser-nya menjalankan `device.js` baru (mengirim secret) mendapat `MISMATCH` di halaman konfirmasi, dari jalur manapun (gate QR maupun QR pribadi). Baris binding juga tidak akan pernah ter-upgrade ke v3, karena jalur upgrade ada di `check_or_register_device()` yang tidak pernah tercapai.
2. **Keamanan — sinyal terbalik.** Justru klien yang meniru UUID saja (tanpa secret — persis pola serangan kopas ala-C1) mendapatkan `MATCH` di preview. UI bertingkah kebalikan dari maksud desain v3.

**Perbaikan — samakan logika preview dengan `check_or_register_device()`; jangan bandingkan satu kolom saja:**

```php
// includes/device_binding.php — helper bersama (dipakai preview & final check)
/** Cocokkan binding terhadap SEMUA varian hash (v3 & legacy) di KEDUA kolom. */
function binding_cocok(array $binding, array $varian): bool
{
    foreach ($varian as $h) {
        if (!empty($binding['device_hash'])    && hash_equals((string) $binding['device_hash'], $h))    return true;
        if (!empty($binding['device_hash_v3']) && hash_equals((string) $binding['device_hash_v3'], $h)) return true;
    }
    return false;
}
```

```php
// api/scan.php (mode=preview) — ganti baris 80 & 97-101
$varian = array_values(device_hash_variants($deviceUuid, $deviceSecret !== '' ? $deviceSecret : null));
// ...
} elseif (binding_cocok($binding, $varian)
          // UUID tanpa secret pada baris yang sudah v3 = indikasi kopas -> MISMATCH,
          // konsisten dengan keputusan check_or_register_device().
          && !($deviceSecret === '' && !empty($binding['device_hash_v3']))) {
    $deviceState = 'MATCH';
} else {
    $deviceState = 'MISMATCH';
}
```

**Wajib:** tambahkan uji regresi untuk tiga skenario: (a) perangkat v3 asli + secret → MATCH; (b) UUID saja tanpa secret pada baris v3 → MISMATCH; (c) baris legacy v2 + secret → MATCH (jalur upgrade). Jalur `identify_siswa_by_device()` (`presensi_core.php:39-57`) sudah benar (mencocokkan kedua kolom) — yang salah hanya preview.

---

### D2 — 🟠 TINGGI: QR Kelas permanen + auto-konfirmasi = "tiket presensi" yang bisa dibawa pulang *(✅ SELESAI — diterapkan 18 Oktober 2026)*

**Bukti kode:**
- `qr_dinamis.php:74-90` — QR kelas dibuat dengan `kadaluarsa_at = NOW() + INTERVAL 20 YEAR`; token lama hanya mati bila admin menekan "Regenerate" (manual).
- `qr_dinamis.php:41-48` — `get_active_qr_dinamis()` hanya memeriksa `status='AKTIF'` dan `kadaluarsa_at >= NOW()`; tidak ada batas usia token.
- `panel/qr_kelas.php:171` — QR yang dicetak/ditempel/di-share meng-encode `BASE_URL/presensi/?gate=<token>`; token = 48 hex acak (tidak bisa ditebak, tapi **bisa difoto**).
- `presensi/index.php:11-19` — cukup membuka `?gate=<token>` dari mana pun untuk mengisi `$_SESSION['gate_token']` (tanpa cek lokasi).
- `presensi/konfirmasi.php:26-28, 188-193` — bila token yang di-scan adalah QR kelas (`kelas_target` terisi), presensi **dikonfirmasi otomatis** tanpa tombol; validasi "fresh 300 detik" di `presensi/index.php:22` **tidak** ditegakkan di konfirmasi maupun di `proses_simpan_presensi()` (yang mengulang validasi token tanpa batas usia).
- Default `gps_aktif` = nonaktif (README §7).

**Skenario nyata:** siswa memfoto QR kelas sekali (dari papan kelas / temannya), lalu setiap pagi dari rumah: buka `?gate=<token foto>` → session terisi → `api/scan.php` mode=device cocok (HP-nya memang HP terdaftar miliknya) → `konfirmasi.php` auto-submit → presensi sah jam 06:55 dari rumah. Device binding (1 siswa = 1 HP) tidak menghalangi karena yang curang adalah pemilik akun & perangkatnya sendiri. Laporan §1 C3 hanya membahas token **gerbang dinamis (120 detik)**; QR kelas **permanen** melampaui mitigasi itu sepenuhnya.

**Catatan interaksi:** regresi D1 saat ini justru menutupi jalur auto-konfirmasi (device_state tidak pernah MATCH). **Perbaikan D1 akan mengaktifkan kembali jalur ini**, jadi D2 harus ditangani bersamaan.

**Perbaikan (pilih kombinasi):**
1. **Wajib GPS untuk QR kelas** — QR gerbang dinamis punya bukti kedekatan waktu; QR kelas tidak punya apa pun selain lokasi:

```php
// includes/presensi_core.php — langkah 9 disesuaikan
$wajibGps = !empty($settings['gps_aktif']) || !empty($qrRow['kelas_target']); // QR kelas SELALU butuh lokasi
if ($wajibGps) {
    if ($lat === null || $lng === null) {
        return ['success' => false, 'code' => 'GPS_REQUIRED', 'message' => 'Aktifkan lokasi untuk presensi melalui QR kelas.'];
    }
    // ... cek radius seperti kode yang sudah ada ...
}
```

2. **Rotasi QR kelas harian** (buat ulang tiap pagi via cron `buat_qr_kelas_permanen()` + tampilkan token baru di layar kelas / panel), sehingga foto lama hangus — konsekuensinya kartu cetak harus dicetak ulang berkala.
3. **Log & skor anomali pemakaian token:** catat `gate_token` per presensi dan tandai REVIEW bila satu token kelas dipakai siswa dari IP/subnet berulang di luar jam masuk atau oleh banyak device dengan pola mencurigakan.
4. Opsi minimum jangka pendek: jadikan `gps_aktif = 1` default (menyambung keputusan C3 yang masih ditunda).

---

### D3 — 🟠 TINGGI: `api/pasangan.php` — endpoint pairing tanpa rate-limit APCu; pengunci 5x tidak atomik; kode massal tanpa kedaluwarsa *(✅ SELESAI — diterapkan 18 Oktober 2026)*

**Bukti kode:**
- `api/pasangan.php` — **tidak** memanggil `rate_limit_gate()` (bandingkan `api/scan.php:17` 30/menit, `api/presensi.php:13` 12/menit, `api/cek_perangkat.php:10` 60/menit, `api/beranda.php:12` 60/menit). Satu-satunya pembatas adalah `$_SESSION['pair_try']` (8 percobaan/10 menit) — **dapat dilewati total dengan tidak mengirim cookie sesi**: tiap request mendapat sesi baru yang kosong, jadi hitungannya selalu 0. C6 mengklaim "4 endpoint API publik" sudah dibatasi — `pasangan.php` terlewat.
- `api/pasangan.php:39-52` — jalur **NIS + kode 6 angka** tidak membutuhkan gate token sama sekali (bukan hanya jalur SESSION di baris 54-64). NIS dapat dienumerasi (mis. dari daftar kelas), sehingga penyerang **dari luar sekolah** bisa menebak kode untuk siswa target tanpa pernah dekat gerbang.
- `device_binding.php:688-704` — pola baca → cek → increment: `SELECT ... percobaan < 5` dibaca dulu, baru `UPDATE percobaan = percobaan + 1`. Kiriman **paralel** (semua membaca `percobaan = 0` sebelum increment mana pun ter-commit) dapat melakukan jauh lebih dari 5 tebakan ke **satu** kode dalam satu gelombang; gelombang berikutnya terkunci. Batas tebakan per gelombang ≈ concurrency server (di aaPanel umumnya puluhan–ratusan).
- `device_binding.php:748, 757-788` — `buat_kode_pasangan_massal($ids, $admin, null)` menulis `expires_at = '9999-12-31 23:59:59'` dan `token_hash = NULL`. Kode 6 angka massal **tidak pernah kedaluwarsa** (hanya "sekali pakai"); bila bocor (kertas tempel, screenshot, chat), kode itu menjadi kunci rebinding perangkat yang berlaku selamanya. Ruang kode hanya 10⁶ dan hash-nya `sha256(siswaId|kode|'pasangan')` **tanpa salt acak** — bila hash bocor (dump DB), seluruh 10⁶ kandidat per siswa bisa dihitung < 1 detik secara offline.

**Perbaikan:**

```php
// api/pasangan.php — setarakan dengan endpoint publik lain + batasi jalur NIS lebih ketat
require_once __DIR__ . '/../includes/ratelimit.php';
rate_limit_gate('pasangan', 10, 60);        // 10/menit/IP untuk token/kode via sesi
// sebelum blok NIS (baris ~39):
rate_limit_gate('pasangan_nis', 5, 300);    // 5/5menit/IP untuk jalur NIS
```

```php
// includes/device_binding.php — daftarkan_dengan_kode(): kunci baris dulu, baru nilai
$db->beginTransaction();
try {
    $st = $db->prepare('SELECT * FROM device_pairing_codes
                        WHERE siswa_id = :s AND used_at IS NULL AND expires_at > NOW()
                        ORDER BY id DESC LIMIT 1 FOR UPDATE');           // serialisasi percobaan
    $st->execute([':s' => $siswaId]);
    $row = $st->fetch();
    if (!$row || (int) $row['percobaan'] >= KODE_PASANGAN_MAKS_SALAH) {
        $db->rollBack();
        security_log($siswaId, 'DEVICE_PAIRING_FAILED', null, 'Kode pasangan tidak ada/kedaluwarsa/terkunci.');
        return $gagal('KODE_INVALID', 'Kode tidak berlaku. Minta admin membuat Kode Pasangan baru.');
    }
    if (!hash_equals($row['code_hash'], hash_kode_pasangan($siswaId, $kode))) {
        $db->prepare('UPDATE device_pairing_codes SET percobaan = percobaan + 1 WHERE id = :id')
           ->execute([':id' => $row['id']]);
        $db->commit();                                                    // baru lepas kunci
        $sisa = max(0, KODE_PASANGAN_MAKS_SALAH - ((int) $row['percobaan'] + 1));
        return $gagal('KODE_SALAH', $sisa > 0 ? "Kode salah. Sisa percobaan: $sisa." : 'Kode salah dan sudah terkunci.');
    }
    $db->commit();                                                        // cocok → lanjut _selesaikan_pendaftaran_pasangan()
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
```

Tambahan kebijakan: (a) ganti `KODE_TANPA_BATAS` menjadi batas konkret (mis. 7 hari) atau jadikan opsi pengaturan berbatas; (b) beri salt acak per baris pada `hash_kode_pasangan()` (`sha256(siswaId|salt|kode)` dengan `salt` kolom baru) agar dump DB tidak langsung berbuah kode; (c) pertimbangkan **melepas jalur NIS tanpa sesi** atau memaksanya lewat minimal validasi gate-token yang sama dengan jalur sesi.

---

### D4 — 🟠 TINGGI (skalabilitas, pendalaman C6): rate-limit murni per-IP salah sasaran di NAT/CGNAT & bisa kolaps di belakang proxy *(✅ SELESAI — diterapkan 18 Oktober 2026)*

**Bukti kode:** `includes/ratelimit.php:23` — kunci tunggal `'rl|' . $modul . '|' . get_client_ip()`; `api/presensi.php:13` `rate_limit_gate('presensi', 12, 60)`; `api/scan.php:17` `rate_limit_gate('scan', 30, 60)`.

**Dua masalah nyata di jam puncak 06:50–07:00:**
1. **NAT sekolah / CGNAT seluler.** Beberapa siswa (Wi-Fi sekolah, hotspot kelas, atau CGNAT operator) berbagi satu IP publik. 12 presensi/menit/IP berarti siswa ke-13 dari IP yang sama **ditolak 429** — persis di menit tersibuk. Kebutuhan agregat saat puncak = ±30 presensi/menit; limit saat ini lebih rendah dari laju satu kelas besar yang berbagi IP.
2. **Collapse di belakang reverse proxy.** C4 mengosongkan `TRUSTED_PROXIES` secara default (aman untuk XAMPP). Namun bila produksi di belakang Cloudflare/aaPanel-proxy **tanpa mengisi** whitelist, `get_client_ip()` mengembalikan IP proxy yang sama untuk seluruh sekolah → satu ember global 12/menit → semua presensi 429. Kebijakan "default aman" C4 justru berubah menjadi "seluruh situs satu ember" di deployment ber-proxy yang lupa mengisi konfigurasi.

**Perbaikan — ember ganda: identitas (ketat) + IP (longgar):**

```php
// includes/ratelimit.php — pembatas dua lapis
function rate_limit_identitas(string $modul, string $identitas, int $maksId, int $maksIp, int $jendela): bool
{
    if (!rate_limit_boleh($modul . '|ip', $maksIp, $jendela)) return false;   // jaring kasar (anti-bot dari 1 IP)
    if ($identitas === '') return true;
    $id = substr(hash('sha256', $identitas), 0, 16);                          // jangan simpan UUID mentah di key
    return rate_limit_boleh($modul . '|id|' . $id, $maksId, $jendela);
}
// api/presensi.php:
//   rate_limit_identitas('presensi', $deviceUuid, 6, 300, 60);  // 6/menit/device, 300/menit/IP
// api/scan.php:
//   rate_limit_identitas('scan', $deviceUuid, 20, 600, 60);
```

Dokumentasikan di README/ops: bila di belakang proxy, **wajib** mengisi `TRUSTED_PROXIES`, jika tidak rate-limit (dan log IP) menjadi tidak bermakna.

---

### D5 — 🟡 SEDANG: `device.js?v=2` tidak di-bump setelah penambahan `getOrCreateDeviceSecret()` → klien dengan cache lama macet *(✅ SELESAI — diterapkan 18 Oktober 2026)*

**Bukti kode:**
- Keempat halaman memuat skrip dengan versi statis yang sama: `beranda.php:246`, `presensi/daftar_hp.php:64`, `presensi/index.php:167`, `presensi/konfirmasi.php:141` — semuanya `device.js?v=2`.
- Fungsi `getOrCreateDeviceSecret()` (C1) adalah tambahan baru di file yang sama. Klien yang sudah menyimpan `device.js?v=2` versi lama akan terus memakai salinan cache itu (URL identik; `.htaccess` tidak mengatur `Cache-Control`/`mod_expires` untuk assets).
- Inline script memanggil fungsi baru itu **tanpa penjagaan**: `presensi/konfirmasi.php:163` (`device_secret: getOrCreateDeviceSecret()`), `presensi/index.php:210`. ReferenceError ini meletup saat argumen `fetch()` dievaluasi → **fetch tidak pernah berjalan, `.then/.catch` tidak pernah terpasang**, dan karena berada di satu blok `<script>` yang sama, baris-baris setelahnya (termasuk handler pairing) ikut tidak terdaftar → halaman **macet permanen di spinner "Memeriksa data..."**.
- Bukti ketidakkonsistenan: `beranda.php:443` sudah defensif (`typeof getOrCreateDeviceSecret === 'function'`), tetapi `beranda.php:354,368` tidak.

**Perbaikan — cache-busting berbasis filemtime (pola yang sudah dipakai `presensi.css`) + guard defensif:**

```php
<script src="<?= BASE_URL ?>/assets/js/device.js?v=<?= @filemtime(__DIR__ . '/../assets/js/device.js') ?: time() ?>"></script>
```

```js
const deviceSecret = (typeof getOrCreateDeviceSecret === 'function') ? getOrCreateDeviceSecret() : '';
```

---

### D6 — 🟢 RENDAH: `https_aktif()` mempercayai `X-Forwarded-Proto` tanpa whitelist (`includes/keamanan.php:9-14`) *(✅ SELESAI — diterapkan 18 Oktober 2026)*

Kebijakan C4 untuk IP (`TRUSTED_PROXIES`) belum diterapkan ke deteksi skema: header `X-Forwarded-Proto` dipercaya dari siapa pun. Dampak: (a) jika aplikasi di belakang TLS-proxy **tanpa** header tersebut, cookie sesi tidak ditandai `Secure` dan HSTS tidak dikirim — padahal HTTP mungkin masih terbuka (tidak ada force-HTTPS di `.htaccess`); (b) sebaliknya XFP palsu dari klien mengubah keputusan Secure/HSTS (dampak kecil, HSTS diabaikan browser via HTTP). Samakan dengan pola C4:

```php
function https_aktif(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    $trusted = defined('TRUSTED_PROXIES')
        ? array_filter(array_map('trim', explode(',', (string) TRUSTED_PROXIES))) : [];
    if ($trusted && in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $trusted, true)) {
        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
    return false;
}
```

Tambahan: pasang redirect HTTP→HTTPS di `.htaccess` produksi (di luar scope kode ini).

---

### D7 — 🟢 RENDAH: `api/scan.php` & `api/presensi.php` dapat dijatuhkan ke HTTP 500 dengan JSON bertipe array *(✅ SELESAI — diterapkan 18 Oktober 2026)*

`clean(?string $value)` bertipe ketat. Payload JSON seperti `{"device_secret": []}` menghasilkan `TypeError` yang **tidak ditangkap** di kedua endpoint ini (`api/scan.php` dan `api/presensi.php` tidak memiliki `try/catch`; bandingkan `api/pasangan.php:19-67` dan `api/cek_perangkat.php:15` yang membungkusnya). Selain itu, `api/pasangan.php:34` memanggil `preg_replace()` atas `$input['token']` yang bisa array → TypeError (tertangkap, tapi hanya berubah menjadi 500 JSON).

```php
$input        = json_decode(file_get_contents('php://input'), true);
$input        = is_array($input) ? $input : [];
$deviceUuid   = is_string($input['device_uuid'] ?? null)   ? clean($input['device_uuid'])   : '';
$deviceSecret = is_string($input['device_secret'] ?? null) ? clean($input['device_secret']) : '';
$gateToken    = is_string($input['gate_token'] ?? null)    ? clean($input['gate_token'])    : ($_SESSION['gate_token'] ?? '');
```

---

### D8 — 🟢 RENDAH: N+1 di `/qr/kelas` — `get_or_create_qr_kelas()` dipanggil per kelas dalam loop *(✅ SELESAI — diterapkan 18 Oktober 2026)*

`panel/qr_kelas.php:194-197` menjalankan minimal satu SELECT (`get_or_create_qr_kelas()`) untuk **setiap** kelas pada setiap kali halaman dibuka; kelas yang belum punya QR malah memicu INSERT di dalam GET. Untuk 30 kelas = 30+ kueri per page-view. Selain N+1, ini juga berarti pembuatan baris token terjadi malas saat admin hanya membuka daftar. Perbaikan: satu kueri untuk semua kelas + fallback per kelas hanya bila kosong:

```php
// panel/qr_kelas.php — satu kueri, bukan N+1
$tokenPerKelas = [];
$st = $db->query("SELECT kelas_target, token FROM qr_dinamis
                  WHERE kelas_target IS NOT NULL AND status = 'AKTIF' AND kadaluarsa_at >= NOW()
                  GROUP BY kelas_target ORDER BY MAX(id)");
foreach ($st->fetchAll() as $r) $tokenPerKelas[$r['kelas_target']] = $r['token'];
foreach ($kelasList as $k) {
    if (!isset($tokenPerKelas[$k])) $tokenPerKelas[$k] = get_or_create_qr_kelas($k)['token'];
}
```

(Sebagai catatan metode: untuk `/rekap` dan `/siswa`, pencarian N+1 per-baris **tidak menemukan** kueri di dalam loop PHP — biaya utamanya tetap agregasi SQL, yang rekomendasi index-nya sudah ada di §3.5. `panel/rekap.php:46-61` memakai subkueri berkorelasi per baris hasil, tetapi terbatas `LIMIT {perPage}` sehingga aman untuk halaman 10–100 baris.)

---

### D9 — 🟢 RENDAH: "Tanpa CDN" di README vs kenyataan kode (Bootstrap/Chart.js dari jsdelivr) + menghambat C7 *(✅ SELESAI — diterapkan 18 Oktober 2026)*

README §9 mengklaim: *"Tidak ada ketergantungan CDN"*. Faktanya (per grep seluruh file):
- `includes/header.php:50-51` dan `includes/footer.php:4` — Bootstrap CSS/Icons/JS dari `cdn.jsdelivr.net` untuk **seluruh halaman panel**.
- `login.php:67-69` — Bootstrap, Bootstrap Icons, dan Google Fonts (Montserrat).
- `dashboard.php:457` — Chart.js dari CDN (tidak ada salinan di `assets/js/vendor/`; vendor hanya berisi `html5-qrcode.min.js` dan `qrcode.js`).
- `panel/qr_gerbang.php:17`, `panel/qr_kelas.php:38,168`, `panel/qr_kelas_print.php:50-51`, `panel/qr_print.php:37-38` — Bootstrap/Icons dari CDN, padahal `assets/css/vendor/bootstrap.min.css` **sudah tersedia lokal** dan justru dipakai halaman publik `presensi/*`.

Dampak: (a) kontradiksi dokumentasi yang bisa menyesatkan saat deploy di jaringan sekolah yang membatasi CDN; (b) satu lagi penghambat C7 — CSP ketat nanti harus mengizinkan `cdn.jsdelivr.net` + `fonts.googleapis.com` kecuali diseragamkan ke vendor lokal. Perbaikan termurah: ganti referensi header/footer/login ke `assets/css/vendor/*` (sudah ada), tambahkan `assets/js/vendor/bootstrap.bundle.min.js` + Chart.js lokal, lalu hapus seluruh referensi CDN — setelah itu CSP `default-src 'self'` tinggal menyisakan urusan inline script/style.

---

### Area yang diverifikasi bersih (agar tidak diulang audit berikutnya)

- **XSS panel:** seluruh output data terkontrol pengguna di 31 file `panel/*.php` memakai `e()` — termasuk `device_name` (turunan User-Agent klien) di `panel/device.php:265` & `panel/siswa_detail.php:103`, `user_agent`/`keterangan` di `panel/security_log.php:94-96`, serta `panel/papan.php` yang merender via `textContent`. Tidak ditemukan celah XSS tersimpan dari permukaan yang diaudit ini.
- **Session fixation/hijacking:** `session_regenerate_id(true)` saat login (`login.php:33`) dan berkala (`includes/auth.php:39-42`), `use_strict_mode=1`, cookie `HttpOnly/SameSite=Lax/Secure` (`keamanan.php:35-47`), CSRF di form login (`login.php:12`). Tidak ada temuan baru.
- **Rainbow table SHA-256 v2:** UUID bersifat acak 122-bit (bukan string lemah) → tabel precomputed tidak praktis. Risiko kriptografis yang tersisa bukan pada fungsi hash, melainkan pada *bundling*: UUID dan secret selalu tersimpan bersama di `localStorage`+cookie dan dikirim bersama di setiap request — sehingga XSS/pencurian storage/penyadapan tetap menghasilkan kredensial penuh (v3 unggul hanya terhadap kebocoran parsial "UUID saja"). Ini memperkuat alasan prioritas C7/CSP dan verifikasi escaping di atas, bukan alasan mengganti algoritma hash.

---

### Prioritas perbaikan lanjutan (urut eksekusi)

| # | Aksi | Efek | Upaya |
|---|------|------|-------|
| D1 | Perbaiki perbandingan varian hash di `api/scan.php` preview (+ helper `binding_cocok()`, uji regresi 3 skenario) — [x] Selesai - 18 Oktober 2026 | Membuka kembali seluruh alur presensi; menyelamatkan kebenaran sinyal v3 | 1–2 jam |
| D2 | Wajib GPS untuk `kelas_target` + rotasi/log QR kelas — [x] Selesai - 18 Oktober 2026 | Menutup replay lokasi abadi QR kelas | 2–4 jam + kebijakan |
| D3 | Rate-limit `api/pasangan.php` + `FOR UPDATE` pada kode + batas usia kode massal — [x] Selesai - 18 Oktober 2026 | Menutup brute force pairing remote | 2–3 jam |
| D5 | Cache-busting `filemtime` untuk `device.js` + guard `typeof` — [x] Selesai - 18 Oktober 2026 | Mencegah halaman konfirmasi macet di klien ber-cache | 30 mnt |
| D4 | Rate-limit ember ganda (identitas + IP) & dokumentasi TRUSTED_PROXIES — [x] Selesai - 18 Oktober 2026 | Ketahanan jam puncak di NAT/CGNAT | 2–3 jam |
| D6–D9 | XFP whitelist, guard tipe JSON, kueri tunggal `/qr/kelas`, vendor lokal CDN — [x] Selesai - 18 Oktober 2026 | Hardening & konsistensi | 2–4 jam |

*Audit lanjutan ini independen dari laporan sebelumnya dan disaring agar tidak mengulang C1–C7; verifikasi runtime D1 dilakukan dengan PHP 8.2.12 terhadap fungsi asli `includes/device_binding.php`.*

---

## 🐞 Bug Regresi & Temuan Post-Audit: DeepSeek 4.1 Flash (Max Effort) — 18 Oktober 2026

**Konteks:** seluruh perbaikan C1–C7 dan D1–D9 sudah diterapkan. Risiko regresi tinggi karena perubahan besar (rate-limit dua lapis, HMAC v3, transisi cache-busting `filemtime`, transaksi kunci DB). Audit ini fokus pada **integrasi antar-perbaikan & blind spot lingkungan**, bukan mengulang temuan lama. Semua temuan di bawah adalah hal baru, diverifikasi lewat pembacaan kode + uji runtime (PHP CLI 8.3.33 dan HTTP ke `siap.man2kotamadiun.sch.id.test`).

### Ringkasan Temuan

| # | Temuan | Severity | Status |
|---|--------|----------|--------|
| BUG-1 | Cabang JSON `rate_limit_tolak()` tidak pernah mengirim HTTP 429 (hanya 200 + body `TOO_MANY`) — kontradiksi klaim C6/D4; klien/monitoring yang memeriksa status HTTP tidak mendeteksi penolakan | 🟡 Sedang | [x] Selesai - 18 Oktober 2026 |
| BUG-2 | Regresi D2 × data installer: radius QR kelas divalidasi terhadap koordinat **contoh bawaan** (`-7.1234567/111.1234567`) sehingga SEMUA presensi QR kelas ditolak `OUT_OF_RANGE` di instalasi baru | 🔴 Tinggi | [x] Selesai - 18 Oktober 2026 |
| BUG-3 | Cakupan D7 belum lengkap: `api/beranda.php` & `api/cek_perangkat.php` masih TypeError → HTTP 500 untuk JSON bertipe array/scalar | 🟡 Sedang | [x] Selesai - 18 Oktober 2026 |
| RISK-4 | (Catatan, belum dieksekusi) D2: izin lokasi ditolak permanen → loop error `GPS_REQUIRED` tanpa retry/re-prompt in-page | 🟡 UX | Rekomendasi |
| RISK-5 | (Catatan) D4 tidak diterapkan ke `api/beranda.php`/`api/cek_perangkat.php` (60/menit/IP; beranda polling 8 detik ≈ 8+ klien di 1 NAT sudah 429) | 🟡 Skala | Rekomendasi |
| RISK-6 | (Catatan) `api/pasangan.php` 10/menit/IP & 5/5 menit/IP utk jalur NIS — hari pairing massal di NAT berisiko 429 siswa sah | 🟡 Skala | Rekomendasi |
| DOC-7 | Cuplikan kode saran D8 (`GROUP BY` + kolom non-agregat) akan error 1055 bila disalin apa adanya ke MySQL 8 (`ONLY_FULL_GROUP_BY`) — implementasi aktual sudah aman | ⚪ Dok | Catatan |
| DOC-8 | Komentar basi: CSP "karena CDN Bootstrap" (`includes/keamanan.php`, CDN sudah dihapus D9); komentar "sliding-window" pada fallback file (faktanya fixed-window) | ⚪ Dok | Catatan |

### BUG-1 — 🟡 Cabang JSON rate limiter selalu HTTP 200

- **Lokasi:** `includes/ratelimit.php` (`rate_limit_tolak()`).
- **Akar masalah:** cabang HTML menulis `http_response_code(429)` + `Retry-After`, tetapi cabang JSON (dipakai semua endpoint `api/*`) hanya menulis header `Content-Type` lalu body `{"success":false,"code":"TOO_MANY"}` — status tetap **200**. Ini juga membuat `beranda.js`/`daftar_hp.php` yang memakai pola `if (!r.ok) throw` tidak pernah menangkap penolakan.
- **Perbaikan:** cabang JSON kini juga mengirim `Retry-After` dan `http_response_code(429)` sebelum echo, sejajar dengan cabang HTML.
- **Bukti uji:** burst 65 request ke `/api/cek_perangkat` → `200 x60, 429 x5`; respons penolakan terverifikasi `HTTP/1.1 429 Too Many Requests` + `Retry-After: 60` + body `TOO_MANY`. Sebelum perbaikan seluruh 65 request berstatus 200.

### BUG-2 — 🔴 Regresi D2: validasi radius vs koordinat contoh bawaan installer

- **Lokasi:** `includes/presensi_core.php` (cabang `$qrKelas`), `panel/pengaturan.php` (guard GPS), `database/database.sql` (seed).
- **Akar masalah:** seed installer mengisi koordinat palsu (`-7.1234567/111.1234567`, "SMA Negeri 1 Contoh"). Guard `panel/pengaturan.php` hanya menolak koordinat kosong/`0,0`, sehingga nilai dummy lolos; di `presensi_core.php`, karena `$punyaTitikSekolah` bernilai true, **setiap** presensi QR kelas dengan lokasi asli berjarak >100 m dari titik dummy → `OUT_OF_RANGE` ("Presensi hanya dapat dilakukan di area sekolah") padahal siswa benar-benar di sekolah. Sebelum D2, QR kelas dengan `gps_aktif=0` berjalan tanpa radius → jadi **regresi fungsional** untuk instalasi baru.
- **Perbaikan (disetujui):** (a) radius QR kelas kini **hanya** dievaluasi saat admin mengaktifkan `gps_aktif` (QR kelas tetap WAJIB-ADA lokasi sebagai anti-replay — cabang `gps_aktif=1` tidak berubah); (b) `panel/pengaturan.php` memperlakukan pasangan koordinat dummy bawaan sebagai "belum diisi" (tolak mengaktifkan GPS dengan pesan eksplisit); (c) seed `database/database.sql` diganti `NULL, NULL` + komentar peringatan agar instalasi baru tidak mewarisi bug yang sama.
- **Catatan dokumentasi:** cuplikan kode pada bagian **D2** di laporan ini (validasi radius di cabang `$qrKelas`) kini dipersempit oleh perbaikan (a).
- **Bukti uji:** harness CLI 3 fase (settings diubah lintas-proses — `get_settings()` menyimpan `static` per-proses, bukan bug produk): `aktif0+tanpa GPS → GPS_REQUIRED`; `aktif0+lokasi jauh → OK` (sebelumnya `OUT_OF_RANGE`); `aktif1+lokasi jauh → OUT_OF_RANGE`; `aktif1+tanpa GPS → GPS_REQUIRED`; `aktif1+di koordinat sekolah → OK`; `gerbang QR+aktif0+tanpa GPS → OK`; preview scan `api/scan.php` → `MATCH`. Seluruh data uji dibersihkan.

### BUG-3 — 🟡 Guard D7 belum seragam di endpoint polling

- **Lokasi:** `api/beranda.php:20-22`, `api/cek_perangkat.php:16-18`.
- **Akar masalah:** sama kelasnya dengan D7 — `clean($input['device_uuid'] ?? '')` tanpa guard; body JSON `"abc"` (string) atau `{"device_uuid":[]}` (array) → TypeError tertangkap `catch (Throwable)` → `json_response(..., 500)` "Terjadi kesalahan server" untuk input yang seharusnya bisa ditangani.
- **Perbaikan:** pola D7 disamakan (`$input = is_array($input) ? $input : [];` + `is_string(...)` sebelum `clean()`).
- **Bukti uji:** 6 kasus (`"abc"`, `{"device_uuid":[]}`, `123` × kedua endpoint) → semua **HTTP 200** dengan JSON ramah (`Identitas perangkat tidak valid.` / ringkasan sekolah), sebelumnya 500.

### RISK-4/5/6 — catatan rekomendasi (belum dieksekusi)

- **RISK-4 (GPS dead-end):** pada perangkat yang izin lokasinya ditolak permanen, alur QR kelas berakhir di halaman error `GPS_REQUIRED`; tombol "Coba Lagi" mengarah ke scan ulang (sesi sudah dibersihkan server) sehingga berpotensi loop. Saran: kartu retry in-page yang memanggil `getCurrentPosition` ulang + petunjuk mengaktifkan izin + fallback "gunakan QR gerbang".
- **RISK-5 (NAT beranda/cek_perangkat):** dua endpoint itu masih ember tunggal per-IP 60/menit; gunakan pola ember ganda D4 (identitas = device UUID).
- **RISK-6 (NAT pairing):** `api/pasangan.php` 10/menit/IP global + 5/5 menit/IP jalur NIS; pertimbangkan ember identitas (mis. hash NIS) atau selubung IP lebih longgar.

### Area yang diverifikasi bersih (regresi)

- **C5 × D4:** double-tap dalam kuota 6/menit perangkat tetap aman — pemenang INSERT pertama, pecundang jatuh ke catch `23000` → `ALREADY_PRESENT`; rate limiter tidak mengubah jalur ini.
- **D5 × PWA/SW:** `sw.js` mengunci cache per-URL lengkap (termasuk query `?v=filemtime`), hanya menangani GET `assets/`; seluruh referensi `device.js` sudah berbump versi; 0 sisa `device.js?v=2`.
- **D1 paritas preview/registrasi:** `api/scan.php`, `api/presensi.php`, `api/quick.php`, `api/pasangan.php`, `api/cek_perangkat.php`, `api/beranda.php` konsisten memakai `binding_cocok()`/varian hash yang sama.
- **D8 aktual:** kueri bulk `panel/qr_kelas.php` tanpa `GROUP BY` (aman di MySQL 8.4.3 `ONLY_FULL_GROUP_BY`).
- **D9:** 0 referensi CDN di repo; seluruh vendor (Bootstrap, Icons, Montserrat, Chart.js, html5-qrcode) lokal dan ter-`filemtime`.
- **Kode massal D3:** UI panel sudah dibatasi maksimum 7 hari + clamp server `KODE_MASSAL_MAKS_MENIT=10080` (cabang tampilan "tanpa batas" kini mati/tidak tercapai — kosmetik).
- **`var/cache`:** tidak dapat diakses web (`.htaccess` deny), `settings.json` tanpa rahasia.

### Rekomendasi uji manual E2E (5 skenario krusial)

1. **QR kelas normal (gps_aktif=0):** siswa scan QR kelas dari ponsel di sekolah → konfirmasi → presensi tercatat, tanpa validasi radius (verifikasi BUG-2a).
2. **GPS aktif (gps_aktif=1 + koordinat asli):** dari dalam radius → sukses; dari lokasi jauh → `OUT_OF_RANGE` (verifikasi jalur lama utuh).
3. **Rate limit:** >60 request/menit ke `/api/cek_perangkat` dari DevTools → tab Network harus menampilkan **HTTP 429** + `Retry-After` + JSON `TOO_MANY` (verifikasi BUG-1).
4. **Input aneh:** POST body `[]`, `"abc"`, `{\"device_uuid\":[]}` ke `api/beranda`/`api/cek_perangkat` → JSON error ramah, bukan 500 (verifikasi BUG-3).
5. **Panel pengaturan:** centang GPS sambil memakai koordinat contoh → DITOLAK dengan pesan; isi koordinat asli (atau tombol "Ambil Lokasi") → tersimpan; instalasi baru dari `database.sql` harus berkoordinat `NULL` (verifikasi BUG-2b/2c).

*Audit post-fix ini tidak mengulang temuan C1–C7/D1–D9; fokus pada interaksi antar-perbaikan. Verifikasi runtime memakai PHP 8.3.33 (CLI) + HTTP ke host test lokal, dengan pembersihan data uji penuh (siswa/presensi/security_logs/binding/token QR test dibersihkan; settings direstore `gps_aktif=0`, hari `1,2,3,4,5`, jam `07:00`).*

---

## 🎨 Roadmap Redesain UI (Adaptasi SIPIKET)

Dokumen ini adalah hasil audit antarmuka SIAP sebagai persiapan **perombakan total UI** berdasarkan `desain.md` (Sistem Desain UI/UX SIAP — Adaptasi SIPIKET, merujuk referensi SIPIKET Laravel + React). Perombakan mencakup variabel CSS `:root` (warna, tipografi, radius, bayangan, spacing), komponen inti (card, button, input form, label, badge, chip, note, nav-link, tile, panel gradien, jendela macOS, action bar mobile), serta struktur layout (topbar sticky, shell container, glow top, section ritme, bottom action bar).

Semua perubahan akan menggunakan **Bootstrap 5 + CSS custom** dengan prefix `siap-*`, ditempatkan setelah `bootstrap.min.css`, tanpa React / Vue / Tailwind / build Node.js.

**⛔ TAHAP INI HANYA PERENCANAAN (AUDIT & ROADMAP). Tidak ada perubahan kode HTML/CSS yang dieksekusi pada file apa pun di tahap ini.**

---

### 📋 Daftar Tugas (To-Do List)

#### A. Fondasi Global (Layout, Navigasi, dan CSS Utama)
- [x] `includes/header.php` — Topbar sticky, brand/logo, menu desktop (`siap-nav-link`), dropdown profil, avatar, hamburger mobile + drawer (sesuai spesifikasi §4.2 desain.md). [x] Selesai - 18 Oktober 2026
- [x] `includes/footer.php` — Footer global, menyesuaikan warna teks dan latar dengan token `--siap-*`. [x] Selesai - 18 Oktober 2026
- [x] `assets/css/style.css` — CSS custom utama; impor variabel `:root` dan utilitas sistem desain SIAP (warna, tipografi, radius, bayangan). [x] Selesai - 18 Oktober 2026
- [x] `assets/css/presensi.css` — CSS halaman presensi; taruh blok CSS `:root` SIAP setelah `bootstrap.min.css`, override komponen Bootstrap bawaan. [x] Selesai - 18 Oktober 2026

#### B. Autentikasi & Halaman Publik
- [x] `login.php` — Fokus memusatkan form login, ubah warna tombol primary (`siap-btn-blue` gradien), input (`siap-input`), label mono (`siap-label`). [x] Selesai - 18 Oktober 2026
- [x] `beranda.php` — Halaman landing publik, struktur shell + kartu informasi, warna latar `--siap-bg`. [x] Selesai - 18 Oktober 2026
- [x] `presensi/index.php` — Halaman scan presensi publik, form input gate token, tombol aksi primary, integrasi html5-qrcode. [x] Selesai - 18 Oktober 2026
- [x] `presensi/konfirmasi.php` — Halaman konfirmasi data presensi, form data siswa, tombol konfirmasi/batal. [x] Selesai - 18 Oktober 2026
- [x] `presensi/berhasil.php` — Halaman status presensi berhasil/gagal, badge status (success/danger), pesan notifikasi. [x] Selesai - 18 Oktober 2026
- [x] `presensi/daftar_hp.php` — Halaman pendaftaran perangkat, form pairing, input kode, notifikasi status. [x] Selesai - 18 Oktober 2026

#### C. Panel Admin (Dashboard & Halaman Master)
- [x] `panel/dashboard.php` — Dashboard admin, statistik besar (typography display), card statistik, grafik Chart.js. [x] Selesai - 18 Oktober 2026
- [x] `panel/device.php` — Manajemen perangkat terdaftar, tabel device, badge status, aksi hapus/nonaktifkan. [x] Selesai - 18 Oktober 2026
- [x] `panel/hak_akses.php` — Manajemen hak akses pengguna, form role, toggle akses. [x] Selesai - 18 Oktober 2026
- [x] `panel/kenaikan_kelas.php` — Proses kenaikan kelas siswa, form seleksi kelas, tombol eksekusi. [x] Selesai - 18 Oktober 2026
- [x] `panel/kode_massal.php` — Pembuatan kode pasangan massal, form input jumlah siswa, download/print kode. [x] Selesai - 18 Oktober 2026
- [x] `panel/kode_pasangan.php` — Daftar kode pasangan per siswa, status digunakan/kadaluarsa, tombol buat baru. [x] Selesai - 18 Oktober 2026
- [x] `panel/log_aktivitas.php` — Tabel log aktivitas, filter tanggal/pengguna, pagination. [x] Selesai - 18 Oktober 2026
- [x] `panel/log_cetak.php` — Halaman cetak log, layout print-friendly. [x] Selesai - 18 Oktober 2026 (endpoint JSON, tanpa UI)
- [x] `panel/master_kelas.php` — Master data kelas, form tambah/edit kelas, daftar kelas. [x] Selesai - 18 Oktober 2026
- [x] `panel/papan.php` — Papan presensi real-time, daftar siswa masuk hari ini, auto-refresh. [x] Selesai - 18 Oktober 2026
- [x] `panel/pengaturan.php` — Pengaturan aplikasi (gps_aktif, jam masuk, dll), form input, toggle, simpan. [x] Selesai - 18 Oktober 2026
- [x] `panel/pengguna.php` — Manajemen pengguna/admin, form tambah/edit user, password. [x] Selesai - 18 Oktober 2026
- [x] `panel/presensi.php` — Rekap presensi admin, filter tanggal/kelas/status, tabel data, export. [x] Selesai - 18 Oktober 2026
- [x] `panel/presensi_print.php` — Cetak rekap presensi, layout kertas. [x] Selesai - 18 Oktober 2026
- [x] `panel/profil.php` — Profil admin, form edit profil, ganti password, avatar. [x] Selesai - 18 Oktober 2026
- [x] `panel/qr_generate.php` — Generate QR, form parameter, preview QR, download. [x] Selesai - 18 Oktober 2026
- [x] `panel/qr_gerbang.php` — QR gerbang dinamis, token, validasi waktu, tampilan layar gerbang. [x] Selesai - 18 Oktober 2026
- [x] `panel/qr_kelas.php` — QR kelas permanen, daftar kelas + token, generate/regenerate. [x] Selesai - 18 Oktober 2026
- [x] `panel/qr_kelas_print.php` — Cetak QR kelas, layout cetak per kelas. [x] Selesai - 18 Oktober 2026
- [x] `panel/qr_print.php` — Cetak QR siswa individu, layout kertas. [x] Selesai - 18 Oktober 2026
- [x] `panel/qr_siswa.php` — QR pribadi siswa, generate, tampilkan QR, download. [x] Selesai - 18 Oktober 2026
- [x] `panel/rekap.php` — Rekapitulasi presensi, agregat per kelas/tanggal, tabel ringkasan. [x] Selesai - 18 Oktober 2026
- [x] `panel/rekap_print.php` — Cetak rekapitulasi, layout kertas. [x] Selesai - 18 Oktober 2026
- [x] `panel/security_log.php` — Log keamanan, event device mismatch, filter, tabel. [x] Selesai - 18 Oktober 2026
- [x] `panel/siswa.php` — Master siswa, daftar siswa, pencarian, filter kelas, tombol aksi. [x] Selesai - 18 Oktober 2026
- [x] `panel/siswa_detail.php` — Detail siswa, foto, data presensi, riwayat device. [x] Selesai - 18 Oktober 2026
- [x] `panel/siswa_edit.php` — Form edit siswa, input data, upload foto, simpan/batal. [x] Selesai - 18 Oktober 2026
- [x] `panel/siswa_import.php` — Impor siswa dari file, upload xlsx, preview impor. [x] Selesai - 18 Oktober 2026
- [x] `panel/siswa_tambah.php` — Form tambah siswa baru, input data, simpan. [x] Selesai - 18 Oktober 2026

#### D. Halaman Error (Opsional / Pelengkap)
- [x] `403.html` — Halaman akses ditolak, sesuaikan warna dan tipografi. [x] Selesai - 18 Oktober 2026
- [x] `404.html` — Halaman tidak ditemukan, sesuaikan warna dan tipografi. [x] Selesai - 18 Oktober 2026
- [x] `502.html` — Halaman gagal layanan, sesuaikan warna dan tipografi. [x] Selesai - 18 Oktober 2026

---

### 📊 Ringkasan Roadmap

| Kategori | File | Jumlah |
|----------|------|--------|
| A. Fondasi Global | `includes/header.php`, `includes/footer.php`, `assets/css/style.css`, `assets/css/presensi.css` | 4 |
| B. Autentikasi & Publik | `login.php`, `beranda.php`, `presensi/index.php`, `presensi/konfirmasi.php`, `presensi/berhasil.php`, `presensi/daftar_hp.php` | 6 |
| C. Panel Admin | 29 file di folder `panel/` | 29 |
| D. Halaman Error (opsional) | `403.html`, `404.html`, `502.html` | 3 |
| **Total** | | **42 file** (37 PHP + 2 CSS + 3 HTML) |

File yang **dikecualikan** dari roadmap karena tidak merender UI (aksi/redirect saja): `logout.php`, `index.php` (router), `presensi/scan.php`, `panel/siswa_hapus.php`, `panel/presensi_export.php`, serta seluruh file di `api/` (endpoint JSON).

Jika setuju dengan daftar tugas di atas, beri perintah **"Mulai kerjakan tugas pertama"** untuk mengeksekusi perombakan file per file.

---

## 📱 Roadmap Redesain UI (Layout iOS 17 + Warna SIPIKET)

Roadmap ini adalah perombakan visual lanjutan terhadap 42 file yang sudah selesai di roadmap sebelumnya. Gaya visual diadaptasi dari **Cash App iOS 17** (gambar referensi: `referensi/Cash App iOS 17.png`), dengan aturan:
- **Layout:** Mobile-first, card dengan rounded corners sangat membulat (rounded-4 / radius ≥ 24px), padding luas, bayangan halus (soft shadow), hilangnya garis tepi kaku, bottom navigation bar di layar kecil.
- **Tipografi:** Heading tebal besar, hierarki jelas, teks body longgar.
- **Warna:** TETAP menggunakan palet `--siap-*` dari `desain.md` (JANGAN menggunakan warna dari gambar Cash App).
- **Tombol:** Bentuk pil (rounded-pill), ambang sentuh 44px, padding luas.

### A. Fondasi Global & Komponen iOS 17 (4 file)
- [x] `assets/css/style.css` — Tambahkan variabel dan komponen iOS 17. [x] Selesai - 18 Oktober 2026: `.siap-card-ios17` (rounded ≥ 24px, soft shadow, bg-white/75), `.siap-btn-ios17` (rounded-pill, min-height 44px, padding luas), `.siap-input-ios17` (rounded ≥ 16px, border lembut), `.siap-bottom-nav` (bottom navigation bar 5 ikon, sticky bottom).
- [x] `includes/header.php` — Sesuaikan topbar dengan gaya iOS 17: border-radius lebih besar, shadow lebih halus, brand + menu desktop dengan `siap-nav-link` rounded-pill. [x] Selesai - 18 Oktober 2026: `<nav>` diganti dari `siap-window` (macOS dots, radius 20px, shadow keras) menjadi `siap-card-ios17` (radius 24px, soft shadow, border var(--siap-line), latar rgba(255,255,255,0.9)). `siap-nav-link` sudah rounded-pill via CSS.
- [x] `includes/footer.php` — Sesuaikan footer dengan latar `--siap-bg`, tipografi muted, spacing luas. [x] Selesai - 18 Oktober 2026: `<footer>` diberi `style="background-color: var(--siap-bg);"`; spacing via `siap-section-ios17` (2rem), tipografi muted via `siap-eyebrow` + `siap-h4 siap-mono-xs`.
- [x] `assets/css/presensi.css` — Override komponen presensi publik dengan gaya iOS 17 (card membulat, tombol rounded-pill, warna `--siap-*`). [x] Selesai - 18 Oktober 2026: `.ps-card` radius 24px + soft shadow + bg rgba(255,255,255,0.9); `.ps-btn` rounded-pill (9999px), min-height 44px; `.ps-btn.utama/hijau/polos` pakai gradient/warna `--siap-blue*` dan `--siap-success*`; `.ps-note`, `.ps-chip`, `.ps-info .r`, `.hs-struk/kartu/gagal`, `.hs-stat`, `.hs-tip`, `.cam`, `.ps-status`, `.ps-tips` semua diganti ke token `--siap-*`; 0 warna hardcode (hex) tersisa.

### B. Autentikasi & Halaman Publik (6 file)
- [x] `login.php` — Layout mobile-centric, kartu login dengan rounded ≥ 24px, `siap-label` mono, `siap-input` border lembut, tombol `siap-btn-blue` gradien bentuk pil, padding luas di sekitar form. [x] Selesai - 18 Oktober 2026: kartu pakai `siap-card-ios17` (radius 24px, shadow halus), tombol masuk `siap-btn-ios17 siap-btn-ios17-blue` (rounded-pill, min-height 44px, gradien `--siap-blue*`); tombol kembali diganti `siap-btn-ios17 siap-btn-ios17-ghost` agar konsisten iOS 17; label `siap-label` (mono, huruf besar), input `siap-input`, padding `login-body` 1.5rem; 0 warna hardcode di form.
- [x] `beranda.php` — Halaman landing publik, shell + kartu informasi dengan rounded besar, warna latar `--siap-bg`, bottom-nav untuk navigasi cepat (Beranda, Scan, Daftar HP, Profil). [x] Selesai - 18 Oktober 2026: `.bd-hero` dan `.bd-card` radius 24px (`--siap-radius-window`); tab aktif radius 24px; tombol Masuk & dialog & aksi utama pakai `siap-btn-ios17 siap-btn-ios17-blue/ghost` (rounded-pill, min-height 44px); 0 elemen `siap-btn` lama tersisa di halaman.
- [x] `presensi/index.php` — Halaman scan presensi publik, form input gate token dengan rounded besar, tombol aksi primary bentuk pil, integrasi html5-qrcode di area kartu membulat. [x] Selesai - 18 Oktober 2026: `ps-card` radius 24px + shadow halus, `cam` (bingkai kamera) radius 24px, `ps-btn` rounded-pill min-height 44px, tombol daftar/selesai `siap-btn-ios17 siap-btn-ios17-blue/ghost`, notifikasi `siap-note siap-note-danger`.
- [x] `presensi/konfirmasi.php` — Halaman konfirmasi data presensi, form data siswa di kartu iOS 17, tombol konfirmasi/batal bentuk pil. [x] Selesai - 18 Oktober 2026: `ps-card` radius 24px, `ps-profile`/`ps-chip`/`ps-info` pakai token `--siap-*`, notifikasi `siap-note-success/warning/danger`, tombol konfirmasi/batal/pair `siap-btn-ios17 siap-btn-ios17-blue/ghost` (rounded-pill), input kode `siap-input siap-input-ios17`.
- [x] `presensi/berhasil.php` — Halaman status presensi berhasil/gagal, badge status (success/danger) bentuk pil, pesan notifikasi di kartu membulat. [x] Selesai - 18 Oktober 2026: `hs-struk`/`hs-kartu`/`hs-gagal` radius 24px + soft shadow, `hs-lencana hadir/telat` bentuk pil, notifikasi `siap-note-info/danger`, tombol selesai/coba lagi/kembali `siap-btn-ios17 siap-btn-ios17-blue/ghost` (rounded-pill).
- [x] `presensi/daftar_hp.php` — Halaman pendaftaran perangkat, form pairing dengan input rounded, tombol aksi bentuk pil, notifikasi status di area note. [x] Selesai - 18 Oktober 2026: ditambahkan include `presensi.css`; `ps-card` (radius 24px) menggantikan `card` Bootstrap; semua `alert alert-*` diganti `siap-note siap-note-*`; tombol `btn btn-primary/outline-secondary` diganti `siap-btn-ios17 siap-btn-ios17-blue/ghost` (rounded-pill, min-height 44px); ikon status pakai warna token `--siap-*`.

### C. Panel Admin (29 file)
- [x] `panel/dashboard.php` — Dashboard admin, statistik besar (typography display), card statistik dengan rounded ≥ 24px dan soft shadow, grafik Chart.js di area kartu membulat. [x] Selesai - 18 Oktober 2026: judul pakai `siap-eyebrow` + `siap-h2`; `.dash-hero`/`.dash-tile`/`.dash-card` radius 24px + soft shadow + border `--siap-line`; `.pesan-banner` radius 24px; tombol `siap-btn-ios17 siap-btn-ios17-blue/ghost` (rounded-pill); badge `siap-badge-success/warning/danger/info`; grafik Chart.js di kartu membulat.
- [x] `panel/device.php` — Manajemen perangkat terdaftar, tabel device di dalam card membulat, badge status bentuk pil, aksi tombol rounded-pill. [x] Selesai - 18 Oktober 2026: judul `siap-eyebrow` + `siap-h2`; tombol aksi (`Reset Terpilih`, Blokir, Aktifkan, Reset, Kode Pasangan, Batal, Lanjut, Kembali, Ya Reset Sekarang) `siap-btn-ios17 siap-btn-ios17-blue/ghost` (rounded-pill); badge `siap-badge-success/danger/info`; notifikasi `siap-note-warning/danger/info`; input `siap-input siap-input-ios17`.
- [x] `panel/hak_akses.php` — Manajemen hak akses pengguna, form role dengan input rounded, toggle akses berbentuk switch/pill. [x] Selesai - 18 Oktober 2026: judul `siap-eyebrow` + `siap-h2`; tombol Pengguna/pilih semua/Simpan `siap-btn-ios17 siap-btn-ios17-blue/ghost` (rounded-pill); matriks hak akses di dalam `tbl-card`.
- [x] `panel/kenaikan_kelas.php` — Proses kenaikan kelas siswa, form seleksi kelas di kartu membulat, tombol eksekusi bentuk pil. [x] Selesai - 18 Oktober 2026: judul `siap-eyebrow` + `siap-h2`; `promo-jenjang`/`promo-card`/`promo-summary-bar` radius 24px; tombol aksi `siap-btn-ios17 siap-btn-ios17-blue/ghost` (rounded-pill); input target `siap-input siap-input-ios17`; notifikasi `siap-note-warning`.
- [x] `panel/kode_massal.php` — Pembuatan kode pasangan massal, form input jumlah siswa di kartu iOS 17, download/print kode dengan tombol rounded-pill. [x] Selesai - 18 Oktober 2026: judul `siap-eyebrow` + `siap-h2`; notifikasi `siap-note-danger`; label `siap-label-ios17`; input `siap-input-ios17`; tombol Buat/Salin/Excel/Cetak/Batalkan `siap-btn-ios17 siap-btn-ios17-blue/ghost` (rounded-pill).
- [x] `panel/kode_pasangan.php` — Daftar kode pasangan per siswa, status digunakan/kadaluarsa dalam badge bentuk pil, tombol buat baru bentuk pil. [x] Selesai - 18 Oktober 2026: judul tidak ada (popup AJAX); kartu CARA 1/2 `ps-card ps-card-pad` (radius 24px); notifikasi `siap-note-warning/danger/info`; tombol Salin/Tutup/Batal `siap-btn-ios17-ghost`, Selesai/Buat QR & Kode `siap-btn-ios17-blue` (rounded-pill).
- [x] `panel/log_aktivitas.php` — Tabel log aktivitas di dalam card membulat, filter tanggal/pengguna dengan input rounded, pagination tombol rounded-pill. [x] Selesai - 18 Oktober 2026: judul `siap-eyebrow` + `siap-h2`; input pencarian/tanggal `siap-input siap-input-ios17`; tombol Reset `siap-btn-ios17-ghost` (rounded-pill); badge aksi `siap-badge-*`.
- [x] `panel/log_cetak.php` — Halaman cetak log, layout print-friendly (tanpa UI perubahan visual, hanya cetak). [x] Selesai - 18 Oktober 2026: endpoint JSON tanpa tampilan UI, tidak ada perubahan visual yang diperlukan.
- [x] `panel/master_kelas.php` — Master data kelas, form tambah/edit kelas di kartu membulat, daftar kelas dengan badge kelas bentuk pil. [x] Selesai - 18 Oktober 2026: judul `siap-eyebrow` + `siap-h2`; `stat-card`/`ikon` radius 24px; `ps-card` (kosong data); notifikasi `siap-note-danger`; tombol Batal/Simpan `siap-btn-ios17 siap-btn-ios17-blue/ghost` (rounded-pill); input nama kelas `siap-input siap-input-ios17`.
- [x] `panel/papan.php` — Papan presensi real-time, daftar siswa masuk hari ini, auto-refresh, card donut dengan rounded besar. [x] Selesai - 18 Oktober 2026: `.bp-card` dan `.bp-jt` (tile per jenjang) radius 24px (`--siap-radius-window`); tombol Layar penuh `siap-btn-ios17 siap-btn-ios17-ghost` (rounded-pill); semua warna pakai token `--siap-*`.
- [x] `panel/pengaturan.php` — Pengaturan aplikasi (gps_aktif, jam masuk, dll), form input dengan `siap-label` mono, toggle berbentuk switch, tombol simpan bentuk pil. [x] Selesai - 18 Oktober 2026: judul `siap-eyebrow` + `siap-h2`; notifikasi `siap-note-danger`; label `siap-label-ios17`; input `siap-input-ios17` (teks, number, date, color, file); tombol Ambil Lokasi `siap-btn-ios17-ghost`, Simpan `siap-btn-ios17-blue` (rounded-pill).
- [x] `panel/pengguna.php` — Manajemen pengguna/admin, form tambah/edit user, password di dalam card membulat, tombol aksi bentuk pil. [x] Selesai - 18 Oktober 2026: judul `siap-eyebrow` + `siap-h2`; notifikasi `siap-note-danger`; label `siap-label-ios17`; input/select `siap-input-ios17`; tombol Batal/Simpan `siap-btn-ios17 siap-btn-ios17-blue/ghost` (rounded-pill); toolbar Hak Akses/Tambah `siap-btn-ios17-ghost/blue`.
- [x] `panel/presensi.php` — Rekap presensi admin, filter tanggal/kelas/status dengan input rounded, tabel data di dalam card membulat, export tombol bentuk pil. [x] Selesai - 18 Oktober 2026: judul `siap-eyebrow` + `siap-h2`; input date/select `siap-input-ios17`; tombol Export CSV/Excel/Print/Reset `siap-btn-ios17-ghost` (rounded-pill); badge status `siap-badge-*`.
- [x] `panel/presensi_print.php` — Cetak rekap presensi, layout kertas (print-only, tidak berubah visual layar). [x] Selesai - 18 Oktober 2026: tombol Cetak/Tutup `siap-btn-ios17 siap-btn-ios17-blue/ghost` (rounded-pill) di toolbar layar.
- [x] `panel/profil.php` — Profil admin, form edit profil, ganti password, avatar di dalam kartu membulat dengan rounded besar. [x] Selesai - 18 Oktober 2026: judul `siap-eyebrow` + `siap-h2`; `pf-card` radius 24px; notifikasi `siap-note-danger`; label `siap-label-ios17`; input `siap-input-ios17`; tombol Batal/Simpan `siap-btn-ios17 siap-btn-ios17-blue/ghost` (rounded-pill).
- [x] `panel/qr_generate.php` — Generate QR, form parameter, preview QR di area kartu membulat, download tombol bentuk pil. [x] Selesai - 18 Oktober 2026: judul `siap-eyebrow` + `siap-h2`; kartu `ps-card ps-card-pad` (radius 24px); tombol Download/Cetak `siap-btn-ios17-ghost`, Regenerate `siap-btn-ios17-blue` (rounded-pill); badge status `siap-badge-*`.
- [x] `panel/qr_gerbang.php` — QR gerbang dinamis, token, validasi waktu, tampilan layar gerbang dengan latar `--siap-bg`, token besar di tengah kartu. [x] Selesai - 18 Oktober 2026: `#qrHolder` radius 24px + soft shadow + border `--siap-line`; tombol Muat Ulang `siap-btn-ios17-ghost` (rounded-pill).
- [x] `panel/qr_kelas.php` — QR kelas permanen, daftar kelas + token, generate/regenerate, card kelas dengan rounded besar. [x] Selesai - 18 Oktober 2026: MODE TAMPIL `#qrHolder` radius 24px + shadow; tombol Ganti/Cetak/Regenerate `siap-btn-ios17-ghost/blue`; modal Batal/Ya Regenerate `siap-btn-ios17-ghost/blue`. MODE PILIH: judul `siap-eyebrow` + `siap-h2`; `kelas-card` radius 24px; `qr-thumb` radius 16px; tombol Cetak Semua `siap-btn-ios17-ghost`; kosong data `ps-card`.
- [x] `panel/qr_kelas_print.php` — Cetak QR kelas, layout cetak per kelas (print-only). [x] Selesai - 18 Oktober 2026: tombol Kembali/Cetak `siap-btn-ios17-ghost/blue` (rounded-pill); select jenjang/kelas `siap-input-ios17`.
- [x] `panel/qr_print.php` — Cetak QR siswa individu, layout kertas (print-only). [x] Selesai - 18 Oktober 2026: tombol Cetak `siap-btn-ios17-blue` (rounded-pill); select kelas `siap-input-ios17`.
- [x] `panel/qr_siswa.php` — QR pribadi siswa, generate, tampilkan QR di area kartu membulat, download tombol bentuk pil. [x] Selesai - 18 Oktober 2026: judul `siap-eyebrow` + `siap-h2`; `kelas-card` radius 24px; `siswa-thumb` radius 16px; kosong data `ps-card`; tombol Cetak Semua/Kembali/Cetak Sekarang `siap-btn-ios17-ghost/blue` (rounded-pill).
- [x] `panel/rekap.php` — Rekapitulasi presensi, agregat per kelas/tanggal, tabel ringkasan di dalam card membulat. [x] Selesai - 18 Oktober 2026: judul `siap-eyebrow` + `siap-h2`; select bulan/kelas + input tahun `siap-input-ios17`; tombol Cetak `siap-btn-ios17-ghost` (rounded-pill).
- [x] `panel/rekap_print.php` — Cetak rekapitulasi, layout kertas (print-only). [x] Selesai - 18 Oktober 2026: tombol Cetak/Tutup `siap-btn-ios17-blue/ghost` (rounded-pill).
- [x] `panel/security_log.php` — Log keamanan, event device mismatch, filter, tabel di dalam card membulat. [x] Selesai - 18 Oktober 2026: judul `siap-eyebrow` + `siap-h2`; input siswa/tanggal + select event `siap-input-ios17`; tombol Reset `siap-btn-ios17-ghost` (rounded-pill); badge event `siap-badge-*`.
- [x] `panel/siswa.php` — Master siswa, daftar siswa, pencarian, filter kelas, tombol aksi bentuk pil. [x] Selesai - 18 Oktober 2026: judul `siap-eyebrow` + `siap-h2`; input q + select kelas/jurusan `siap-input-ios17`; tombol Import/Tambah/Reset `siap-btn-ios17-ghost/blue` (rounded-pill); badge kelas `siap-badge-info`.
- [x] `panel/siswa_detail.php` — Detail siswa, foto, data presensi, riwayat device di dalam kartu membulat. [x] Selesai - 18 Oktober 2026: judul `siap-eyebrow` + `siap-h2`; kartu `ps-card ps-card-pad` (radius 24px); tombol Kembali/Edit/QR/Perangkat `siap-btn-ios17-ghost/blue` (rounded-pill); badge status `siap-badge-*`.
- [x] `panel/siswa_edit.php` — Form edit siswa, input data, upload foto, simpan/batal tombol bentuk pil. [x] Selesai - 18 Oktober 2026: judul `siap-eyebrow` + `siap-h2`; notifikasi `siap-note-danger`; kartu `ps-card ps-card-pad`; label `siap-label-ios17`; input/select `siap-input-ios17`; tombol Simpan/Batal `siap-btn-ios17-ghost/blue` (rounded-pill).
- [x] `panel/siswa_import.php` — Impor siswa dari file, upload xlsx, preview impor di area kartu membulat. [x] Selesai - 18 Oktober 2026: judul `siap-eyebrow` + `siap-h2`; notifikasi `siap-note-danger`; kartu `ps-card ps-card-pad` (radius 24px); tombol Import/Unduh template `siap-btn-ios17-ghost/blue` (rounded-pill).
- [x] `panel/siswa_tambah.php` — Form tambah siswa baru, input data di kartu iOS 17, simpan tombol bentuk pil. [x] Selesai - 18 Oktober 2026: judul `siap-eyebrow` + `siap-h2`; notifikasi `siap-note-danger`; kartu `ps-card ps-card-pad`; label `siap-label-ios17`; input/select `siap-input-ios17`; tombol Simpan/Batal `siap-btn-ios17-ghost/blue` (rounded-pill).

### D. Halaman Error (3 file)
- [ ] `403.html` — Halaman akses ditolak, latar `--siap-bg`, tipografi iOS 17 (heading besar, teks muted), tombol kembali bentuk pil.
- [ ] `404.html` — Halaman tidak ditemukan, latar `--siap-bg`, tipografi iOS 17, tombol kembali bentuk pil.
- [ ] `502.html` — Halaman gagal layanan, latar `--siap-bg`, tipografi iOS 17, tombol kembali bentuk pil.

### Ringkasan
| Kategori | File | Jumlah |
|----------|------|--------|
| A. Fondasi Global & Komponen iOS 17 | `assets/css/style.css`, `includes/header.php`, `includes/footer.php`, `assets/css/presensi.css` | 4 |
| B. Autentikasi & Halaman Publik | `login.php`, `beranda.php`, `presensi/index.php`, `presensi/konfirmasi.php`, `presensi/berhasil.php`, `presensi/daftar_hp.php` | 6 |
| C. Panel Admin | 29 file di folder `panel/` | 29 |
| D. Halaman Error | `403.html`, `404.html`, `502.html` | 3 |
| **Total** | | **42 file** |

File yang **dikecualikan** (tidak merender UI): `logout.php`, `index.php` (router), `presensi/scan.php`, `panel/siswa_hapus.php`, `panel/presensi_export.php`, seluruh `api/`.

WAJIB BERHENTI di tahap ini. Jangan melakukan perubahan kode HTML/CSS pada file apa pun. Silakan periksa daftar tugas di atas. Jika sudah setuju, perintahkan saya untuk mulai mengeksekusi file pertama lalu mennadainya setiap kali selesai mengerjakannya dengan format mengikuti todolist yang sudah ada pada laporan.md.

---
---

## 💡 UX & Feature Strategy Audit (Claude)

**Date:** 22 October 2026 · **Code read:** an exported snapshot of the `vswindows` branch (the security-fix code plus ngrok tooling). I read `presensi/*`, `api/*`, `includes/*`, `assets/js/*`, `beranda.php`, `login.php`, `portal_siswa/*` and the 35 `panel/*` modules. **Method:** I read the code and traced the flows by hand. Nothing was run on a phone, and I have no usage data, so any timing or percentage below is an estimate and is labelled as one.
**Constraints respected:** I keep every decision from `fixes/fix-summary.md` and `review/fix-branch-final-review.md`: the GET_LOCK login limiter, known IPs, the progressive delay, the two-phase cookie re-bind, the forced password change, HMAC class QRs and the required `base_url`. When a recommendation touches one of them, the trade-off is stated next to it.

> **This snapshot is not the `front` branch.** Several items marked "Selesai" in the sections above are **not** in this code. I checked: `api/` has 5 files and no `api/quick.php`. There is no `includes/ratelimit.php` (APCu), no manifest or service worker (PWA), and no settings cache. `device.js` is loaded without a version (`presensi/index.php:167`). `includes/header.php:50-51` and `panel/qr_*.php` still load Bootstrap from jsdelivr. `panel/kode_massal.php:12-15` still offers "Tanpa batas waktu". The findings below describe the code as it stands. Where the `front` report says that branch already changes a flow, a **Front-branch note** says so.

**Legend:** Effort **S** ≤ 1 day · **M** 2–5 days · **L** > 1 week. Impact is the benefit to students and staff. Risk covers regressions and any security trade-off.

---

### A. User Flow Analysis & Simplification (Student & Admin)

#### A.1 The daily check-in (a student whose phone is already registered: about 99% of traffic, mostly between 06:50 and 07:00)

**Path 1: the phone's own camera scans the gate QR and the student taps the link** (the gate QR encodes `BASE_URL/presensi/?gate=<token>`, `panel/qr_gerbang.php:40`)

| # | What happens | Type | Code |
|---|---|---|---|
| 1 | `GET /presensi/?gate=T`. The gate token is checked and stored in the session. The page loads Bootstrap, the icons, 2 CSS files, `device.js` and **html5-qrcode (375 KB)**, even though no camera is needed on this branch | page load 1 | `presensi/index.php:11-23, 166` |
| 2 | `POST api/scan.php` (mode `device`) identifies the student from the device cookie. **In parallel**, `POST api/cek_perangkat.php` runs only to decide whether to show the "Daftarkan Perangkat" button | 2 requests | `presensi/index.php:207-216, 246-257`, `api/scan.php:28-50` |
| 3 | On `MATCH`, a JavaScript redirect opens the confirm page | page load 2 | `presensi/index.php:216` |
| 4 | `POST api/scan.php` (mode `preview`) checks the gate token, the student, already-present and the device binding **a second time** | request | `presensi/konfirmasi.php:160-201`, `api/scan.php:52-…` |
| 5 | **Gate QR:** the student taps **KONFIRMASI PRESENSI**. Only then is GPS requested (`enableHighAccuracy`, `maximumAge: 0`, `timeout: 15000`). **Class QR:** the button is clicked automatically | tap + GPS wait | `presensi/konfirmasi.php:23-28, 188-193, 243-275` |
| 6 | `POST api/presensi.php`. `proses_simpan_presensi()` checks the token and the device **a third time** (`check_or_register_device`), then the hours, GPS and insert | request | `includes/presensi_core.php:114-263` |
| 7 | Redirect to the result page | page load 3 | `presensi/berhasil.php` |

**Total: 3 page loads, 4 API requests, 2 taps** (1 for a class QR), plus a GPS wait that only starts after the tap.

**Path 2: from the home page or the SIAPApp WebView.** The home page `beranda.php` loads and calls `api/beranda` and `api/cek_perangkat`, then polls `api/beranda` every 8 s (`beranda.php:360`). The student taps **Scan Absen** and `presensi/index.php` loads, calling `cek_perangkat` again. The camera decodes the gate QR, and the whole page **reloads** with `?gate=` instead of calling the API in place (`presensi/index.php:198`). Steps 2–7 then follow. **Total: 5 page loads, 7 API requests (`cek_perangkat` 3 times), 2 taps.**

**Friction points**

- **F1: the same student is identified three times.** For a `MATCH` device, the preview call (step 4) adds no information. Steps 2 and 6 already decide everything.
- **F2: the gate and class QRs behave differently for no stated reason.** A class QR auto-confirms, a gate QR asks for a tap (`konfirmasi.php:23-28`). Students who use both learn two behaviours.
- **F3: GPS starts late, and a failure is expensive.** GPS starts after the tap with `maximumAge: 0`, so a cheap Android phone indoors can wait seconds for a fix. If the fix fails, the request is sent with `null`, the server answers `GPS_REQUIRED`, and `api/presensi.php:42` clears the session on **every** failure. "Coba Lagi" then means scanning the QR again. The `front` report raised the same issue as RISK-4.
- **F4: the gate token dies when the screen refreshes, not when it expires.** `generate_qr_dinamis()` marks every `AKTIF` token of the same `lokasi` as `EXPIRED` each time it creates a new one (`includes/qr_dinamis.php:24-26`). The gate page creates a new token on every refresh (`panel/qr_gerbang.php:9, 15, 48-52`). A student's real window is therefore *the seconds left on the countdown*, not `qr_dinamis_durasi_detik`. With 3 page loads, a tap and a GPS fix (roughly 5–20 s at peak), anyone who scans late in the cycle gets `GATE_INVALID`. My unmeasured estimate is 5–15% of peak scans at the default 120 s. Worse, **two gate monitors that use the default `lokasi=Gerbang Utama` cancel each other's tokens** on every refresh. Signed class tokens already allow +180 s (`QR_KELAS_TOLERANSI_DETIK`, `qr_dinamis.php:63, 85`), so the gate is the odd one out.
- **F5: an `ALREADY_PRESENT` answer from the save step shows a red failure screen.** Only the preview path redirects to the friendly "Anda Sudah Presensi" view (`konfirmasi.php:173`). The save path goes to `berhasil.php?status=error` (`konfirmasi.php:258`), which shows "Presensi Tidak Berhasil". The case is rare today, but with the fast path in A1 a second scan would end up here every time.
- **F6: the home page is a dead end for new students on the default setting.** `device_wajib_kode` defaults to 0 (`database/database.sql:122`), so `/presensi` would let a new phone self-register through the student's personal QR card. But **Scan Absen** on the home page refuses any device that isn't registered and only offers the pairing-code dialog (`beranda.php:389-405`; `api/cek_perangkat.php:26` returns `BELUM` whatever the setting).
- **F7: the binding belongs to one browser.** If the camera app opens a different browser from the one that was bound, the student is "not recognised". The UI only warns about this in small print (`presensi/index.php:151`, `presensi/daftar_hp.php:48`). Path 2, inside the app, avoids the problem and should be the path the school teaches.

#### A.2 Is the confirm step (`presensi/konfirmasi.php`) still needed?

| What the step could protect against | Does the tap stop it? | What actually protects |
|---|---|---|
| Accidental scan | Hardly. The gate scan is deliberate (the camera is pointed at a screen), and the record is true (the student is at the gate), once per day (`uniq_siswa_tanggal`), with the server's time | Server time and the duplicate check |
| Wrong student on a shared phone | **No.** For `identified_via = 'device'` the identity comes from the binding (UNIQUE `device_hash` and `siswa_id`; `CLAIMED_BY_OTHER`), and the tap doesn't change it. **Yes** when the identity came from a personal QR card (`presensi/scan.php:29-30`): the name and photo are a real "is this you?" check against a borrowed or photographed card | Device binding |
| Binding a new phone by mistake | **Yes, keep it.** The binding is permanent, and a reset is capped at `maks_reset_device_per_bulan` (default 2, `reset_device()`) | The confirm step (`warnBox`) |
| A friend checking in for you, or a forwarded QR | No. The cheater taps willingly | GPS and short-lived QRs (H3) |

**Verdict:** drop the tap when `identified_via === 'device'` and the device state is `MATCH`, which is exactly what class QRs already do. Keep the confirm screen for the personal-QR path and for registering a new device. **Security cost: none that I can measure.** On installs that still run GPS off with permanent class QRs, the fix is H3 (turn GPS on), not a button.

#### A.3 Recommendations: check-in

| ID | Recommendation | Effort | Impact | Risk / security trade-off |
|---|---|---|---|---|
| **A1** | **Zero-tap fast path for registered devices.** *Step 1 (S):* in `konfirmasi.php` also set `$autoKonfirmasi` when `$_SESSION['identified_via'] === 'device'` (a one-condition change). *Step 2 (M):* in `presensi/index.php`, when `api/scan` returns `MATCH`, call `api/presensi` straight away and skip `konfirmasi.php` and the preview. Start `navigator.geolocation` **when the page loads**, in parallel with `api/scan` (`maximumAge` about 30 s), so the fix is ready. Drop the `cek_perangkat` call (show "Daftarkan" only on `NOT_FOUND`). Load html5-qrcode only when a camera is actually needed. In Path 2, decode in the page and `fetch`, with no `?gate=` reload. Treat `ALREADY_PRESENT` as success (F5) by returning its data or keeping `identified_siswa_id` for that code. **Result:** Path 1 goes from 3 page loads, 4 requests and 2 taps to **2 page loads, 2 requests and 1 tap** (opening the link). Path 2 goes from 5 page loads, 7 requests and 2 taps to about **3 page loads, 4 requests and 1 tap**. | S → M | **Very high** (about 1,000 times a day, at peak) | Low. The server is unchanged and still runs all 11 checks in `proses_simpan_presensi()`. Regression-test the `konfirmasi` states (NEW, NEED_PAIRING, MISMATCH, BLOCKED). **Front-branch note:** the `front` report says `api/quick.php` (one request per check-in) is done on that branch (§3.4 and priority table item 8). If it is merged, use it as the endpoint; starting GPS first and removing the tap still apply. The iOS-17 and SIPIKET redesign (roadmap B) restyles `konfirmasi.php` but keeps the confirm and cancel buttons, so it doesn't remove the step. |
| **A2** | **Give gate tokens a grace period instead of killing them on refresh.** In `generate_qr_dinamis()`, stop expiring the previous token straight away. Let `kadaluarsa_at` (the displayed duration plus about 60–90 s) decide, the same model the class QR already uses. Give each screen its own `lokasi` (C1 does this automatically). | S | High (removes random `GATE_INVALID` rescans and the two-monitor collision) | A forwarded screenshot stays usable for up to about 200 s instead of up to 120 s. GPS stays the main control, and class QRs already accept +180 s. |
| **A3** | **Recover from GPS errors in place.** Check `navigator.permissions` before sending. On `GPS_REQUIRED`, keep the session (an exception in `api/presensi.php:42`) and show an in-page "turn on location, then retry" card. | S | Medium–high (the most common real-world failure on low-end phones) | Low. The gate token is still checked again on retry. |
| **A4** | **Fix the home-page dead end (F6).** Return `device_wajib_kode` from `api/cek_perangkat` and only block **Scan Absen** when it is 1. If B1 below is adopted, this disappears. | S | Medium (onboarding on the default setting) | None |

#### A.4 Login flows

- **Staff.** `login.php` already has one form for staff and students. The limiter, known IPs and the forced change don't affect a normal login. What remains:
  - **(a) An extra redirect, and a 403 for some operators.** After login, `login.php:62` sends `index.php?modul=dashboard`, which costs a 301 to `/dashboard` (`index.php:105-115`). It also gives a 403 to an operator without `dashboard.lihat`. Redirecting to `BASE_URL . '/'` would use the existing "first page this user may see" logic (`index.php:89-95`). **S**, no risk.
  - **(b) Staff log in again many times a day.** The session cookie has `lifetime 0` (`includes/keamanan.php:44`) and no idle timeout is set, so PHP's default of about 24 minutes applies (README §8b says the same for the portal). An explicit idle limit such as 2 hours, the same as `PORTAL_IDLE_DETIK`, would help. **S.** *Trade-off:* an unattended PC stays logged in longer. Kiosk mode (C1) removes the main reason panel sessions sit open on shared PCs, so the net risk falls.
  - **(c) The login page is heavy.** It loads all of Bootstrap, the icon font and **two** Google Fonts families for a two-field form (`login.php:110-115`). This is cosmetic; leave it to the `front` redesign.
- **Student portal.** Students need a **second admin-issued credential**: an 8-character activation code valid for 7 days (`panel/akun_siswa.php`, `portal_terbitkan_kode()`), then NISN and a password. The bound phone already holds a stronger secret: the HttpOnly `SIAP_PERANGKAT` cookie, whose path is the site root, so `/portal` receives it (`includes/device_binding.php:90-103`).

| ID | Recommendation | Effort | Impact | Risk / security trade-off |
|---|---|---|---|---|
| **A5** | **Fix the staff post-login redirect and set an explicit idle timeout** (points a and b above). | S | Low–medium | See (b) |
| **A6** | **Optional device-trust entry to the portal.** On the home page, a recognised phone sees **"Ajukan izin"**, which starts a portal session only if `binding_dari_cookie()` returns an `ACTIVE` binding with `secret_aktif_at` set. Pending and UUID-only bindings must be refused. NISN and password stay as the fallback (lost phone, binding off). Activation codes are then only needed by the few students who ask for one. | M | Medium–high (removes one credential per student; a leave request becomes 1 tap from the home page) | Anyone holding the unlocked phone can file a leave request in the student's name. The impact is low: staff approve it, the student is notified, and the same person could already check in as the student. Accepting pending or UUID-only bindings **would reopen the copied-UUID residual** in fix-summary, so the code must not do that. The portal login limiter is untouched. |

#### A.5 The admin's day

| Task | Today | Friction |
|---|---|---|
| Gate screen | Log in on the gate PC and open `/qr/gerbang` | The session cookie dies when the browser restarts (`keamanan.php:44`), so someone logs in every morning |
| Classroom screens (secure mode, `qr_kelas_permanen = 0`) | **Every classroom device** stays logged in to the panel with an account that has `qr_kelas.lihat` | Staff credentials sit on 30 or more classroom PCs. The **Regenerate** button is on screen for anyone with `qr_kelas.generate` (`panel/qr_kelas.php:117`). One "Back" press lands in the panel. This is why schools keep permanent class QRs despite the red warning. **Fix: C1** |
| Following up absent students | The dashboard shows a **count** of "Belum Presensi" (`panel/dashboard.php:42, 175`) and says "tindak lanjuti siswa yang belum presensi" (`:229`) | **No page lists who is missing.** Data Presensi only lists rows that exist (`panel/presensi.php:10-45`). **`ALPA` is never written anywhere** (no `INSERT` sets it), so the Alpa column in `rekap.php` is always 0. Staff can't record attendance or izin for a student (I4 in fix-summary, left unfixed). **Fix: C2** |
| A student changed or reset their phone | Reset dialog (checkbox and reason, `panel/device.php:309-337`), page reload, then the "Kode Pasangan" popup (`device.php:344`), then "Buat QR & Kode" | About 6 clicks across 2 dialogs. The monthly cap of 2 applies to single resets, but a **mass** reset ignores it (`includes/device_binding.php:540`), so admins learn to use a mass reset for one student. After the cookie migration, every cleared cookie means a reset, so this gets more frequent. **Fix: B3** |
| Leave requests | Queue with a badge, bulk approve (`panel/izin.php:36-44`) | Fine |

---

### B. Recommended Features to Remove / Simplify

#### B1. Device enrollment sprawl: the biggest source of bloat

The student-side task "link this phone to me" is implemented through:

| | Count | Where |
|---|---|---|
| **Credentials** | 4 | The personal QR card (`siswa.qr_token`, which never expires and has no bulk regenerate); the 6-digit pairing code (10 min); the QR pairing token (10 min, created with the code); bulk codes (up to "no time limit") |
| **Student screens** | 5 | The home-page dialog with **Kode / QR tabs and its own camera scanner** (`beranda.php:204-240`); the `presensi/index.php` overlay with NIS and code (`:117-155`); the `konfirmasi.php` code box without NIS (`:118-129`); `presensi/daftar_hp.php` (QR token); self-registration through the personal card (`konfirmasi.php` `warnBox`) |
| **API branches** | 3 | `api/pasangan.php`: token (`:31`), NIS and code (`:37`), session and code (`:53`) |
| **Admin modules** | 5 (+ device) | `kode_pasangan`, `kode_massal`, `qr_siswa`, `qr_generate`, `qr_print` |

**Why it hurts**

- **One task, four sets of instructions, and they disagree.** The admin popup tells the student to enter **NIS and code** on the presensi page (`kode_pasangan.php`, "CARA 2"). `konfirmasi.php` asks for the code only. README §8 says the code is typed "saat konfirmasi presensi".
- **On the default setting, the personal QR card is a permanent bearer credential for enrolment.** Anyone with a photo of the card can bind their own phone as that student (C2 in the first audit; still possible here because `device_wajib_kode = 0` by default).
- **The weaknesses the `front` report fixed as D3 are still open in this snapshot.** The session counter `pair_try` (`api/pasangan.php:25-29`) can be bypassed by not sending a session cookie, and bulk codes can last forever.

**What is NOT bloat:** having both a QR pairing token and a 6-digit code. `buat_kode_pasangan()` creates both in **one row**, and using one cancels the other (`device_binding.php:657-674`). That pair is cheap and fits the "student at the admin desk" case well. Keep it.

**Target state.** One credential: an admin-issued pairing code (shown as a QR for single issues and printed as a list for bulk). One student screen: a "Daftarkan Perangkat" dialog (NIS and code), plus the QR landing page `daftar_hp.php`.

- *Step 1 (S–M, no policy change):*
  - Delete the home page's in-page QR scanner tab; the phone's own camera already opens `daftar_hp.php`.
  - Replace the `index.php` overlay and the `konfirmasi.php` code box with the same component.
  - Remove the session-and-code branch of `api/pasangan.php`, which the NIS-and-code branch already covers.
- *Step 2 (M, needs a school decision):*
  - Make `device_wajib_kode = 1` the default.
  - **Retire the personal QR card from check-in:** `presensi/scan.php`, `needPersonalBox` in `presensi/index.php:92-107`, `qr_siswa`, `qr_generate`, `qr_print` and the `qr_siswa.*` permissions.
  - Keep `kode_massal` as the start-of-year onboarding tool.

**What is lost**

- Enrolment without any admin. Replace it with bulk-code sheets per class, handed out by the wali kelas.
- Printed student QR cards. They become decorative if they are also used as ID cards.

**Migration.**

- Existing bindings are untouched.
- Mid-year, flip the setting once Manajemen Perangkat shows most students are bound.
- Keep the `siswa.qr_token` column (`NOT NULL`), so there is no schema change.
- Tell students that the cards no longer work.

**Security.** A net gain: it closes enrolment from a photo of a card and removes two code paths.

**Effort:** S–M plus M. **Impact: High:** fewer screens, fewer support questions, less attack surface. **Risk: Medium:** onboarding-day logistics and a policy decision.

#### B2–B8. Smaller removals and merges

| ID | Item | Evidence | What is lost | Effort | Impact | Risk |
|---|---|---|---|---|---|---|
| **B2** | **Delete the orphan print pages** `panel/qr_print.php` (280 lines) and `panel/qr_kelas_print.php` (347 lines), and their `DAFTAR_MODUL`/route entries | Nothing links to either page (grep). Printing already happens inside the pages: `qr_siswa.php:252` `previewCetakSiswa()` and `qr_kelas.php:385` `cetakKelasGrid()`. `qr_kelas_print` redirects away in signed mode anyway | Old bookmarks | S | Low (maintenance only) | Low |
| **B3** | **Merge reset and re-pair into one "Ganti perangkat" action** (reset, then show the new QR and code in the same dialog). Replace "mass reset bypasses the cap" with an explicit admin override that needs a reason | `device.php:309-344`, `kode_pasangan.php`, `device_binding.php:540` | Nothing. Reason and audit log are kept | S–M | Medium (the most frequent admin support task) | Neutral: same `device.kelola` permission |
| **B4** | **Remove "Tanpa batas waktu" from bulk codes** (maximum 7 days) | `kode_massal.php:12-15`, `KODE_TANPA_BATAS` (`device_binding.php:792`) | Codes on paper that never expire, which are a liability | S | Low–medium | Security gain. **Front-branch note:** per the report, D3 already caps this at 7 days on `front` |
| **B5** | **Delete the dead setting** "Catat & tampilkan validasi IP (informatif)" | `validasi_ip_aktif` is saved (`pengaturan.php:73, 88`) but read nowhere | Nothing (keep the column) | S | Low (one less confusing toggle) | None |
| **B6** | **Delete `portal_siswa/masuk.php`** | The router 301s `/portal/masuk` to `/login` (`portal_siswa/router.php`). README §8b already says it can be deleted (fix-summary minor #2) | Nothing | S | Low | None |
| **B7** | **Cut log noise.** Stop writing `DEVICE_MATCH` on every check-in, and default the log retention to 365 days instead of forever | Each check-in writes **2** security-log rows: `DEVICE_MATCH` (`device_binding.php:432`) and `PRESENCE_SUCCESS` with the device hash (`presensi_core.php:246`). That buries the MISMATCH and anomaly events admins should look at. `log_retensi_hari` defaults to 0, meaning never deleted (`database.sql:116`) | A redundant row per check-in | S | Medium (a readable Log Keamanan; fewer writes at peak) | Low. `PRESENCE_SUCCESS` keeps the forensic trail |
| **B8** | **Make Papan a kiosk display type** (see C1) instead of a page that needs a logged-in panel | Papan (`panel/papan.php`) overlaps the public home-page board, which shows masked names. Papan's real value is full names on a staff-room TV | Nothing; staff can still open it in the panel | Included in C1 | Medium | See C1 |

#### B9. Honest list: what is NOT bloat

- **Data Presensi, Rekap and Dashboard** do different jobs: the daily list and export, the monthly figures per student for printing, and the at-a-glance view. Don't merge them. Link them instead: a dashboard tile opens a filtered Data Presensi, and "Belum Presensi" opens the list in C2. The daily-only export (`presensi_export.php`, one date) is a small gap; a date range would help.
- **The leave workflow is lean.** One form, one approval step, and 5 states that are each needed. `dicabut` (revoked) is rare but it is the only safe way to undo the presensi rows an approval wrote (`izin_cabut()` deletes only `PENGAJUAN` rows). Rules live in constants (`includes/izin.php:17-26`). What is missing is additive (staff-entered izin, notifying parents), not complexity to remove. One practical gap: `izin.*` isn't in `IZIN_DEFAULT_OPERATOR`, so on a default install only admins can approve.
- **Two log pages** (Log Keamanan and Log Aktivitas) serve different readers: security investigation and admin accountability. Merging them into tabs saves one menu item at best.
- **Pengguna and Hak Akses** (2 roles), **Master Kelas** and **Kenaikan Kelas** are used rarely but are essential and small.
- **The security work** (GET_LOCK, known IPs, the two-phase re-bind, the forced change, signed QRs) costs normal users almost nothing. The migration is invisible and the forced change happens once. Keep all of it. The UX cost of security in this code comes from *screens that need logins* (fixed by C1) and *the gate token lifetime* (fixed by A2), not from the protections themselves.

---

### C. Recommended Crucial New Features (ranked by impact ÷ effort)

| Rank | Feature | Impact | Effort | Risk |
|---|---|---|---|---|
| 1 | **C1: Kiosk display links** (gate, class, board) | High | M | Low–medium (see below) |
| 2 | **C2: "Belum hadir" list, manual marking and real ALPA** | High | M | Medium (manual check-in by staff could be abused) |
| 3 | **C3: Absence summary shared through a WhatsApp link** | Medium–high | S | Low (privacy policy) |
| 4 | **C4: Outbound webhook** (signed JSON) | Medium | M | Low–medium |
| 5 | **C5: Automatic WhatsApp to parents through a gateway** | High | L | Medium (consent, cost, data protection) |

**C1: Kiosk display links.** The final security review recommended this, and fix-summary lists it under "Left unfixed".

- *Data:* a new table `layar_tampilan` with these columns: `id`, `jenis` (gerbang, kelas or papan), `kelas`, `lokasi` (UNIQUE), `label`, `token_hash` (CHAR 64), `ip_izin` (optional CIDR list), `dibuat_oleh`, `dibuat_at`, `dicabut_at`, `terakhir_dilihat_at`, `terakhir_ip`.
- *Panel:* a "Layar" page under QR Code. **Create** shows the URL once, plus a QR of the URL so it can be opened on the screen device. **Revoke** takes one click. A "last seen" column catches dead screens.
- *Public side:* `/layar/<43-character base64url token>` serves a minimal page. `/layar/<token>/data` returns JSON every N seconds:
  - **gerbang:** a token from `generate_qr_dinamis()` for this screen's own `lokasi`, which fixes the F4 collision;
  - **kelas:** `qr_kelas_token_bertanda()`, already signed and short-lived;
  - **papan:** counts and the latest check-ins, with names masked unless the request comes from the allowed school network.
- The client redraws in place, with no meta-refresh flicker.
- *Security:*
  - The URL is a capability (a bearer secret): 256 bits, stored only as a hash, revocable, optionally limited to school IPs through the existing `ip_dalam_daftar()` (`functions.php:481`), with `noindex`.
  - The worst case for a leaked class link is that someone can show that class's QR anywhere. That is the same as a forwarded photo of the screen: they still need the bound phone, the right class (`presensi_core.php:141`), and GPS if it is on.
  - **Net security gain:** no staff sessions and no Regenerate button on classroom PCs. It also makes the signed class QR (H3) practical, so schools can finally turn permanent mode off.
- *Effort:* M (2–4 days).

**C2: "Belum hadir" list, manual marking and real ALPA.**

- *List:* for each class and day, the active students with no presensi row.
- *Actions:* **Hadir (manual)**, **Izin**, **Sakit** and **Alpa**, each with a required note. Rows get `metode = 'MANUAL'`, are written to `catat_aktivitas`, need a new permission `presensi.ubah`, and never overwrite a QR row. A **"Tandai Alpa sisanya"** button per class after a cut-off time gives the rekap a real Alpa figure.
- *Holidays:* this needs a holiday date list. `hari_efektif` only lists weekdays, so without it a national holiday would count as Alpa.
- *What it closes:* I4 from fix-summary, and the dead text on the dashboard.
- *Risk:* staff could record a manual Hadir for an absent student. Mitigate with the audit trail, a separate "Manual" label in exports and the rekap, and an optional rule that a manual Hadir needs a reason. Effort M.

**C3: Absence summary through a WhatsApp link.** On the C2 list, a button builds a `https://wa.me/?text=…` link with the class, the date and the names of students who are missing or Alpa. The wali kelas forwards it to the class or parent group. No gateway, no phone-number database, no cost. *Risk:* minors' names in WhatsApp groups is a school decision under Indonesia's personal data protection law (UU PDP 27/2022). Effort S.

**C4: Outbound webhook.** One URL and one HMAC secret in Pengaturan. Events: check-in, izin decision, device anomaly. A small queue table with retries, using the same "run on page load, at most once a minute" trick as `jalankan_pembersihan_log_otomatis()`, because there is no cron. It lets the school connect any Indonesian WhatsApp gateway or a Google Sheet without changing code. *Risk:* data leaving the system. Sign every payload and send the minimum fields. Effort M.

**C5: Automatic WhatsApp to parents.** This needs a parent phone column (the `siswa` table has none), import support, consent, a gateway contract and a queue. Do it after C4. Effort L.

*Deliberately not recommended now:* selfie verification and SSE live boards (from the first audit). Both add weight. A selfie adds a camera step to every check-in, which is the opposite of A1. The 8-second polling is adequate.

---

### Prioritised roadmap: top 5

1. **Week 1: fix the check-in quick wins (all S).**
   - A1 step 1: auto-confirm when the device matches.
   - A2: gate-token grace period, one `lokasi` per screen.
   - A3: retry GPS in place.
   - F5: show `ALREADY_PRESENT` as success.

   This removes the tap, the random rescans and the most common dead end without touching any security control.
2. **Full fast path (A1 step 2, M).** No confirm page for a matched device, GPS started on page load, no duplicate calls. Each check-in drops from 3–5 page loads and 4–7 requests to 2–3 page loads and 2–4 requests. If the `front` branch's `api/quick.php` is merged, use it as the endpoint.
3. **Kiosk display links (C1, M), then turn "QR Kelas permanen" off.** Classroom and gate screens without staff logins. This is the security review's open item and the precondition for H3 in practice.
4. **"Belum hadir" list, manual marking and the WhatsApp share link (C2 and C3, M plus S).** Turns the dashboard count into action, makes ALPA real and closes I4.
5. **Consolidate device enrolment (B1, M) together with B2–B7 and "Ganti perangkat" (B3).** One credential and one student screen; delete the orphan pages and the dead setting. Needs the school's decision on making "Wajib Kode Pasangan" the default and retiring the personal QR card.

**What I could not verify:**

- Real timings on low-end Android phones: GPS fix time, html5-qrcode start-up, and how many gate scans actually fail under F4. The 5–15% figure is my estimate.
- Which browser the phone's own camera opens, especially inside the `SIAPApp` WebView.
- How often admin features are used (there is no analytics). For example, I can't tell whether anyone still prints student QR cards.
- The `front` branch code itself. Front-branch notes come from the report text only.
- Behaviour on the production server (Nginx/aaPanel).
