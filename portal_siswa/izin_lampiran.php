<?php
if (!defined('APP_ROUTER')) { http_response_code(404); exit; } // hanya lewat router (index.php -> portal_siswa/router.php)
// /portal/izin/{id}/lampiran — hanya pemilik pengajuan
$akun = portal_wajib_login();
$row = izin_ambil($izinId);
if (!$row || (int) $row['siswa_id'] !== (int) $akun['siswa_id'] || !$row['lampiran']) portal_halaman_pesan(404, 'Lampiran tidak ditemukan', 'Lampiran yang Anda cari tidak ada.');
izin_stream_lampiran($row);
