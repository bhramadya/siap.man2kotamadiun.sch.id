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
