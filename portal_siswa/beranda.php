<?php
// /portal — beranda siswa: profil, kehadiran bulan ini, riwayat, perangkat
require_once __DIR__ . '/../includes/device_binding.php';
$akun = portal_wajib_login();
$db = getDB();
$sid = (int) $akun['siswa_id'];

$info = (string) ($_SESSION['portal_info'] ?? '');
unset($_SESSION['portal_info']);

// Rekap bulan berjalan
$awalBulan = date('Y-m-01');
$st = $db->prepare('SELECT status, COUNT(*) c FROM presensi WHERE siswa_id = :id AND tanggal >= :awal GROUP BY status');
$st->execute([':id' => $sid, ':awal' => $awalBulan]);
$rekap = ['HADIR' => 0, 'TERLAMBAT' => 0, 'IZIN' => 0, 'SAKIT' => 0, 'ALPA' => 0];
foreach ($st->fetchAll() as $r) if (isset($rekap[$r['status']])) $rekap[$r['status']] = (int) $r['c'];

// 10 presensi terakhir
$st = $db->prepare('SELECT tanggal, jam_masuk, status, metode FROM presensi WHERE siswa_id = :id ORDER BY tanggal DESC, id DESC LIMIT 10');
$st->execute([':id' => $sid]);
$riwayat = $st->fetchAll();

$binding = get_device_binding($sid);

$kata = preg_split('/\s+/', trim((string) $akun['nama']));
$inisial = mb_strtoupper(mb_substr($kata[0] ?? '', 0, 1) . (isset($kata[1]) ? mb_substr($kata[1], 0, 1) : ''));
$namaDepan = mb_convert_case(mb_strtolower($kata[0] ?? ''), MB_CASE_TITLE, 'UTF-8');
$labelStatus = ['HADIR' => 'Hadir', 'TERLAMBAT' => 'Terlambat', 'IZIN' => 'Izin', 'SAKIT' => 'Sakit', 'ALPA' => 'Alpa'];
$namaBulan = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'][(int) date('n')];

// Pengajuan terakhir & notifikasi baru
$st = $db->prepare('SELECT id, jenis, tanggal_mulai, tanggal_selesai, status FROM izin_pengajuan WHERE siswa_id = :id ORDER BY id DESC LIMIT 3');
$st->execute([':id' => $sid]);
$izinTerakhir = $st->fetchAll();
$notifBaru = portal_notif_belum_dibaca($sid);

portal_app_awal('Beranda', $akun, 'beranda');
if ($info !== '') portal_alert('ok', $info);
if ($notifBaru > 0): ?>
<a class="pt-banner" href="<?= e(portal_url('notifikasi')) ?>"><i class="bi bi-bell-fill"></i><span>Anda punya <?= $notifBaru ?> notifikasi baru tentang pengajuan Anda.</span></a>
<?php endif; ?>
<section class="pt-card">
  <div class="pt-profil">
    <?php if ($akun['foto'] && is_file(__DIR__ . '/../uploads/siswa/' . $akun['foto'])): ?>
      <img class="pt-avatar" src="<?= e(BASE_URL . '/uploads/siswa/' . rawurlencode($akun['foto'])) ?>" alt="">
    <?php else: ?>
      <span class="pt-avatar"><?= e($inisial) ?></span>
    <?php endif; ?>
    <div>
      <div class="m">Halo,</div>
      <h2><?= e($namaDepan) ?> 👋</h2>
      <div>
        <span class="pt-chip">Kelas <?= e($akun['kelas']) ?></span>
        <span class="pt-chip">NISN <?= e($akun['nisn']) ?></span>
        <?php if ($akun['jurusan']): ?><span class="pt-chip"><?= e($akun['jurusan']) ?></span><?php endif; ?>
      </div>
    </div>
  </div>
</section>

<section class="pt-card">
  <h3>Kehadiran <?= e($namaBulan) ?></h3>
  <div class="pt-stat">
    <div class="h"><b><?= $rekap['HADIR'] ?></b><span>Hadir</span></div>
    <div class="t"><b><?= $rekap['TERLAMBAT'] ?></b><span>Terlambat</span></div>
    <div class="i"><b><?= $rekap['IZIN'] ?></b><span>Izin</span></div>
    <div class="s"><b><?= $rekap['SAKIT'] ?></b><span>Sakit</span></div>
    <div class="a"><b><?= $rekap['ALPA'] ?></b><span>Alpa</span></div>
  </div>
</section>

<section class="pt-card">
  <h3>Riwayat terakhir</h3>
  <?php if ($riwayat): ?>
  <ul class="pt-list">
    <?php foreach ($riwayat as $r): ?>
    <li>
      <span><?= e(format_tanggal_indonesia($r['tanggal'])) ?><br><small><?= $r['metode'] === 'PENGAJUAN' ? 'Pengajuan disetujui' : 'Masuk ' . e(substr((string) $r['jam_masuk'], 0, 5)) ?></small></span>
      <span class="pt-badge <?= e($r['status']) ?>"><?= e($labelStatus[$r['status']] ?? $r['status']) ?></span>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?>
    <p class="pt-kosong">Belum ada data presensi.</p>
  <?php endif; ?>
</section>

<section class="pt-card">
  <h3>Perangkat presensi</h3>
  <?php if ($binding): ?>
    <ul class="pt-list">
      <li><span>Perangkat<br><small><?= e(trim(($binding['os'] ?: '-') . ' · ' . ($binding['browser'] ?: '-'))) ?></small></span>
          <span class="pt-badge <?= $binding['status'] === 'BLOCKED' ? 'ALPA' : 'HADIR' ?>"><?= $binding['status'] === 'BLOCKED' ? 'Diblokir' : 'Terdaftar' ?></span></li>
      <li><span>Terakhir dipakai</span><small><?= e($binding['last_used_at'] ?: '-') ?></small></li>
    </ul>
    <p class="pt-kosong" style="margin-top:8px">Ganti HP atau perangkat tidak terdaftar? Hubungi petugas sekolah.</p>
  <?php else: ?>
    <p class="pt-kosong">Belum ada perangkat terdaftar. Perangkat akan terdaftar saat Anda presensi pertama kali.</p>
  <?php endif; ?>
</section>

<section class="pt-card">
  <div class="pt-judul-halaman" style="margin-bottom:10px">
    <h3 style="margin:0">Izin &amp; sakit</h3>
    <a class="pt-btn kecil" href="<?= e(portal_url('izin/baru')) ?>"><i class="bi bi-plus-lg"></i> Ajukan</a>
  </div>
  <?php if ($izinTerakhir): ?>
  <ul class="pt-list">
    <?php foreach ($izinTerakhir as $z): ?>
    <li>
      <a href="<?= e(portal_url('izin/' . (int) $z['id'])) ?>" style="color:inherit;text-decoration:none">
        <?= e(izin_teks_rentang($z['tanggal_mulai'], $z['tanggal_selesai'])) ?><br><small><?= e(izin_label_jenis($z['jenis'])) ?></small></a>
      <span class="pt-st <?= e($z['status']) ?>"><?= e(IZIN_STATUS_LABEL[$z['status']] ?? $z['status']) ?></span>
    </li>
    <?php endforeach; ?>
  </ul>
  <p style="margin:8px 0 0"><a href="<?= e(portal_url('izin')) ?>" style="font-size:13px;font-weight:600">Lihat semua pengajuan &rarr;</a></p>
  <?php else: ?>
    <p class="pt-kosong">Tidak masuk sekolah? Ajukan izin atau sakit langsung dari sini.</p>
  <?php endif; ?>
</section>
<?php portal_app_akhir();
