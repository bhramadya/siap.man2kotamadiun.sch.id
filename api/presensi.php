<?php
/**
 * API: Simpan presensi (aksi final).
 * siswa_id TIDAK diambil dari body request, melainkan dari SESSION yang
 * sudah divalidasi sebelumnya oleh presensi/scan.php atau api/scan.php (mode=device).
 * Ini mencegah klien memalsukan siswa_id untuk presensi atas nama siswa lain.
 */

require_once __DIR__ . '/../includes/presensi_core.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$siswaId = (int) ($_SESSION['identified_siswa_id'] ?? 0);
$gateToken = clean($input['gate_token'] ?? ($_SESSION['gate_token'] ?? ''));
$deviceUuid = clean($input['device_uuid'] ?? '');
$lat = isset($input['latitude']) && $input['latitude'] !== null ? (float) $input['latitude'] : null;
$lng = isset($input['longitude']) && $input['longitude'] !== null ? (float) $input['longitude'] : null;

if (!$siswaId) {
    json_response(['success' => false, 'code' => 'NO_SESSION', 'message' => 'Sesi identifikasi tidak ditemukan. Silakan scan ulang.']);
}

$result = proses_simpan_presensi($siswaId, $gateToken, $deviceUuid, $lat, $lng);

// Bersihkan sesi identifikasi & gate SETELAH proses selesai, baik BERHASIL
// maupun GAGAL. Kalau tidak dibersihkan saat gagal, tombol "Kembali ke
// Presensi" bisa berperilaku tidak konsisten (kadang lompat langsung ke
// konfirmasi pakai data lama yg sudah terbukti tidak valid, kadang diminta
// scan ulang — tergantung kebetulan sudah berapa lama sejak scan gerbang).
// Dengan selalu dibersihkan, siswa SELALU kembali ke keadaan bersih dan
// diminta scan ulang QR yang benar (mis. kalau gagal krn salah pakai QR
// kelas lain, mereka perlu scan QR kelas/gerbang yang benar, bukan
// mengulang QR yang sama yang sudah pasti gagal lagi).
unset($_SESSION['identified_siswa_id'], $_SESSION['identified_via'], $_SESSION['gate_token'], $_SESSION['gate_verified_at']);

if ($result['success']) {
    $_SESSION['presensi_result'] = $result['data'] + ['message' => $result['message']];
}

json_response($result);