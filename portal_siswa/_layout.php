<?php
if (!defined('APP_ROUTER')) { http_response_code(404); exit; } // hanya lewat router (index.php -> portal_siswa/router.php)
/** Kerangka HTML portal siswa (dipakai semua halaman portal). */

function portal_head(string $judul): void
{
    $s = get_settings();
    ?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#114e2e">
<title><?= e($judul) ?> — Portal Siswa <?= e($s['nama_sekolah'] ?? '') ?></title>
<?= favicon_tag() ?>
<link href="<?= BASE_URL ?>/assets/css/vendor/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/portal.css" rel="stylesheet">
</head>
<?php
}

/** Halaman masuk/aktivasi: panel merek + panel formulir. */
function portal_auth_awal(string $judul, string $heading, string $sub = '', bool $tombolKembali = true): void
{
    $s = get_settings();
    $nama = (string) ($s['nama_sekolah'] ?? 'Sekolah');
    $logo = portal_logo_url();
    portal_head($judul);
    ?>
<body class="pt-body">
<main class="pt-auth">
  <section class="pt-brand">
    <?php if ($tombolKembali): ?><a class="pt-back" href="<?= BASE_URL ?>/" aria-label="Kembali ke beranda"><i class="bi bi-chevron-left"></i></a><?php endif; ?>
    <div class="pt-logo"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><i class="bi bi-person-badge"></i><?php endif; ?></div>
    <h2>Portal Siswa</h2>
    <p class="pt-sekolah"><?= e($nama) ?></p>
    <p class="pt-uraian">Layanan mandiri siswa <?= e($nama) ?>. Pantau kehadiran Anda dan, segera, ajukan izin atau sakit langsung dari sini.</p>
    <div class="pt-garis"></div>
  </section>
  <section class="pt-form">
    <div class="pt-form-in">
      <h1><?= e($heading) ?></h1>
      <p class="pt-sub"><?= e($sub) ?></p>
<?php
}

function portal_auth_akhir(): void
{
    $s = get_settings();
    ?>
      <p class="pt-hak"><?= e($s['nama_sekolah'] ?? '') ?> · SIAP ABSEN © <?= date('Y') ?></p>
    </div>
  </section>
</main>
<script src="<?= BASE_URL ?>/assets/js/portal.js"></script>
</body>
</html>
<?php
}

function portal_alert(string $jenis, string $pesan): void
{
    $ikon = ['err' => 'exclamation-octagon-fill', 'ok' => 'check-circle-fill', 'info' => 'info-circle-fill'][$jenis] ?? 'info-circle-fill';
    echo '<div class="pt-alert ' . e($jenis) . '" role="alert"><i class="bi bi-' . $ikon . '"></i><span>' . e($pesan) . '</span></div>';
}

/** Halaman pesan sederhana (portal ditutup / belum siap / tidak ditemukan). */
function portal_halaman_pesan(int $kode, string $judul, string $pesan): void
{
    http_response_code($kode);
    portal_auth_awal($judul, $judul, '');
    portal_alert('info', $pesan);
    echo '<a class="pt-btn garis" href="' . e(BASE_URL) . '/"><i class="bi bi-house"></i> Ke beranda</a>';
    portal_auth_akhir();
    exit;
}

/** Halaman setelah masuk: bilah atas, navigasi, dan tombol keluar (POST + CSRF). $aktif: beranda|izin|notifikasi|akun */
function portal_app_awal(string $judul, array $akun, string $aktif = 'beranda'): void
{
    $s = get_settings();
    $logo = portal_logo_url();
    $baru = portal_notif_belum_dibaca((int) $akun['siswa_id']);
    $menu = ['beranda' => ['', 'Beranda'], 'izin' => ['izin', 'Izin / Sakit'], 'notifikasi' => ['notifikasi', 'Notifikasi'], 'akun' => ['password', 'Akun']];
    portal_head($judul);
    ?>
<body class="pt-body pt-app">
<header class="pt-top">
  <div class="pt-top-in">
    <div class="pt-mini"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><i class="bi bi-person-badge"></i><?php endif; ?></div>
    <div>
      <div class="t1">Portal Siswa</div>
      <div class="t2"><?= e($s['nama_sekolah'] ?? '') ?></div>
    </div>
    <form method="post" action="<?= e(portal_url('keluar')) ?>">
      <?= csrf_field() ?>
      <button class="pt-keluar" type="submit"><i class="bi bi-box-arrow-right"></i> Keluar</button>
    </form>
  </div>
  <nav class="pt-nav" aria-label="Menu portal">
    <?php foreach ($menu as $k => [$sub, $label]): ?>
      <a href="<?= e(portal_url($sub)) ?>" class="<?= $aktif === $k ? 'on' : '' ?>"<?= $aktif === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?><?php if ($k === 'notifikasi' && $baru > 0): ?><span class="pt-dot" aria-label="<?= $baru ?> belum dibaca"><?= $baru > 99 ? '99+' : $baru ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  </nav>
</header>
<main class="pt-wrap">
<?php
}

function portal_app_akhir(): void
{
    ?>
</main>
<script src="<?= BASE_URL ?>/assets/js/portal.js"></script>
</body>
</html>
<?php
}
