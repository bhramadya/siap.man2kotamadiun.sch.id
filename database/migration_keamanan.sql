-- =====================================================================
-- MIGRASI: PERBAIKAN KEAMANAN (wajib ganti password bawaan, dst.)
-- Aman dijalankan berulang (idempoten), di MySQL 8 maupun MariaDB. Pilih database aplikasi di
-- phpMyAdmin lalu Import file ini. Kolom yang sama juga dibuat otomatis oleh aplikasi bila akun
-- database punya hak ALTER; file ini untuk server yang tidak memberi hak tersebut.
-- =====================================================================

-- 1) users.must_change_password: 1 = semua halaman panel dialihkan ke Profil sampai password diganti.
SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'must_change_password');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Akun "admin" yang masih memakai hash bawaan admin123 dari database.sql langsung ditandai wajib ganti.
-- (Akun lain yang login dengan password bawaan juga otomatis ditandai saat login.)
UPDATE users SET must_change_password = 1
  WHERE password = '$2b$10$b41vg2ko8zqtu.jWTfT48eXiFP5YLFg8U8IxUn7HI76kcI5upq7Ha';

-- 2) settings.qr_kelas_permanen: instalasi LAMA diberi 1 (poster QR kelas yang sudah ditempel tetap berlaku).
--    Matikan di Pengaturan agar QR kelas memakai token bertanda waktu (HMAC). GPS TIDAK diubah oleh migrasi ini.
SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE settings ADD COLUMN qr_kelas_permanen TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'qr_kelas_permanen');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) settings.kunci_rahasia: kunci HMAC server bila app_secret tidak diisi (diisi otomatis oleh aplikasi).
SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE settings ADD COLUMN kunci_rahasia VARCHAR(128) NULL',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'kunci_rahasia');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) device_bindings.secret_hash: hash rahasia perangkat dari server (cookie HttpOnly). Binding lama berisi NULL
--    dan otomatis diikat ulang saat siswa presensi berikutnya (tidak perlu daftar ulang).
SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE device_bindings ADD COLUMN secret_hash CHAR(64) NULL DEFAULT NULL AFTER device_hash, ADD UNIQUE KEY uniq_device_secret (secret_hash)',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_bindings' AND COLUMN_NAME = 'secret_hash');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5) device_bindings.secret_aktif_at: NULL = rahasia baru menunggu konfirmasi (UUID masih diterima),
--    terisi = hanya cookie. Rahasia yang sudah ada sebelum kolom ini dianggap aktif.
SET @perlu_aktif := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_bindings' AND COLUMN_NAME = 'secret_aktif_at');
SET @sql := IF(@perlu_aktif,
    'ALTER TABLE device_bindings ADD COLUMN secret_aktif_at DATETIME NULL DEFAULT NULL AFTER secret_hash',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- Hanya saat kolom BARU dibuat (aman dijalankan ulang: baris "menunggu" tidak ikut diaktifkan).
UPDATE device_bindings SET secret_aktif_at = COALESCE(registered_at, NOW())
  WHERE @perlu_aktif = 1 AND secret_hash IS NOT NULL;

-- 6) settings.device_legacy_until: batas tanggal pengenalan perangkat lama lewat UUID saja (opsional).
--    Dibiarkan KOSONG (tanpa batas); sekolah menentukan sendiri tanggalnya di Pengaturan.
SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE settings ADD COLUMN device_legacy_until DATE NULL DEFAULT NULL',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'device_legacy_until');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
