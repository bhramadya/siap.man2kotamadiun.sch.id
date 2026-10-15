<?php
require_once __DIR__ . '/../includes/header.php';

// Hanya boleh lewat POST + token CSRF (sebelumnya GET, sehingga bisa dipicu dari halaman lain).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? '')) {
    set_flash('danger', 'Permintaan tidak valid. Silakan ulangi dari daftar siswa.');
    header('Location: ' . modul_url('siswa'));
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare('SELECT * FROM siswa WHERE id = :id');
$stmt->execute([':id' => $id]);
$siswa = $stmt->fetch();

if ($siswa) {
    // FK ON DELETE CASCADE menghapus presensi, device_bindings, dan reset log terkait otomatis.
    $del = $db->prepare('DELETE FROM siswa WHERE id = :id');
    $del->execute([':id' => $id]);

    if ($siswa['foto'] && file_exists(__DIR__ . '/../uploads/siswa/' . $siswa['foto'])) {
        @unlink(__DIR__ . '/../uploads/siswa/' . $siswa['foto']);
    }
    $qrFile = __DIR__ . '/../uploads/qr/' . $siswa['qr_token'] . '.png';
    if (file_exists($qrFile)) @unlink($qrFile);

    catat_aktivitas('HAPUS', 'siswa_hapus', 'Menghapus siswa ' . $siswa['nama'] . ' (NISN ' . (($siswa['nisn'] ?? '') ?: '-') . ', kelas ' . ($siswa['kelas'] ?? '') . ')');
    set_flash('success', 'Siswa "' . $siswa['nama'] . '" berhasil dihapus.');
} else {
    set_flash('danger', 'Siswa tidak ditemukan.');
}

header('Location: ' . modul_url('siswa'));
exit;