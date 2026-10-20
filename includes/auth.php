<?php
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; } // hanya untuk di-include
/**
 * Session bootstrap + guard akses admin.
 * Wajib di-include paling atas pada setiap halaman admin.
 */

// Header keamanan + parameter cookie sesi (httponly, samesite, secure bila HTTPS).
require_once __DIR__ . '/keamanan.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/functions.php';

function is_logged_in(): bool
{
    return !empty($_SESSION['admin_id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    // Akses langsung ke /panel/xxx diarahkan ke URL bersih modul tsb (GET saja).
    if (!defined('APP_ROUTER') && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        && strpos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/panel/') !== false) {
        $modul = basename($_SERVER['SCRIPT_NAME'], '.php');
        if (in_array($modul, DAFTAR_MODUL, true)) {
            parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $qp);
            unset($qp['modul']);
            header('Location: ' . modul_url($modul, $qp), true, 302);
            exit;
        }
    }
    // Regenerasi id sesi berkala untuk mencegah session fixation.
    if (empty($_SESSION['last_regen']) || (time() - $_SESSION['last_regen']) > 900) {
        session_regenerate_id(true);
        $_SESSION['last_regen'] = time();
    }

    // Akun sudah dihapus / dinonaktifkan -> sesi lama tidak boleh dipakai lagi.
    if (!current_admin()) {
        unset($_SESSION['admin_id']);
        session_regenerate_id(true);
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }

    // Password bawaan / wajib diganti -> semua halaman panel dialihkan ke Profil sampai password diganti.
    if (admin_wajib_ganti_password() && modul_saat_ini() !== 'profil') {
        if (!empty($_REQUEST['modal'])) {
            echo '<div class="alert alert-warning mb-0">Ganti password Anda terlebih dahulu di menu Profil.</div>';
            exit;
        }
        set_flash('warning', 'Demi keamanan, ganti password Anda terlebih dahulu sebelum memakai panel.');
        header('Location: ' . modul_url('profil'));
        exit;
    }

    // PENEGAKAN IZIN DI SEMUA JALUR. Sebelumnya izin hanya dicek di index.php (router),
    // sehingga POST langsung ke /panel/xxx.php melewati pengecekan izin role.
    $modul = modul_saat_ini();
    if ($modul !== null) {
        if (!in_array($modul, DAFTAR_MODUL, true)) tolak_akses();
        $izin = MODUL_IZIN[$modul] ?? null;
        if ($izin !== null && !can($izin)) tolak_akses();
    }
}

/** Nama modul yang sedang dijalankan (via router atau akses langsung ke /panel/xxx.php), null bila bukan halaman panel. */
function modul_saat_ini(): ?string
{
    if (defined('MODUL_FILE')) return basename((string) MODUL_FILE, '.php');
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (strpos($script, '/panel/') !== false) return basename($script, '.php');
    return null;
}

/** Hentikan request dengan 403 (format menyesuaikan: popup/modal atau halaman penuh). */
function tolak_akses(): void
{
    http_response_code(403);
    if (!empty($_REQUEST['modal'])) {
        echo '<div class="alert alert-danger mb-0">Anda tidak memiliki izin untuk aksi ini.</div>';
        exit;
    }
    $f = __DIR__ . '/../403.html';
    if (is_file($f)) readfile($f); else echo 'Akses ditolak.';
    exit;
}

function current_admin(): ?array
{
    if (!is_logged_in()) return null;
    static $admin = null;
    if ($admin === null) {
        $stmt = getDB()->prepare('SELECT id, nama, username, role FROM users WHERE id = :id AND status = \'aktif\' LIMIT 1');
        $stmt->execute([':id' => $_SESSION['admin_id']]);
        $admin = $stmt->fetch() ?: null;
    }
    return $admin;
}

// ---------------------------------------------------------------------
// WAJIB GANTI PASSWORD (akun bawaan admin/admin123)
// Kolom users.must_change_password dibuat otomatis; SQL manual: database/migration_keamanan.sql.
// ---------------------------------------------------------------------
function siapkan_kolom_wajib_ganti_password(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db = getDB();
        if (!$db->query("SHOW COLUMNS FROM users LIKE 'must_change_password'")->fetch()) {
            $db->exec("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
        }
        return $ok = true;
    } catch (Throwable $e) {
        error_log('siapkan_kolom_wajib_ganti_password: ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * Apakah pengguna yang sedang login wajib mengganti password? Sumber: kolom users.must_change_password,
 * atau penanda sesi bila kolom itu tidak bisa dibuat (akun database tanpa hak ALTER dan
 * migration_keamanan.sql belum dijalankan) — supaya paksaan ganti password tidak diam-diam mati.
 */
function admin_wajib_ganti_password(): bool
{
    if (!is_logged_in()) return false;
    if (!empty($_SESSION['wajib_ganti_password'])) return true;
    try {
        $st = getDB()->prepare('SELECT must_change_password FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $_SESSION['admin_id']]);
        return (int) $st->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

function tandai_wajib_ganti_password(int $userId, bool $wajib): void
{
    $tersimpan = false;
    if (siapkan_kolom_wajib_ganti_password()) {
        try {
            getDB()->prepare('UPDATE users SET must_change_password = :w WHERE id = :id')
                   ->execute([':w' => $wajib ? 1 : 0, ':id' => $userId]);
            $tersimpan = true;
        } catch (Throwable $e) {
            error_log('tandai_wajib_ganti_password: ' . $e->getMessage());
        }
    }
    // Cadangan: penanda di sesi (hanya untuk pengguna yang sedang login).
    if (!$tersimpan && $wajib && (int) ($_SESSION['admin_id'] ?? 0) === $userId) {
        $_SESSION['wajib_ganti_password'] = true;
        error_log('SIAP: kolom users.must_change_password tidak tersedia; jalankan database/migration_keamanan.sql. Paksaan ganti password memakai sesi.');
    }
    if (!$wajib) unset($_SESSION['wajib_ganti_password']);
}

/** Password bawaan yang tidak boleh dipakai lagi. */
const PASSWORD_BAWAAN = ['admin123', 'admin', 'password', '123456', '12345678'];

// ---------------------------------------------------------------------
// HAK AKSES (role & permission)
// ---------------------------------------------------------------------

/** Menyiapkan tabel role_permissions & kolom role (sekali per sesi). */
function siapkan_hak_akses(): void
{
    if (!empty($_SESSION['hak_akses_siap'])) return;
    $db = getDB();
    try {
        $kol = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
        if ($kol && stripos((string) $kol['Type'], 'enum(') === 0 && stripos((string) $kol['Type'], 'operator') === false) {
            $db->exec("ALTER TABLE users MODIFY role VARCHAR(30) NOT NULL DEFAULT 'operator'");
        }
        $ada = (bool) $db->query("SHOW TABLES LIKE 'role_permissions'")->fetchColumn();
        if (!$ada) {
            $db->exec("CREATE TABLE role_permissions (
                role VARCHAR(30) NOT NULL,
                izin VARCHAR(60) NOT NULL,
                PRIMARY KEY (role, izin)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $ins = $db->prepare('INSERT INTO role_permissions (role, izin) VALUES (:r, :i)');
            foreach (IZIN_DEFAULT_OPERATOR as $i) $ins->execute([':r' => 'operator', ':i' => $i]);
        }
        // Migrasi: izin QR lama (qr.lihat/cetak/generate) dipecah menjadi QR Siswa, QR Gerbang, dan QR Kelas
        $peta = [
            'qr.lihat'    => ['qr_siswa.lihat', 'qr_gerbang.lihat', 'qr_kelas.lihat'],
            'qr.cetak'    => ['qr_siswa.cetak', 'qr_kelas.cetak'],
            'qr.generate' => ['qr_siswa.generate', 'qr_kelas.generate'],
        ];
        $lama = $db->query("SELECT role, izin FROM role_permissions WHERE izin IN ('qr.lihat','qr.cetak','qr.generate')")->fetchAll();
        if ($lama) {
            $ins = $db->prepare('INSERT IGNORE INTO role_permissions (role, izin) VALUES (:r, :i)');
            foreach ($lama as $row) {
                foreach ($peta[$row['izin']] as $baru) $ins->execute([':r' => $row['role'], ':i' => $baru]);
            }
            $db->exec("DELETE FROM role_permissions WHERE izin IN ('qr.lihat','qr.cetak','qr.generate')");
        }
        // Migrasi sekali: izin baru "papan.lihat" untuk role yang sudah boleh melihat presensi
        $sudahPapan = (bool) $db->query("SELECT 1 FROM role_permissions WHERE role = '_meta' AND izin = 'migrasi_papan' LIMIT 1")->fetchColumn();
        if (!$sudahPapan) {
            $db->exec("INSERT IGNORE INTO role_permissions (role, izin) SELECT role, 'papan.lihat' FROM role_permissions WHERE izin = 'presensi.lihat'");
            $db->exec("INSERT IGNORE INTO role_permissions (role, izin) VALUES ('_meta', 'migrasi_papan')");
        }
        $_SESSION['hak_akses_siap'] = true;
    } catch (Throwable $e) {
        error_log('siapkan_hak_akses: ' . $e->getMessage());
    }
}

/** Himpunan izin milik pengguna yang sedang login. ['*' => true] untuk admin. */
function izin_pengguna(): array
{
    static $set = null;
    if ($set !== null) return $set;
    $set = [];
    $admin = current_admin();
    if (!$admin) return $set;
    if (($admin['role'] ?? '') === 'admin') return $set = ['*' => true];

    siapkan_hak_akses();
    try {
        $st = getDB()->prepare('SELECT izin FROM role_permissions WHERE role = :r');
        $st->execute([':r' => $admin['role']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $i) $set[$i] = true;
    } catch (Throwable $e) {
        foreach (IZIN_DEFAULT_OPERATOR as $i) $set[$i] = true;
    }
    return $set;
}

function can(string $izin): bool
{
    $set = izin_pengguna();
    if (isset($set['*'])) return true;
    if ($izin === 'pengguna.kelola') return false; // khusus administrator
    return isset($set[$izin]);
}

function can_modul(string $modul): bool
{
    $izin = MODUL_IZIN[$modul] ?? null;
    return $izin === null || can($izin);
}

function can_any_modul(array $moduls): bool
{
    foreach ($moduls as $m) if (can_modul($m)) return true;
    return false;
}
