<?php
// /portal/aktivasi — NISN + kode dari petugas + password baru
if (portal_siswa_sekarang()) { header('Location: ' . portal_url()); exit; }

$galat = '';
$nisn = portal_nisn((string) ($_GET['nisn'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $nisn = portal_nisn((string) ($_POST['nisn'] ?? ''));
    $kode = (string) ($_POST['kode'] ?? '');
    $pw1 = (string) ($_POST['password'] ?? '');
    $pw2 = (string) ($_POST['password2'] ?? '');

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $galat = 'Sesi halaman kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif ($nisn === '' || trim($kode) === '' || $pw1 === '') {
        $galat = 'Lengkapi NISN, kode aktivasi, dan password.';
    } elseif ($pw1 !== $pw2) {
        $galat = 'Konfirmasi password belum sama.';
    } else {
        $hasil = portal_aktivasi($nisn, $kode, $pw1);
        if ($hasil['ok']) {
            header('Location: ' . portal_login_url(['aktif' => 1]));
            exit;
        }
        $galat = $hasil['pesan'];
    }
}

portal_auth_awal('Aktivasi Akun', 'Aktivasi Akun', 'Masukkan kode dari petugas, lalu buat password Anda sendiri');
if ($galat !== '') portal_alert('err', $galat);
?>
      <form method="post" action="<?= e(portal_url('aktivasi')) ?>" data-sekali autocomplete="off">
        <?= csrf_field() ?>
        <div class="pt-field">
          <label for="nisn">NISN</label>
          <input type="text" id="nisn" name="nisn" value="<?= e($nisn) ?>" inputmode="numeric" maxlength="30" placeholder="Nomor Induk Siswa Nasional" required <?= $nisn === '' ? 'autofocus' : '' ?>>
        </div>
        <div class="pt-field">
          <label for="kode">Kode aktivasi</label>
          <input type="text" id="kode" name="kode" class="kode" maxlength="9" placeholder="XXXX-XXXX" autocapitalize="characters" spellcheck="false" required <?= $nisn !== '' ? 'autofocus' : '' ?>>
        </div>
        <div class="pt-field pw">
          <label for="password">Password baru</label>
          <input type="password" id="password" name="password" autocomplete="new-password" placeholder="Minimal <?= PORTAL_PASSWORD_MIN ?> karakter" required>
          <button type="button" class="pt-eye" data-pw-toggle="password" aria-label="Tampilkan password"><i class="bi bi-eye-slash"></i></button>
        </div>
        <p class="pt-hint">Gunakan huruf dan angka. Jangan memakai NISN atau tanggal lahir.</p>
        <div class="pt-field pw">
          <label for="password2">Ulangi password</label>
          <input type="password" id="password2" name="password2" autocomplete="new-password" placeholder="Ketik ulang password" required>
        </div>
        <p class="pt-hint" id="cocok"></p>
        <button type="submit" class="pt-btn">Aktifkan Akun</button>
      </form>
      <p class="pt-kaki">Sudah punya password? <a href="<?= e(portal_login_url()) ?>">Masuk</a></p>
<?php portal_auth_akhir();
