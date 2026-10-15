/**
 * Device UUID — dibuat SEKALI per instalasi browser, disimpan di localStorage
 * agar tetap sama di kunjungan berikutnya (persisten selama data browser
 * tidak dihapus). UUID mentah ini TIDAK PERNAH dipakai sebagai bukti tunggal:
 * server akan meng-hash-nya bersama User-Agent (lihat includes/device_binding.php).
 *
 * Catatan jujur: ini BUKAN hardware ID permanen. Jika siswa menghapus data
 * browser, memakai mode incognito, ganti browser, atau ganti perangkat,
 * UUID baru akan dibuat dan perangkat dianggap baru oleh sistem.
 */
function getOrCreateDeviceUuid() {
  const KEY = 'presensi_device_uuid';
  let uuid = localStorage.getItem(KEY);
  if (!uuid) {
    uuid = (crypto.randomUUID ? crypto.randomUUID() : fallbackUuid());
    localStorage.setItem(KEY, uuid);
  }
  return uuid;
}

function fallbackUuid() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
    const r = Math.random() * 16 | 0;
    const v = c === 'x' ? r : (r & 0x3 | 0x8);
    return v.toString(16);
  });
}