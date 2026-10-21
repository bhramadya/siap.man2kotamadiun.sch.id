<?php
if (!defined('APP_ROUTER')) { header('Location: ../index.php?modul=' . basename(__FILE__, '.php')); exit; } // hanya lewat router (index.php)
// Endpoint kecil (tanpa tampilan): mencatat aksi CETAK dari browser saat dialog cetak dibuka.
require_once __DIR__ . '/../includes/auth.php';
require_login();
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}
csrf_require();

$modul = (string) ($_POST['modul'] ?? '');
if (!in_array($modul, DAFTAR_MODUL, true)) $modul = 'lainnya';

$ket = 'Mencetak: ' . mb_substr(clean($_POST['judul'] ?? ''), 0, 120);
$id = (int) ($_POST['id'] ?? 0);
$kelas = mb_substr(clean($_POST['kelas'] ?? ''), 0, 50);
if ($id > 0) $ket .= ' — ' . nama_siswa_log($id);
if ($kelas !== '') $ket .= ' — kelas ' . $kelas;

catat_aktivitas('CETAK', $modul, $ket);
echo json_encode(['success' => true]);