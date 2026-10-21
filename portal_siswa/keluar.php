<?php
if (!defined('APP_ROUTER')) { http_response_code(404); exit; } // hanya lewat router (index.php -> portal_siswa/router.php)
// /portal/keluar — hanya POST + CSRF (agar tidak bisa dipicu dari situs lain lewat gambar/tautan)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { header('Location: ' . portal_url()); exit; }
if (csrf_verify($_POST['csrf_token'] ?? '')) {
    portal_keluar_sesi();
    header('Location: ' . portal_login_url(['keluar' => 1]));
    exit;
}
header('Location: ' . portal_url());
exit;
