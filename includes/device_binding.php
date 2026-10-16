<?php
/**
 * =====================================================================
 * MODUL DEVICE BINDING — SATU SISWA SATU PERANGKAT
 * =====================================================================
 * Modul ini TIDAK menerima input mentah "device_id" dari klien sebagai
 * bukti tunggal. Klien (JS) mengirim sebuah UUID instalasi yang
 * tersimpan persisten di localStorage (dibuat sekali oleh browser).
 * Server meng-hash UUID tersebut (SHA-256) untuk dibandingkan.
 *
 * PENTING: User-Agent SENGAJA TIDAK ikut di-hash. UA berubah otomatis setiap
 * Chrome / Android System WebView ter-update (mis. 154.0.8037.106 -> .107),
 * sehingga hash lama tidak cocok lagi dan perangkat yang sah dianggap
 * "belum terdaftar". UUID acak 122-bit sudah cukup sebagai identitas;
 * UA mudah dipalsukan sehingga tidak menambah keamanan nyata.
 * =====================================================================
 */

require_once __DIR__ . '/functions.php';

/** Versi skema hash. Naikkan bila rumus hash berubah lagi. */
const DEVICE_HASH_VERSI = 'v2';

/**
 * Membentuk device_hash HANYA dari UUID instalasi (kiriman klien) — stabil
 * walau browser/WebView ter-update. TIDAK memakai User-Agent.
 *
 * Self-healing: baris binding lama (skema v1 = UUID + User-Agent) otomatis
 * dimigrasikan ke hash v2 lewat kolom device_uuid, sekali per UUID per
 * request, sehingga perangkat yang sudah terdaftar tidak perlu daftar ulang.
 */
function build_device_hash(string $installUuid): string
{
    $hash = hash('sha256', DEVICE_HASH_VERSI . '|' . $installUuid);
    sinkronkan_hash_perangkat($installUuid, $hash);
    return $hash;
}

/**
 * Migrasi otomatis hash lama (v1) -> v2 untuk perangkat dengan UUID ini.
 * Aman dipanggil berulang: hanya mengubah baris yang hash-nya belum v2,
 * dan hanya berjalan sekali per UUID dalam satu request.
 */
function sinkronkan_hash_perangkat(string $installUuid, string $hashBaru): void
{
    static $sudah = [];
    if (isset($sudah[$installUuid]) || !is_valid_install_uuid($installUuid)) return;
    $sudah[$installUuid] = true;
    try {
        $stmt = getDB()->prepare(
            'UPDATE device_bindings SET device_hash = :baru
             WHERE device_uuid = :uuid AND device_hash <> :baru'
        );
        $stmt->execute([':baru' => $hashBaru, ':uuid' => $installUuid]);
    } catch (PDOException $e) {
        // Bentrok UNIQUE (sangat jarang) -> abaikan; jangan sampai memblokir presensi.
        error_log('sinkronkan_hash_perangkat: ' . $e->getMessage());
    }
}

/**
 * Validasi format UUID v4 sederhana supaya klien tidak bisa mengirim
 * string sembarangan yang terlalu pendek/panjang.
 */
function is_valid_install_uuid(string $uuid): bool
{
    return (bool) preg_match('/^[0-9a-fA-F-]{20,64}$/', $uuid);
}

/**
 * Ambil binding aktif milik seorang siswa (jika ada).
 */
function get_device_binding(int $siswaId): ?array
{
    $stmt = getDB()->prepare('SELECT * FROM device_bindings WHERE siswa_id = :sid LIMIT 1');
    $stmt->execute([':sid' => $siswaId]);
    return $stmt->fetch() ?: null;
}

/**
 * Cari binding berdasarkan device_hash (lintas siswa). Dipakai utk mengecek
 * apakah sebuah perangkat SUDAH dipakai siswa lain sebelum mengizinkannya
 * didaftarkan ke siswa baru — inilah kunci "1 siswa = 1 device" yang
 * sesungguhnya (bukan cuma dicek dari sisi siswa saja).
 */
function get_device_binding_by_hash(string $deviceHash): ?array
{
    $stmt = getDB()->prepare('SELECT * FROM device_bindings WHERE device_hash = :hash LIMIT 1');
    $stmt->execute([':hash' => $deviceHash]);
    return $stmt->fetch() ?: null;
}

/**
 * INTI ALUR DEVICE BINDING.
 *
 * Return array:
 *  [
 *    'allowed' => bool,
 *    'code'    => 'REGISTERED_NEW' | 'MATCH' | 'MISMATCH' | 'BLOCKED' | 'BINDING_DISABLED' | 'DEVICE_ALREADY_BOUND',
 *    'message' => string,
 *  ]
 */
function check_or_register_device(int $siswaId, string $installUuid): array
{
    $settings = get_settings();
    if (empty($settings['device_binding_aktif'])) {
        return ['allowed' => true, 'code' => 'BINDING_DISABLED', 'message' => 'Device binding tidak aktif.'];
    }

    $deviceHash = build_device_hash($installUuid);
    $ua = parse_user_agent($_SERVER['HTTP_USER_AGENT'] ?? '');
    $ip = get_client_ip();

    $binding = get_device_binding($siswaId);

    // --- Belum pernah ada device terdaftar untuk siswa ini ---
    if (!$binding) {
        // PENTING: cek dulu apakah device (hash) ini SUDAH dipakai siswa LAIN.
        // Tanpa cek ini, 1 perangkat bisa didaftarkan ke banyak siswa berbeda —
        // celah utama titip-absen yang device binding harusnya mencegah.
        $sudahDipakaiSiswaLain = get_device_binding_by_hash($deviceHash);
        if ($sudahDipakaiSiswaLain && (int) $sudahDipakaiSiswaLain['siswa_id'] !== $siswaId) {
            security_log(
                $siswaId, 'DEVICE_MISMATCH', $deviceHash,
                'Percobaan daftar device yang sudah terikat siswa ID ' . $sudahDipakaiSiswaLain['siswa_id'] . '.'
            );
            return [
                'allowed' => false, 'code' => 'DEVICE_ALREADY_BOUND',
                'message' => 'Perangkat ini sudah terdaftar untuk siswa lain. Satu perangkat hanya dapat digunakan oleh satu siswa. Hubungi admin jika ini keliru.',
            ];
        }

        // Mode "Wajib Kode Pasangan": Perangkat baru TIDAK didaftarkan otomatis; harus lewat kode dari admin.
        if (!empty($settings['device_wajib_kode'])) {
            return [
                'allowed' => false, 'code' => 'PAIRING_REQUIRED',
                'message' => 'Perangkat ini belum didaftarkan. Minta Kode Pasangan 6 angka ke admin, lalu daftarkan perangkat ini.',
            ];
        }

        try {
            $stmt = getDB()->prepare(
                'INSERT INTO device_bindings
                    (siswa_id, device_uuid, device_hash, device_name, browser, os, ip_address, status, registered_at, last_used_at)
                 VALUES
                    (:sid, :uuid, :hash, :name, :browser, :os, :ip, "ACTIVE", NOW(), NOW())'
            );
            $stmt->execute([
                ':sid'     => $siswaId,
                ':uuid'    => $installUuid,
                ':hash'    => $deviceHash,
                ':name'    => $ua['os'] . ' - ' . $ua['browser'],
                ':browser' => $ua['browser'],
                ':os'      => $ua['os'],
                ':ip'      => $ip,
            ]);
        } catch (PDOException $e) {
            // Jaring pengaman terakhir thd race condition (2 request nyaris
            // bersamaan lolos pengecekan di atas sebelum salah satu INSERT).
            // device_hash WAJIB punya UNIQUE index di database (lihat
            // database/database.sql / migration_kunci_device_hash.sql) —
            // tanpa itu, percobaan INSERT kedua bisa lolos begitu saja dan
            // 1 perangkat bisa kepakai 2 siswa berbeda.
            if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                security_log($siswaId, 'DEVICE_MISMATCH', $deviceHash, 'Race condition: device sudah keburu terikat siswa lain.');
                return [
                    'allowed' => false, 'code' => 'DEVICE_ALREADY_BOUND',
                    'message' => 'Perangkat ini sudah terdaftar untuk siswa lain. Satu perangkat hanya dapat digunakan oleh satu siswa.',
                ];
            }
            throw $e;
        }

        security_log($siswaId, 'DEVICE_REGISTERED', $deviceHash, 'Perangkat baru didaftarkan sebagai perangkat utama.');
        return ['allowed' => true, 'code' => 'REGISTERED_NEW', 'message' => 'Perangkat berhasil didaftarkan.'];
    }

    // --- Perangkat diblokir admin ---
    if ($binding['status'] === 'BLOCKED') {
        security_log($siswaId, 'DEVICE_BLOCKED', $deviceHash, 'Percobaan presensi dari device yang diblokir.');
        return ['allowed' => false, 'code' => 'BLOCKED', 'message' => 'Perangkat ini diblokir. Silakan hubungi administrator sekolah.'];
    }

    // --- Cocokkan hash ---
    if (hash_equals($binding['device_hash'], $deviceHash)) {
        $upd = getDB()->prepare(
            'UPDATE device_bindings SET last_used_at = NOW(), ip_address = :ip, status = "ACTIVE" WHERE id = :id'
        );
        $upd->execute([':ip' => $ip, ':id' => $binding['id']]);
        security_log($siswaId, 'DEVICE_MATCH', $deviceHash);
        return ['allowed' => true, 'code' => 'MATCH', 'message' => 'Perangkat sesuai.'];
    }

    // --- Tidak cocok: perangkat lain ---
    security_log($siswaId, 'DEVICE_MISMATCH', $deviceHash, 'Percobaan presensi dari perangkat yang tidak terdaftar.');
    return [
        'allowed' => false,
        'code'    => 'MISMATCH',
        'message' => 'Perangkat tidak terdaftar. Gunakan perangkat yang telah didaftarkan untuk presensi.',
    ];
}

/**
 * Reset device oleh admin. Mengosongkan binding sehingga siswa dapat
 * mendaftarkan perangkat baru pada presensi berikutnya.
 * Membatasi jumlah reset per bulan sesuai pengaturan.
 */
function reset_device(int $siswaId, int $adminId, string $reason): array
{
    $db = getDB();
    $settings = get_settings();
    $maxReset = (int)($settings['maks_reset_device_per_bulan'] ?? 2);

    $count = $db->prepare(
        'SELECT COUNT(*) AS c FROM device_reset_logs
         WHERE siswa_id = :sid AND created_at >= :awal_bulan
           AND (reason IS NULL OR reason NOT LIKE \'[MASSAL]%\')'
    );
    $count->execute([':sid' => $siswaId, ':awal_bulan' => date('Y-m-01 00:00:00')]);
    $used = (int) $count->fetch()['c'];

    if ($used >= $maxReset) {
        return ['success' => false, 'message' => "Batas reset perangkat bulan ini ({$maxReset}x) sudah tercapai."];
    }

    $binding = get_device_binding($siswaId);
    $oldHash = $binding['device_hash'] ?? null;

    $db->beginTransaction();
    try {
        $del = $db->prepare('DELETE FROM device_bindings WHERE siswa_id = :sid');
        $del->execute([':sid' => $siswaId]);

        $log = $db->prepare(
            'INSERT INTO device_reset_logs (siswa_id, old_device_hash, admin_id, reason, created_at)
             VALUES (:sid, :hash, :admin, :reason, NOW())'
        );
        $log->execute([':sid' => $siswaId, ':hash' => $oldHash, ':admin' => $adminId, ':reason' => $reason]);

        $db->commit();
        catat_aktivitas('RESET', 'device', 'Reset perangkat ' . nama_siswa_log($siswaId) . '. Alasan: ' . $reason);
        return ['success' => true, 'message' => 'Perangkat siswa berhasil direset.'];
    } catch (Exception $e) {
        $db->rollBack();
        error_log('Reset device error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Gagal mereset perangkat.'];
    }
}

/**
 * Tentukan sasaran reset massal dari nilai "kelas:X-A", "jenjang:XI", "manual", atau "terpilih".
 * Hanya siswa AKTIF yang punya perangkat terdaftar yang ikut.
 * Return null bila sasaran tidak valid (kelas/jenjang tidak ada).
 * Return: ['label' => 'kelas X-A', 'siswa_ids' => [..], 'diblokir' => n]
 */
function ambil_target_reset_massal(string $sasaran): ?array
{
    $db = getDB();
    $kelasAktif = $db->query("SELECT DISTINCT kelas FROM siswa WHERE status = 'aktif'")->fetchAll(PDO::FETCH_COLUMN);

    if ($sasaran === 'manual' || $sasaran === 'terpilih') {
        // Manual / terpilih dari tabel: semua siswa aktif berperangkat boleh dipilih lewat checklist.
        // Yang benar-benar diproses tetap hanya id yang dicentang (lihat panel/device.php).
        $cocok = fn(string $k): bool => true;
        $label = $sasaran === 'terpilih' ? 'siswa terpilih dari tabel' : 'pilihan manual';
    } elseif (strncmp($sasaran, 'kelas:', 6) === 0) {
        $kelas = trim(substr($sasaran, 6));
        if (!in_array($kelas, $kelasAktif, true)) return null;
        $cocok = fn(string $k): bool => $k === $kelas;
        $label = 'kelas ' . $kelas;
    } elseif (strncmp($sasaran, 'jenjang:', 8) === 0) {
        $jenjang = trim(substr($sasaran, 8));
        if (!in_array($jenjang, array_map('ambil_jenjang', $kelasAktif), true)) return null;
        $cocok = fn(string $k): bool => ambil_jenjang($k) === $jenjang;
        $label = 'jenjang ' . $jenjang;
    } else {
        return null;
    }

    $rows = $db->query(
        "SELECT s.id, s.kelas, db.status
         FROM siswa s JOIN device_bindings db ON db.siswa_id = s.id
         WHERE s.status = 'aktif'"
    )->fetchAll();

    $ids = [];
    $diblokir = 0;
    foreach ($rows as $r) {
        if (!$cocok((string) $r['kelas'])) continue;
        $ids[] = (int) $r['id'];
        if ($r['status'] === 'BLOCKED') $diblokir++;
    }
    return ['label' => $label, 'siswa_ids' => $ids, 'diblokir' => $diblokir];
}

/**
 * Reset perangkat secara massal (aksi administratif).
 * - Tidak memeriksa/menghabiskan batas reset bulanan siswa (log diberi awalan [MASSAL]).
 * - Perangkat berstatus BLOCKED dilewati kecuali $termasukBlokir = true, supaya
 *   pemblokiran yang disengaja tidak terhapus diam-diam.
 * - Semua dalam satu transaksi: gagal sebagian = tidak ada yang berubah.
 * Return: ['success' => bool, 'direset' => n, 'dilewati_blokir' => n, 'message' => '...']
 */
function reset_device_massal(array $siswaIds, int $adminId, string $reason, bool $termasukBlokir = false): array
{
    $siswaIds = array_values(array_unique(array_map('intval', $siswaIds)));
    if (!$siswaIds) {
        return ['success' => false, 'direset' => 0, 'dilewati_blokir' => 0, 'message' => 'Tidak ada perangkat terdaftar pada sasaran ini.'];
    }
    $alasanLog = mb_substr('[MASSAL] ' . $reason, 0, 255);
    $filterStatus = $termasukBlokir ? '' : " AND status <> 'BLOCKED'";

    $db = getDB();
    $direset = 0;
    $dilewati = 0;
    $db->beginTransaction();
    try {
        foreach (array_chunk($siswaIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));

            if (!$termasukBlokir) {
                $st = $db->prepare("SELECT COUNT(*) FROM device_bindings WHERE siswa_id IN ($in) AND status = 'BLOCKED'");
                $st->execute($chunk);
                $dilewati += (int) $st->fetchColumn();
            }

            $log = $db->prepare(
                "INSERT INTO device_reset_logs (siswa_id, old_device_hash, admin_id, reason, created_at)
                 SELECT siswa_id, device_hash, ?, ?, NOW() FROM device_bindings
                 WHERE siswa_id IN ($in)$filterStatus"
            );
            $log->execute(array_merge([$adminId, $alasanLog], $chunk));

            $del = $db->prepare("DELETE FROM device_bindings WHERE siswa_id IN ($in)$filterStatus");
            $del->execute($chunk);
            $direset += $del->rowCount();
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('Reset device massal error: ' . $e->getMessage());
        return ['success' => false, 'direset' => 0, 'dilewati_blokir' => 0, 'message' => 'Gagal mereset perangkat secara massal. Tidak ada data yang diubah.'];
    }

    return ['success' => true, 'direset' => $direset, 'dilewati_blokir' => $dilewati, 'message' => ''];
}

/**
 * Blokir / aktifkan kembali perangkat siswa (dipakai halaman admin).
 */
function set_device_status(int $siswaId, string $status): bool
{
    if (!in_array($status, ['ACTIVE', 'BLOCKED', 'TRUSTED'], true)) return false;
    $stmt = getDB()->prepare('UPDATE device_bindings SET status = :status WHERE siswa_id = :sid');
    $ok = $stmt->execute([':status' => $status, ':sid' => $siswaId]);
    if ($ok) {
        catat_aktivitas('UBAH', 'device', 'Status perangkat ' . nama_siswa_log($siswaId) . " diubah menjadi {$status}");
    }
    return $ok;
}

// =====================================================================
// PASANGAN PERANGKAT: dua cara mendaftarkan perangkat, keduanya dari admin
//   1. QR Pasangan  -> siswa scan QR dengan kamera perangkat (token panjang acak di dalam URL)
//   2. Kode 6 angka -> siswa mengetik kode saat konfirmasi presensi
// Satu pembuatan menghasilkan KEDUANYA; salah satu yang dipakai membuat yang lain hangus.
// Berlaku KODE_PASANGAN_MENIT menit, sekali pakai. Kode terkunci setelah
// KODE_PASANGAN_MAKS_SALAH kali salah. Yang disimpan hanya hash.
// =====================================================================
const KODE_PASANGAN_MENIT = 10;
const KODE_PASANGAN_MAKS_SALAH = 5;

function siapkan_tabel_kode_pasangan(): void
{
    static $sudah = false;
    if ($sudah) return;
    $sudah = true;
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS device_pairing_codes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        siswa_id INT UNSIGNED NOT NULL,
        code_hash CHAR(64) NOT NULL,
        token_hash CHAR(64) DEFAULT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        percobaan TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pair_siswa (siswa_id, used_at, expires_at),
        INDEX idx_pair_token (token_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Tabel versi lama (hanya kode 6 angka) belum punya kolom token_hash
    if (!$db->query("SHOW COLUMNS FROM device_pairing_codes LIKE 'token_hash'")->fetch()) {
        $db->exec("ALTER TABLE device_pairing_codes ADD COLUMN token_hash CHAR(64) DEFAULT NULL AFTER code_hash, ADD INDEX idx_pair_token (token_hash)");
    }
}

function hash_kode_pasangan(int $siswaId, string $kode): string
{
    return hash('sha256', $siswaId . '|' . $kode . '|pasangan');
}

function hash_token_pasangan(string $token): string
{
    return hash('sha256', 'token|' . $token);
}

/** Alamat yang dikodekan ke dalam QR Pasangan. */
function url_pasangan(string $token): string
{
    return BASE_URL . '/presensi/daftar_hp.php?t=' . $token;
}

/** Admin membuat pasangan baru (kode + token QR); yang lama belum terpakai dibatalkan. */
function buat_kode_pasangan(int $siswaId, int $adminId): array
{
    siapkan_tabel_kode_pasangan();
    $db = getDB();
    $db->prepare('UPDATE device_pairing_codes SET used_at = NOW() WHERE siswa_id = :s AND used_at IS NULL')->execute([':s' => $siswaId]);

    $kode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $token = bin2hex(random_bytes(16));
    $db->prepare(
        'INSERT INTO device_pairing_codes (siswa_id, code_hash, token_hash, expires_at, created_by)
         VALUES (:s, :h, :t, DATE_ADD(NOW(), INTERVAL ' . (int) KODE_PASANGAN_MENIT . ' MINUTE), :a)'
    )->execute([':s' => $siswaId, ':h' => hash_kode_pasangan($siswaId, $kode), ':t' => hash_token_pasangan($token), ':a' => $adminId ?: null]);

    $st = $db->prepare('SELECT expires_at FROM device_pairing_codes WHERE id = :id');
    $st->execute([':id' => $db->lastInsertId()]);
    return ['kode' => $kode, 'token' => $token, 'url' => url_pasangan($token), 'berlaku_sampai' => (string) $st->fetchColumn()];
}

/** Inti pendaftaran: dipakai oleh jalur kode maupun jalur QR (kode/token sudah tervalidasi). */
function _selesaikan_pendaftaran_pasangan(array $row, int $siswaId, string $installUuid, string $via): array
{
    $gagal = fn(string $code, string $msg) => ['success' => false, 'code' => $code, 'message' => $msg];
    $db = getDB();
    $deviceHash = build_device_hash($installUuid);

    $lain = get_device_binding_by_hash($deviceHash);
    if ($lain && (int) $lain['siswa_id'] !== $siswaId) {
        security_log($siswaId, 'DEVICE_MISMATCH', $deviceHash, 'Percobaan daftar (' . $via . ') di device milik siswa ID ' . $lain['siswa_id'] . '.');
        return $gagal('DEVICE_ALREADY_BOUND', 'Perangkat ini sudah terdaftar untuk siswa lain. Satu perangkat hanya untuk satu siswa.');
    }

    // Pakai kode/token secara atomik (cegah dipakai dua kali bersamaan)
    $pakai = $db->prepare('UPDATE device_pairing_codes SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
    $pakai->execute([':id' => $row['id']]);
    if ($pakai->rowCount() !== 1) return $gagal('KODE_INVALID', 'Kode/QR sudah dipakai. Minta admin membuat yang baru.');

    $ua = parse_user_agent($_SERVER['HTTP_USER_AGENT'] ?? '');
    try {
        $db->prepare(
            'INSERT INTO device_bindings
                (siswa_id, device_uuid, device_hash, device_name, browser, os, ip_address, status, registered_at, last_used_at)
             VALUES (:sid, :uuid, :hash, :name, :browser, :os, :ip, "ACTIVE", NOW(), NOW())'
        )->execute([
            ':sid' => $siswaId, ':uuid' => $installUuid, ':hash' => $deviceHash,
            ':name' => $ua['os'] . ' - ' . $ua['browser'], ':browser' => $ua['browser'], ':os' => $ua['os'],
            ':ip' => get_client_ip(),
        ]);
    } catch (PDOException $e) {
        if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
            return $gagal('DEVICE_ALREADY_BOUND', 'Perangkat ini sudah terdaftar untuk siswa lain.');
        }
        throw $e;
    }

    security_log($siswaId, 'DEVICE_REGISTERED', $deviceHash, 'Perangkat didaftarkan memakai ' . $via . '.');
    return ['success' => true, 'code' => 'PAIRED', 'message' => 'Perangkat berhasil didaftarkan.'];
}

/** Jalur 2 — kode 6 angka (siswa sudah teridentifikasi dari scan QR gerbang). */
function daftarkan_dengan_kode(int $siswaId, string $kode, string $installUuid): array
{
    $gagal = fn(string $code, string $msg) => ['success' => false, 'code' => $code, 'message' => $msg];
    $settings = get_settings();
    if (empty($settings['device_binding_aktif'])) {
        return $gagal('BINDING_DISABLED', 'Device binding tidak aktif, tidak perlu mendaftarkan perangkat.');
    }
    if (!is_valid_install_uuid($installUuid)) return $gagal('DEVICE_INVALID', 'Identitas perangkat tidak valid. Muat ulang halaman.');
    if (!preg_match('/^\d{6}$/', $kode)) return $gagal('KODE_FORMAT', 'Kode Pasangan harus 6 angka.');
    if (get_device_binding($siswaId)) {
        return $gagal('ALREADY_BOUND', 'Anda sudah memiliki perangkat terdaftar. Minta admin mereset perangkat bila ingin mengganti perangkat.');
    }

    siapkan_tabel_kode_pasangan();
    $db = getDB();
    $st = $db->prepare(
        'SELECT * FROM device_pairing_codes
         WHERE siswa_id = :s AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1'
    );
    $st->execute([':s' => $siswaId]);
    $row = $st->fetch();

    if (!$row || (int) $row['percobaan'] >= KODE_PASANGAN_MAKS_SALAH) {
        security_log($siswaId, 'DEVICE_PAIRING_FAILED', null, 'Kode pasangan tidak ada, kedaluwarsa, atau terkunci.');
        return $gagal('KODE_INVALID', 'Kode tidak berlaku. Minta admin membuat Kode Pasangan baru.');
    }
    if (!hash_equals($row['code_hash'], hash_kode_pasangan($siswaId, $kode))) {
        $db->prepare('UPDATE device_pairing_codes SET percobaan = percobaan + 1 WHERE id = :id')->execute([':id' => $row['id']]);
        $sisa = max(0, KODE_PASANGAN_MAKS_SALAH - ((int) $row['percobaan'] + 1));
        security_log($siswaId, 'DEVICE_PAIRING_FAILED', null, 'Kode pasangan salah (percobaan ke-' . ((int) $row['percobaan'] + 1) . ').');
        return $gagal('KODE_SALAH', $sisa > 0 ? "Kode salah. Sisa percobaan: $sisa." : 'Kode salah dan sudah terkunci. Minta admin membuat kode baru.');
    }
    return _selesaikan_pendaftaran_pasangan($row, $siswaId, $installUuid, 'Kode Pasangan');
}

/** Jalur 1 — QR Pasangan (siswa scan QR dari admin; token di URL mengidentifikasi siswa). */
function daftarkan_dengan_token(string $token, string $installUuid): array
{
    $gagal = fn(string $code, string $msg) => ['success' => false, 'code' => $code, 'message' => $msg];
    $settings = get_settings();
    if (empty($settings['device_binding_aktif'])) {
        return $gagal('BINDING_DISABLED', 'Device binding tidak aktif, tidak perlu mendaftarkan perangkat.');
    }
    if (!is_valid_install_uuid($installUuid)) return $gagal('DEVICE_INVALID', 'Identitas perangkat tidak valid. Muat ulang halaman.');
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) return $gagal('TOKEN_INVALID', 'QR Pasangan tidak valid.');

    siapkan_tabel_kode_pasangan();
    $db = getDB();
    $st = $db->prepare(
        'SELECT * FROM device_pairing_codes
         WHERE token_hash = :t AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
    );
    $st->execute([':t' => hash_token_pasangan($token)]);
    $row = $st->fetch();
    if (!$row) {
        security_log(null, 'DEVICE_PAIRING_FAILED', null, 'QR Pasangan tidak valid/kedaluwarsa/sudah dipakai.');
        return $gagal('TOKEN_INVALID', 'QR Pasangan tidak berlaku atau sudah dipakai. Minta admin membuat yang baru.');
    }

    $siswaId = (int) $row['siswa_id'];
    $ss = $db->prepare('SELECT nama, kelas, status FROM siswa WHERE id = :id');
    $ss->execute([':id' => $siswaId]);
    $siswa = $ss->fetch();
    if (!$siswa || $siswa['status'] !== 'aktif') return $gagal('INACTIVE', 'Status siswa tidak aktif. Hubungi admin sekolah.');
    if (get_device_binding($siswaId)) {
        return $gagal('ALREADY_BOUND', 'Siswa ini sudah memiliki perangkat terdaftar. Minta admin mereset perangkat bila ingin mengganti perangkat.');
    }

    $hasil = _selesaikan_pendaftaran_pasangan($row, $siswaId, $installUuid, 'QR Pasangan');
    if ($hasil['success']) $hasil['data'] = ['nama' => $siswa['nama'], 'kelas' => $siswa['kelas']];
    return $hasil;
}


/** Penanda "tanpa batas waktu" untuk kolom expires_at (tanpa perlu mengubah skema tabel). */
const KODE_TANPA_BATAS = '9999-12-31 23:59:59';

/**
 * Buat Kode Pasangan untuk banyak siswa sekaligus. $menit = null berarti TANPA batas waktu.
 * Tetap sekali pakai & terkunci setelah KODE_PASANGAN_MAKS_SALAH kali salah.
 * Kode lama yang belum terpakai untuk siswa-siswa tersebut dibatalkan.
 * @param int[] $siswaIds
 * @return array<int,string> siswa_id => kode 6 angka (hanya tampil sekali; di DB hanya hash)
 */
function buat_kode_pasangan_massal(array $siswaIds, int $adminId, ?int $menit): array
{
    $siswaIds = array_values(array_unique(array_map('intval', $siswaIds)));
    if (!$siswaIds) return [];
    siapkan_tabel_kode_pasangan();
    $db = getDB();
    $hasil = [];

    $db->beginTransaction();
    try {
        foreach (array_chunk($siswaIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $db->prepare("UPDATE device_pairing_codes SET used_at = NOW() WHERE used_at IS NULL AND siswa_id IN ($in)")->execute($chunk);
        }
        if ($menit === null) {
            $ins = $db->prepare('INSERT INTO device_pairing_codes (siswa_id, code_hash, token_hash, expires_at, created_by) VALUES (:s, :h, NULL, :e, :a)');
        } else {
            $ins = $db->prepare('INSERT INTO device_pairing_codes (siswa_id, code_hash, token_hash, expires_at, created_by) VALUES (:s, :h, NULL, DATE_ADD(NOW(), INTERVAL ' . (int) $menit . ' MINUTE), :a)');
        }
        foreach ($siswaIds as $id) {
            $kode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $par = [':s' => $id, ':h' => hash_kode_pasangan($id, $kode), ':a' => $adminId ?: null];
            if ($menit === null) $par[':e'] = KODE_TANPA_BATAS;
            $ins->execute($par);
            $hasil[$id] = $kode;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return $hasil;
}