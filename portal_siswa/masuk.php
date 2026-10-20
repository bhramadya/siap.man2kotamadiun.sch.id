<?php
if (!defined('APP_ROUTER')) { http_response_code(404); exit; } // hanya lewat router (index.php -> portal_siswa/router.php)
// /portal/masuk — login siswa (username = NISN)
if (portal_siswa_sekarang()) { header('Location: ' . portal_url()); exit; }

$galat = '';
$nisn = '';
$info = (string) ($_SESSION['portal_info'] ?? '');
$nisnAwal = (string) ($_SESSION['portal_nisn'] ?? '');
unset($_SESSION['portal_info'], $_SESSION['portal_nisn']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $nisn = portal_nisn((string) ($_POST['nisn'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $galat = 'Sesi halaman kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif ($nisn === '' || $password === '') {
        $galat = 'Isi NISN dan password.';
    } else {
        $hasil = portal_coba_login($nisn, $password);
        if ($hasil['ok']) {
            portal_masuk_sesi($hasil['akun']);
            header('Location: ' . portal_url());
            exit;
        }
        $galat = $hasil['pesan'];
    }
} else {
    $nisn = $nisnAwal;
}

portal_auth_awal('Masuk', 'Selamat Datang', 'Masuk dengan NISN dan password Anda');
if ($info !== '') portal_alert('ok', $info);
if ($galat !== '') portal_alert('err', $galat);
?>
      <form method="post" action="<?= e(portal_url('masuk')) ?>" data-sekali autocomplete="on">
        <?= csrf_field() ?>
        <div class="pt-field">
          <label for="nisn">NISN</label>
          <input type="text" id="nisn" name="nisn" value="<?= e($nisn) ?>" inputmode="numeric" autocomplete="username" maxlength="30" placeholder="Nomor Induk Siswa Nasional" required <?= $nisn === '' ? 'autofocus' : '' ?>>
        </div>
        <div class="pt-field pw">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" autocomplete="current-password" placeholder="Password" required <?= $nisn !== '' ? 'autofocus' : '' ?>>
          <button type="button" class="pt-eye" data-pw-toggle="password" aria-label="Tampilkan password"><i class="bi bi-eye-slash"></i></button>
        </div>
        <button type="submit" class="pt-btn">Masuk</button>
      </form>
      <p class="pt-kaki">Belum punya password? <a href="<?= e(portal_url('aktivasi')) ?>">Aktivasi akun</a><br>Lupa password? Minta kode baru ke petugas sekolah.</p>
<?php portal_auth_akhir();
