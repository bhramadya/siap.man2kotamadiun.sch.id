<?php
/**
 * API publik: apakah perangkat/browser ini sudah terdaftar sebagai perangkat siswa?
 * Body: {"device_uuid": "..."}. Hanya mengembalikan status + nama yang disamarkan.
 */
require_once __DIR__ . '/../includes/presensi_core.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $uuid = clean($input['device_uuid'] ?? '');
    $settings = get_settings();

    if (empty($settings['device_binding_aktif'])) {
        json_response(['success' => true, 'wajib' => false, 'terdaftar' => true, 'status' => 'TIDAK_WAJIB']);
    }
    if (!is_valid_install_uuid($uuid)) {
        json_response(['success' => false, 'message' => 'Identitas perangkat tidak valid.']);
    }

    $b = cari_binding_perangkat($uuid);
    migrasi_pasif_perangkat($b, $uuid);   // binding lama -> rahasia server (status menunggu)
    if (!$b) {
        json_response(['success' => true, 'wajib' => true, 'terdaftar' => false, 'status' => 'BELUM']);
    }


    json_response([
        'success' => true, 'wajib' => true,
        'terdaftar' => $b['status'] === 'ACTIVE',
        'status' => $b['status'] === 'ACTIVE' ? 'AKTIF' : 'DIBLOKIR',
    ]);
} catch (Throwable $e) {
    error_log('api/cek_perangkat: ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'Terjadi kesalahan server.'], 500);
}