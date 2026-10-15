<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Sesi tidak valid, silakan coba lagi.';
    } else {
        $username = clean($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Username dan password wajib diisi.';
        } else {
            $sisaKunci = login_kunci_sisa($username);
            if ($sisaKunci > 0) {
                // Terlalu banyak percobaan gagal: jangan periksa password sama sekali.
                security_log(null, 'ADMIN_LOGIN_LOCKED', null, 'Login diblokir sementara untuk username: ' . substr($username, 0, 50));
                $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . (int) ceil($sisaKunci / 60) . ' menit.';
                usleep(300000);
            } else {
                $stmt = getDB()->prepare('SELECT * FROM users WHERE username = :u AND status = "aktif" LIMIT 1');
                $stmt->execute([':u' => $username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['admin_id'] = $user['id'];
                    $_SESSION['last_regen'] = time();
                    login_bersihkan($username);
                    catat_aktivitas('LOGIN', 'login', 'Login berhasil: ' . $user['username']);
                    if ($password === 'admin123') {
                        set_flash('warning', 'Password Anda masih password bawaan (admin123). Segera ganti di menu Profil Akun.');
                    }
                    header('Location: index.php');
                    exit;
                } else {
                    login_catat_gagal($username);
                    security_log(null, 'ADMIN_LOGIN_FAILED', null, 'Login admin gagal untuk username: ' . substr($username, 0, 50));
                    $error = 'Username atau password salah.';
                    usleep(300000); // mitigasi brute force sederhana
                }
            }
        }
    }
}

$settings = get_settings();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Masuk — <?= e($settings['nama_sekolah'] ?? 'Presensi Siswa') ?></title>
<?= favicon_tag() ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
<style>
  /* Beri ruang di kiri-kanan (dan atas-bawah) agar kotak login tidak menempel ke tepi layar HP */
  .login-page { padding: 24px 20px; }
  /* Halaman dikunci: tidak bisa digeser/ditarik ke samping maupun bounce, seperti halaman 404.
     Hanya akan bisa scroll vertikal bila layar terlalu pendek untuk memuat form (mis. saat keyboard terbuka). */
  html { height: 100%; overflow: hidden; overscroll-behavior: none; -webkit-text-size-adjust: 100%; }
  body.login-page { position: fixed; inset: 0; min-height: 0; overflow-x: hidden; overflow-y: auto; overscroll-behavior: none; touch-action: pan-y; }
  .login-page .login-box { margin: auto; }
  @media (max-width: 480px) { .field input { font-size: 16px; } } /* cegah auto-zoom iOS saat fokus */
  @media (max-width: 480px) { .login-page { padding: 20px 16px; } .login-box { padding: 28px 22px 22px; } }
  .login-box { max-width: 400px; padding: 36px 32px 28px; border-radius: 20px; }
  .login-logo { display: block; width: auto; height: auto; margin: 0 auto 14px; font-size: 64px; line-height: 1; text-align: center;
    background: none; box-shadow: none; border-radius: 0; color: #0d6efd; }
  .login-logo i { background: linear-gradient(135deg, #0d6efd, #6f42c1); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; color: transparent; }
  .app-name { font-size: 24px; font-weight: 800; letter-spacing: 3px; line-height: 1.2; color: #0d6efd; margin-top: 6px; }
  .nama-sekolah { font-size: 19px; font-weight: 700; letter-spacing: .5px; line-height: 1.3; margin: 4px 0 6px; color: #1b2437; }
  .login-garis { border: 0; border-top: 1px solid #e3e8f1; opacity: 1; margin: 22px 0 14px; }
  .login-footer { text-align: center; font-size: 12px; letter-spacing: 1px; color: #8a94a6; }
  .field { position: relative; margin-bottom: 22px; }
  .field-icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); font-size: 18px; color: #8a94a6; pointer-events: none; transition: color .2s; }
  .field input {
    width: 100%; height: 56px; padding: 0 46px 0 48px; line-height: 56px; font-size: 15px; color: #1b2437;
    border: 1.5px solid #cfd6e4; border-radius: 14px; background: #fff; outline: none;
    transition: border-color .2s, box-shadow .2s;
  }
  .field label {
    position: absolute; left: 47px; top: 50%; transform: translateY(-50%); padding: 0 6px; margin: 0;
    color: #8a94a6; font-size: 15px; background: transparent; pointer-events: none; border-radius: 6px;
    transition: top .18s ease, font-size .18s ease, color .18s ease, left .18s ease, background .18s ease;
  }
  /* label naik ke garis atas saat fokus / sudah terisi */
  .field input:focus ~ label,
  .field input:not(:placeholder-shown) ~ label,
  .field input:-webkit-autofill ~ label {
    top: 0; left: 14px; font-size: 12.5px; background: #fff; color: #6b7690;
  }
  /* Sembunyikan ikon mata bawaan browser (Edge/Chrome) agar hanya tersisa satu */
  .field input::-ms-reveal, .field input::-ms-clear { display: none; }
  .field input::-webkit-credentials-auto-fill-button, .field input::-webkit-contacts-auto-fill-button { visibility: hidden; display: none !important; pointer-events: none; }
  .field input:focus { border-color: #0d6efd; box-shadow: 0 0 0 4px rgba(13,110,253,.12); }
  .field input:focus ~ label { color: #0d6efd; }
  .field input:focus ~ .field-icon { color: #0d6efd; }
  .field .toggle-pw { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); border: 0; background: transparent;
    color: #8a94a6; font-size: 18px; width: 36px; height: 36px; border-radius: 10px; cursor: pointer; }
  .field .toggle-pw:hover { color: #0d6efd; background: rgba(13,110,253,.08); }
  .btn-masuk { height: 50px; border-radius: 14px; font-weight: 600; font-size: 16px; border: 0;
    background: linear-gradient(135deg, #0d6efd, #5b3df5); box-shadow: 0 10px 22px -10px rgba(13,110,253,.8); transition: transform .15s, box-shadow .15s; }
  .btn-masuk:hover { transform: translateY(-1px); box-shadow: 0 14px 26px -10px rgba(13,110,253,.9); }
  .btn-masuk:disabled { opacity: .75; transform: none; }
  @media (prefers-reduced-motion: reduce) { .field label, .btn-masuk { transition: none; } }
</style>
</head>
<body class="login-page">
  <div class="login-box">
    <div class="text-center mb-4">
      <div class="login-logo"><i class="bi bi-qr-code-scan"></i></div>
      <div class="app-name">ABSENSI SISWA</div>
      <h4 class="nama-sekolah"><?= e(mb_strtoupper($settings['nama_sekolah'] ?? 'Presensi Siswa', 'UTF-8')) ?></h4>
    </div>
    <?php if ($error): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <div class="field">
        <i class="bi bi-person field-icon"></i>
        <input type="text" name="username" id="username" placeholder=" " required autofocus autocapitalize="none" spellcheck="false">
        <label for="username">Username</label>
      </div>
      <div class="field">
        <i class="bi bi-lock field-icon"></i>
        <input type="password" name="password" id="password" placeholder=" " required>
        <label for="password">Password</label>
        <button type="button" class="toggle-pw" id="togglePw" aria-label="Tampilkan password"><i class="bi bi-eye"></i></button>
      </div>
      <button type="submit" class="btn btn-primary btn-masuk w-100" id="btnMasuk"><i class="bi bi-box-arrow-in-right me-1"></i> Masuk</button>
    </form>
    <div class="text-center mt-3">
      <a href="index.php" class="small">&laquo; Kembali ke beranda</a>
    </div>
    <hr class="login-garis">
    <div class="login-footer">SIAP ABSEN &copy; <?= date('Y') ?></div>
  </div>
<script>
  (function () {
    var pw = document.getElementById('password'), tg = document.getElementById('togglePw');
    tg.addEventListener('click', function () {
      var show = pw.type === 'password';
      pw.type = show ? 'text' : 'password';
      tg.innerHTML = '<i class="bi bi-eye' + (show ? '-slash' : '') + '"></i>';
      tg.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
      pw.focus();
    });
    document.querySelector('form').addEventListener('submit', function () {
      var b = document.getElementById('btnMasuk');
      setTimeout(function () { b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Memproses...'; }, 0);
    });
  })();
</script>
</body>
</html>