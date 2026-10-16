/**
<<<<<<< HEAD
 * Device UUID — dibuat SEKALI per instalasi browser/WebView dan disimpan
 * persisten agar sama di kunjungan berikutnya. Server hanya meng-hash UUID
 * ini (lihat includes/device_binding.php); User-Agent TIDAK ikut dihash,
 * jadi update Chrome/WebView tidak membuat perangkat dianggap baru.
 *
 * Penyimpanan ganda (localStorage + cookie 400 hari): bila salah satunya
 * hilang/dibersihkan, UUID dipulihkan dari yang lain.
 *
 * Catatan jujur: ini BUKAN hardware ID permanen. Jika siswa menghapus data
 * aplikasi/browser (localStorage DAN cookie), memakai mode incognito, ganti
 * browser, atau ganti perangkat, UUID baru akan dibuat dan perangkat dianggap
 * baru oleh sistem.
 */
function getOrCreateDeviceUuid() {
  const KEY = 'presensi_device_uuid';
  const UUID_RE = /^[0-9a-fA-F-]{20,64}$/;

  let ls = null, ck = null;
  try { ls = localStorage.getItem(KEY); } catch (e) {}
  try {
    const m = document.cookie.match(new RegExp('(?:^|; )' + KEY + '=([^;]*)'));
    ck = m ? decodeURIComponent(m[1]) : null;
  } catch (e) {}

  let uuid = (ls && UUID_RE.test(ls)) ? ls : ((ck && UUID_RE.test(ck)) ? ck : null);
  if (!uuid) {
    uuid = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : fallbackUuid();
  }

  // Tulis ulang ke kedua penyimpanan bila ada yang kosong/berbeda
  if (ls !== uuid) { try { localStorage.setItem(KEY, uuid); } catch (e) {} }
  if (ck !== uuid) {
    try {
      document.cookie = KEY + '=' + encodeURIComponent(uuid) +
        '; max-age=34560000; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    } catch (e) {}
=======
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
>>>>>>> 6bcabbc39289f07eb4e728fb4aa648439ed80733
  }
  return uuid;
}

function fallbackUuid() {
<<<<<<< HEAD
  // Gunakan CSPRNG bila ada; Math.random hanya cadangan terakhir
  const bytes = new Uint8Array(16);
  if (window.crypto && crypto.getRandomValues) crypto.getRandomValues(bytes);
  else for (let i = 0; i < 16; i++) bytes[i] = Math.random() * 256 | 0;
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const h = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
  return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
=======
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
    const r = Math.random() * 16 | 0;
    const v = c === 'x' ? r : (r & 0x3 | 0x8);
    return v.toString(16);
  });
>>>>>>> 6bcabbc39289f07eb4e728fb4aa648439ed80733
}