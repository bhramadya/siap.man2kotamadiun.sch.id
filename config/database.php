<?php

/**
 * Konfigurasi aplikasi + koneksi database (PDO).
 *
 * NILAI ASLI TIDAK DISIMPAN DI GIT. Salin config/database.example.php menjadi
 * config/database.local.php (file itu diabaikan git) lalu isi kredensial database,
 * BASE_URL, dan kunci rahasia server. Alternatif: variabel lingkungan SIAP_DB_HOST,
 * SIAP_DB_NAME, SIAP_DB_USER, SIAP_DB_PASS, SIAP_BASE_URL, SIAP_APP_SECRET,
 * SIAP_TRUSTED_PROXIES (dipisah koma).
 */

// File ini hanya untuk di-include, tidak untuk dibuka langsung dari browser.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

$__siapConfig = [];
if (is_file(__DIR__ . '/database.local.php')) {
    $__siapConfig = require __DIR__ . '/database.local.php';
    if (!is_array($__siapConfig)) $__siapConfig = [];
}

/** Ambil nilai konfigurasi: database.local.php -> variabel lingkungan -> bawaan. */
function siap_config_nilai(array $config, string $kunci, string $env, $bawaan)
{
    if (array_key_exists($kunci, $config) && $config[$kunci] !== null) return $config[$kunci];
    $v = getenv($env);
    return ($v !== false && $v !== '') ? $v : $bawaan;
}

define('DB_HOST', (string) siap_config_nilai($__siapConfig, 'db_host', 'SIAP_DB_HOST', 'localhost'));
define('DB_NAME', (string) siap_config_nilai($__siapConfig, 'db_name', 'SIAP_DB_NAME', 'siapabsen'));
define('DB_USER', (string) siap_config_nilai($__siapConfig, 'db_user', 'SIAP_DB_USER', 'root'));
define('DB_PASS', (string) siap_config_nilai($__siapConfig, 'db_pass', 'SIAP_DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

/**
 * Base URL aplikasi (dipakai untuk redirect, cookie portal, dan isi QR Code siswa), TANPA slash
 * di akhir, mis. https://siap.man2kotamadiun.sch.id atau http://localhost/siap.
 * Bila belum diisi, ditebak dari permintaan saat ini (dan dicatat di error_log) supaya
 * instalasi baru tidak diam-diam mengarah ke localhost. Untuk produksi WAJIB diisi.
 */
function siap_tebak_base_url(): string
{
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) return 'http://localhost';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $host  = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) $_SERVER['HTTP_HOST']);
    $path  = '';
    $root  = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $app   = realpath(dirname(__DIR__));
    if ($root && $app && strpos($app, $root) === 0) $path = str_replace('\\', '/', substr($app, strlen($root)));
    error_log('SIAP: BASE_URL belum diatur di config/database.local.php; memakai tebakan otomatis.');
    return ($https ? 'https' : 'http') . '://' . $host . $path;
}

$__siapBase = (string) siap_config_nilai($__siapConfig, 'base_url', 'SIAP_BASE_URL', '');
define('BASE_URL', rtrim($__siapBase !== '' ? $__siapBase : siap_tebak_base_url(), '/'));

// Kunci rahasia server (penandatangan QR kelas, dsb.). Bila kosong, dibuat otomatis dan
// disimpan di tabel settings (lihat kunci_rahasia_server() di includes/functions.php).
define('APP_SECRET', (string) siap_config_nilai($__siapConfig, 'app_secret', 'SIAP_APP_SECRET', ''));

// Reverse proxy tepercaya (IP atau CIDR). Hanya bila REMOTE_ADDR termasuk daftar ini, header
// X-Forwarded-For dibaca untuk menentukan IP klien. Kosong = selalu memakai REMOTE_ADDR.
$__siapProxy = siap_config_nilai($__siapConfig, 'trusted_proxies', 'SIAP_TRUSTED_PROXIES', []);
if (is_string($__siapProxy)) $__siapProxy = array_filter(array_map('trim', explode(',', $__siapProxy)));
define('TRUSTED_PROXIES', array_values((array) $__siapProxy));
unset($__siapConfig, $__siapBase, $__siapProxy);

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
