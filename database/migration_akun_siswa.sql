-- =====================================================================
-- MIGRASI: PORTAL SISWA (akun siswa + pembatas percobaan login portal)
-- Aman dijalankan berulang (idempoten). Pilih database aplikasi di phpMyAdmin lalu Import file ini.
-- Struktur SAMA PERSIS dengan yang dibuat otomatis oleh includes/portal.php
-- (portal_siapkan_tabel) dan includes/functions.php (get_settings). Jalankan bila akun
-- database aplikasi tidak punya hak CREATE/ALTER.
-- =====================================================================

CREATE TABLE IF NOT EXISTS siswa_akun (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  siswa_id INT UNSIGNED NOT NULL,
  password_hash VARCHAR(255) NULL,
  status ENUM('menunggu','aktif','nonaktif') NOT NULL DEFAULT 'menunggu',
  kode_hash VARCHAR(128) NULL,
  kode_salt VARCHAR(32) NULL,
  kode_berlaku_hingga DATETIME NULL,
  kode_salah INT UNSIGNED NOT NULL DEFAULT 0,
  kode_terkunci_hingga DATETIME NULL,
  sesi_versi INT UNSIGNED NOT NULL DEFAULT 1,
  dibuat_oleh INT UNSIGNED NULL,
  login_terakhir DATETIME NULL,
  password_diubah DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_akun_siswa (siswa_id),
  CONSTRAINT fk_akun_siswa FOREIGN KEY (siswa_id) REFERENCES siswa (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kunci VARCHAR(60) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_pa_kunci (kunci, created_at),
  INDEX idx_pa_ip (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kolom pengaturan "portal dibuka/ditutup" (1 = dibuka)
SET @sql := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE settings ADD COLUMN portal_siswa_aktif TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'portal_siswa_aktif');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
