-- =====================================================================
-- MIGRASI: PENGAJUAN IZIN / SAKIT + NOTIFIKASI PORTAL
-- Aman dijalankan berulang (idempoten). Jalankan SETELAH migration_akun_siswa.sql.
-- Struktur SAMA PERSIS dengan yang dibuat otomatis oleh includes/izin.php (izin_siapkan_tabel).
-- Jalankan bila akun database aplikasi tidak punya hak CREATE.
-- =====================================================================

CREATE TABLE IF NOT EXISTS izin_pengajuan (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  siswa_id INT UNSIGNED NOT NULL,
  jenis ENUM('izin','sakit') NOT NULL,
  tanggal_mulai DATE NOT NULL,
  tanggal_selesai DATE NOT NULL,
  alasan VARCHAR(500) NOT NULL,
  lampiran VARCHAR(80) NULL,
  lampiran_nama VARCHAR(120) NULL,
  lampiran_tipe VARCHAR(40) NULL,
  lampiran_dihapus_at DATETIME NULL,
  status ENUM('menunggu','disetujui','ditolak','dibatalkan','dicabut') NOT NULL DEFAULT 'menunggu',
  diajukan_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diproses_oleh INT UNSIGNED NULL,
  diproses_nama VARCHAR(100) NULL,
  diproses_at DATETIME NULL,
  catatan_petugas VARCHAR(300) NULL,
  hari_diterapkan SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_iz_status (status, diajukan_at),
  KEY idx_iz_siswa (siswa_id, tanggal_mulai),
  KEY idx_iz_tanggal (tanggal_mulai, tanggal_selesai),
  CONSTRAINT fk_izin_siswa FOREIGN KEY (siswa_id) REFERENCES siswa (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS izin_hari (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pengajuan_id INT UNSIGNED NOT NULL,
  tanggal DATE NOT NULL,
  hasil ENUM('diterapkan','sudah_ada','dipakai_hadir','dicabut') NOT NULL,
  UNIQUE KEY uniq_izin_hari (pengajuan_id, tanggal),
  CONSTRAINT fk_izin_hari FOREIGN KEY (pengajuan_id) REFERENCES izin_pengajuan (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_notifikasi (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  siswa_id INT UNSIGNED NOT NULL,
  jenis VARCHAR(30) NOT NULL,
  judul VARCHAR(120) NOT NULL,
  isi VARCHAR(400) NOT NULL,
  tautan VARCHAR(120) NULL,
  dibaca_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notif_siswa (siswa_id, dibaca_at, id),
  CONSTRAINT fk_notif_siswa FOREIGN KEY (siswa_id) REFERENCES siswa (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
