<?php
/**
 * ROUTER UTAMA: index.php?modul=nama_modul
 * Contoh: index.php?modul=siswa, index.php?modul=rekap&bulan=9
 */
define('APP_ROUTER', true);

// Tahan seluruh output sampai halaman selesai, supaya header()/redirect di
// halaman admin tetap bisa dipanggil walau header.php sudah menulis HTML.
ob_start();
require_once __DIR__ . '/includes/auth.php';

$modul = (string) ($_GET['modul'] ?? '');

if (!is_logged_in()) {
    if ($modul === '') {
        // Halaman depan publik: ringkasan kehadiran, histori realtime, scan absen & pendaftaran HP
        require __DIR__ . '/beranda.php';
        if (ob_get_level() > 0) ob_end_flush();
        exit;
    }
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if ($modul === '') {
    // Arahkan ke halaman pertama yang boleh diakses pengguna ini
    foreach (['dashboard', 'siswa', 'presensi', 'rekap', 'qr_siswa', 'profil'] as $m) {
        if (can_modul($m)) { header('Location: ' . modul_url($m)); exit; }
    }
    header('Location: ' . modul_url('profil'));
    exit;
}
if (!in_array($modul, DAFTAR_MODUL, true) || !is_file(__DIR__ . '/panel/' . $modul . '.php')) {
    http_response_code(404);
    readfile(__DIR__ . '/404.html');
    exit;
}

// Cek izin (role & hak akses)
$izinDiperlukan = MODUL_IZIN[$modul] ?? null;
if ($izinDiperlukan !== null && !can($izinDiperlukan)) {
    http_response_code(403);
    if (!empty($_REQUEST['modal'])) {
        echo '<div class="alert alert-danger mb-0">Anda tidak memiliki izin untuk aksi ini.</div>';
        exit;
    }
    readfile(__DIR__ . '/403.html');
    exit;
}

define('MODUL_FILE', $modul . '.php');
require __DIR__ . '/panel/' . $modul . '.php';

if (ob_get_level() > 0) ob_end_flush();