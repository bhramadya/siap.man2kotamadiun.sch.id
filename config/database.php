<?php

/**
 * Konfigurasi koneksi database (PDO)
 * Ganti nilai di bawah sesuai environment hosting/XAMPP/aaPanel Anda.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'siapabsen');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------------------
// BASE_URL — base URL aplikasi (dipakai untuk membuat isi QR Code siswa,
// link, redirect, dan URL aset).
//
// BASE_URL TIDAK lagi ditulis manual, supaya satu kode yang sama bisa jalan
// di localhost (http://siap.test), di tunnel ngrok (https://domain-ngrok),
// maupun di hosting domain sendiri tanpa perlu mengubah file tiap kali.
//
// Urutan prioritas:
//   1) konstanta SIAP_BASE_URL  -> dari file config/production.php (server VPS)
//   2) environment variable SIAP_BASE_URL
//   3) deteksi otomatis dari request (skema + host)
//
// Contoh production: https://domainsekolah.sch.id
// ---------------------------------------------------------------------------

// Override manual untuk subfolder (biasanya TIDAK perlu diisi).
// Contoh: '/siap' bila aplikasi memang dipasang di dalam folder.
// Kalau kosong, subfolder dideteksi otomatis dari DOCUMENT_ROOT (lihat
// siap_base_path()).
define('BASE_PATH', '');

/** Host cadangan bila tidak ada request HTTP (mis. menjalankan skrip via CLI). */
const BASE_HOST_DEFAULT = 'siap.test';

// Override produksi (opsional). Salin config/production.php.example menjadi
// config/production.php lalu isi domainnya. File itu tidak di-commit.
if (is_file(__DIR__ . '/production.php')) {
    require_once __DIR__ . '/production.php';
}

define('BASE_URL', base_url_aplikasi());

/**
 * Base URL lengkap aplikasi.
 * Mengembalikan override eksplisit bila ada, jika tidak mendeteksi dari request.
 */
function base_url_aplikasi(): string
{
    // 1) Override eksplisit (konstanta dari config/production.php)
    $override = defined('SIAP_BASE_URL') ? trim((string) SIAP_BASE_URL) : '';
    // 2) Override eksplisit (environment variable, mis. di aaPanel/Panel)
    if ($override === '') {
        $override = trim((string) (getenv('SIAP_BASE_URL') ?: ''));
    }
    // Override = base URL PENUH (sudah termasuk subfolder bila ada).
    if ($override !== '') {
        return rtrim($override, '/');
    }

    // 3) Deteksi dari request
    $skema = siap_https_aktif() ? 'https' : 'http';
    return $skema . '://' . siap_host_request() . siap_base_path();
}

/**
 * Subfolder tempat aplikasi berada relatif terhadap document root server.
 * '' berarti aplikasi dilayani di root domain (mis. vhost siap.test),
 * '/siap' bila dilayani lewat default vhost (folder D:/laragon/www/siap).
 * Dideteksi otomatis supaya BASE_URL selalu benar, apa pun cara aplikasinya
 * dipasang, tanpa perlu mengubah konfigurasi tiap kali.
 */
function siap_base_path(): string
{
    // Override manual menang.
    if (defined('BASE_PATH') && BASE_PATH !== '') {
        return '/' . trim((string) BASE_PATH, '/');
    }

    $docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    $appDir  = realpath(__DIR__ . '/..');           // folder root aplikasi
    if ($docRoot === '' || $appDir === false) {
        return '';
    }

    $docRoot = rtrim(str_replace('\\', '/', (string) realpath($docRoot)), '/');
    $appDir  = str_replace('\\', '/', (string) $appDir);
    if ($docRoot !== '' && strpos($appDir, $docRoot . '/') === 0) {
        return substr($appDir, strlen($docRoot));   // mis. '/siap'
    }
    return '';
}

/**
 * Host publik yang sedang diakses.
 *
 * Sumber utama adalah header Host yang dikirim browser — nilainya sudah
 * dijamin benar oleh server (ngrok menolak request dengan Host yang tidak
 * cocok dengan endpoint-nya, dan vhost Apache memverifikasi ServerName).
 *
 * X-Forwarded-Host hanya dipakai sebagai cadangan bila Host kosong, dan HANYA
 * dipercaya bila request datang dari loopback (127.0.0.1 / ::1 = proxy lokal
 * di mesin ini). Tanpa aturan ini, siapa pun bisa mengarang host sehingga QR
 * Code yang dicetak (isinya memuat BASE_URL) menunjuk ke domain palsu.
 */
function siap_host_request(): string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

    if ($host === '') {
        $remote    = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $proxyLokal = in_array($remote, ['127.0.0.1', '::1'], true);
        if ($proxyLokal) {
            $daftar = array_filter(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))));
            $host  = (string) end($daftar);
        }
    }

    // Sanitasi: hanya izinkan karakter yang lazim pada nama host + port.
    $host = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $host) ?? '';
    return $host !== '' ? $host : BASE_HOST_DEFAULT;
}

/**
 * Apakah koneksi pengguna memakai HTTPS (langsung atau lewat reverse proxy).
 * Fungsi ini dipakai untuk BASE_URL sekaligus untuk cookie sesi "secure".
 *
 * Untuk X-Forwarded-Proto dipakai NILAI TERAKHIR: proxy menambahkan nilainya
 * di belakang nilai yang dikirim klien, jadi nilai terakhir yang paling dekat
 * dengan server dan tidak bisa dipalsukan klien.
 */
function siap_https_aktif(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') return true;

    $daftar = array_filter(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))));
    $proto  = strtolower((string) end($daftar));
    return $proto === 'https';
}

// Zona waktu server — WAJIB konsisten agar validasi jam presensi akurat.
date_default_timezone_set('Asia/Jakarta');

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Samakan timezone session MySQL dengan PHP (Asia/Jakarta) agar
            // fungsi SQL seperti NOW()/CURDATE() selalu konsisten dengan
            // date()/time() di PHP -- mencegah bug batas hari di sekitar tengah malam.
            $pdo->exec("SET time_zone = '+07:00'");
        } catch (PDOException $e) {
            tampilkan_error_database($e);
        }
    }
    return $pdo;
}

/**
 * Koneksi database gagal -> tampilkan halaman 503 yang rapi (503.html), BUKAN teks polos.
 * - Detail galat (host, user, dsb.) hanya masuk error_log server, tidak pernah ke browser.
 * - Halaman memuat "kode kejadian" acak yang sama dengan yang ditulis ke log,
 *   sehingga administrator bisa mencocokkan laporan pengguna dengan baris lognya.
 * - Permintaan API/AJAX (yang mengharapkan JSON) dijawab JSON 503, bukan HTML.
 */
function tampilkan_error_database(Throwable $e): void
{
    $kode = 'DB-' . strtoupper(bin2hex(random_bytes(3)));
    error_log('DB Connection Error [' . $kode . ']: ' . $e->getMessage());

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Koneksi database gagal [$kode]\n");
        exit(1);
    }

    // Buang keluaran halaman yang sempat tertulis supaya tidak bercampur dengan halaman error.
    while (ob_get_level() > 0) ob_end_clean();

    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 30');
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex');
    }

    // Popup formulir (?modal=1): isinya disisipkan ke dalam halaman induk lewat innerHTML,
    // jadi cukup potongan kecil — halaman penuh akan merusak tampilan halaman induk.
    if (!empty($_REQUEST['modal'])) {
        if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
        echo '<div class="alert alert-danger mb-0">Layanan sedang tidak dapat diakses. '
            . 'Silakan tutup jendela ini lalu coba lagi beberapa saat lagi. '
            . '<small class="d-block mt-1 opacity-75">Kode kejadian: ' . $kode . '</small></div>';
        exit;
    }

    $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $json = strpos($uri, '/api/') !== false
        || isset($_GET['data'])
        || stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') === 0
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

    if ($json) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'      => false,
            'success' => false,
            'code'    => 'DB_UNAVAILABLE',
            'message' => 'Layanan sedang tidak dapat diakses. Silakan coba lagi beberapa saat lagi.',
            'ref'     => $kode,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    $file = __DIR__ . '/../503.html';
    $html = is_file($file) ? (string) file_get_contents($file) : '';
    if ($html === '') {
        // Cadangan bila 503.html terhapus: tetap bukan teks polos tanpa konteks.
        $html = '<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>503</title><body style="font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;padding:24px;text-align:center">'
            . '<main><h1>Layanan sedang tidak dapat diakses</h1><p>Silakan coba lagi beberapa saat lagi.</p><p><small>Kode kejadian: {{KODE}}</small></p></main>';
    }
    echo str_replace('{{KODE}}', $kode, $html);
    exit;
}
