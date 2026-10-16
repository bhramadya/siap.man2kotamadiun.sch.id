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
$namaSekolah = $settings['nama_sekolah'] ?? 'Presensi Siswa';
$logo = $settings['logo'] ?? '';
$logoUrl = ($logo !== '' && is_file(__DIR__ . '/uploads/siswa/' . $logo)) ? BASE_URL . '/uploads/siswa/' . rawurlencode($logo) : null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, interactive-widget=overlays-content">
<meta name="theme-color" content="#0b3a27">
<title>Masuk — <?= e($namaSekolah) ?></title>
<?= favicon_tag() ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
<style>
  :root {
    --hj-tua: #0f5c3c;       /* tombol & judul */
    --hj-judul: #1d5a3f;
    --hj-teks: #3f8a69;      /* teks isian & tautan */
    --hj-redup: #7a9487;     /* keterangan */
    --hj-isian: #e8efeb;     /* latar kolom isian */
  }
  /* Halaman dikunci: tidak bisa digeser/ditarik ke samping maupun bounce, seperti halaman 404.
     Hanya akan bisa scroll vertikal bila layar terlalu pendek untuk memuat form (mis. saat keyboard terbuka). */
  html { height: 100%; overflow: hidden; overscroll-behavior: none; -webkit-text-size-adjust: 100%; }
  body.login-page {
    position: fixed; inset: 0; min-height: 0; overflow-x: hidden; overflow-y: auto; overscroll-behavior: none; touch-action: pan-y;
    padding: 20px 16px; display: flex; align-items: flex-start; justify-content: center;
    font-family: 'Montserrat', system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
    background:
      radial-gradient(60% 45% at 12% 8%, rgba(120, 190, 150, .38), transparent 70%),
      radial-gradient(55% 45% at 92% 92%, rgba(100, 170, 135, .34), transparent 70%),
      #eaf2ed;
  }

  .lg-card, .lg-card *, .lg-card *::before, .lg-card *::after { box-sizing: border-box; }

  /* ---- kartu ---- */
  .lg-card {
    margin: auto; width: 100%; max-width: 380px; display: flex; flex-direction: column;
    min-height: 600px; min-height: min(660px, calc(100svh - 40px));
    background: #fff; border-radius: 30px; overflow: hidden;
    box-shadow: 0 30px 60px -28px rgba(14, 60, 40, .55), 0 2px 8px rgba(14, 60, 40, .08);
  }

  /* ---- bagian atas bergambar daun ---- */
  .lg-hero { position: relative; height: 250px; flex: none; background: #0b3a27; }
  .lg-hero > svg { position: absolute; inset: 0; width: 100%; height: 100%; display: block; }
  .lg-back {
    position: absolute; z-index: 2; top: 16px; left: 16px; width: 34px; height: 34px; border-radius: 50%;
    background: #fff; color: var(--hj-tua); display: grid; place-items: center; text-decoration: none; font-size: 15px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, .25); transition: transform .15s;
  }
  .lg-back:hover { color: var(--hj-tua); transform: translateX(-2px); }
  .lg-back:focus-visible { outline: 3px solid #fff; outline-offset: 2px; }
  .lg-logo { position: absolute; left: 0; right: 0; top: 0; bottom: 76px; display: flex; align-items: center; justify-content: center; pointer-events: none; }
  /* Logo transparan: tanpa lingkaran/latar putih; bayangan mengikuti bentuk logo agar tetap terbaca di atas gambar daun */
  .lg-logo img { width: 88px; height: 88px; object-fit: contain; background: transparent; border: 0; border-radius: 0; padding: 0; box-shadow: none; filter: drop-shadow(0 4px 10px rgba(0, 0, 0, .4)); }
  .lg-logo i { font-size: 64px; line-height: 1; color: #fff; filter: drop-shadow(0 6px 14px rgba(0, 0, 0, .35)); }

  /* ---- bagian putih dengan lengkung kiri-atas ---- */
  .lg-body {
    position: relative; z-index: 1; flex: 1; display: flex; flex-direction: column;
    margin-top: -76px; background: #fff; border-top-left-radius: 76px; padding: 34px 28px 20px;
  }
  .lg-judul { margin: 0; text-align: center; font-size: 28px; font-weight: 800; letter-spacing: -.2px; color: var(--hj-judul); line-height: 1.15; }
  .lg-sub { margin: 6px 0 0; text-align: center; font-size: 13px; font-weight: 600; color: var(--hj-redup); line-height: 1.4; }
  .lg-form { flex: 1; display: flex; flex-direction: column; margin: 28px 0 0; }

  .lg-err { display: flex; gap: 8px; align-items: flex-start; margin: 0 0 14px; padding: 10px 14px; border-radius: 16px; background: #fdecec; color: #b42318; font-size: 12.5px; font-weight: 600; line-height: 1.45; }
  .lg-err i { margin-top: 1px; }

  .lg-field { position: relative; margin-bottom: 14px; }
  .lg-field input {
    display: block; width: 100%; height: 48px; padding: 0 20px; border: 2px solid transparent; border-radius: 999px; outline: none;
    background: var(--hj-isian); color: var(--hj-judul); font: 600 14.5px/1 'Montserrat', system-ui, sans-serif;
    transition: border-color .18s, background .18s, box-shadow .18s;
  }
  .lg-field input::placeholder { color: var(--hj-teks); opacity: 1; font-weight: 600; }
  .lg-field input:focus { background: #fff; border-color: var(--hj-teks); box-shadow: 0 0 0 4px rgba(63, 138, 105, .16); }
  .lg-field.pw input { padding-right: 52px; }
  .lg-field input:-webkit-autofill { -webkit-box-shadow: 0 0 0 40px var(--hj-isian) inset; -webkit-text-fill-color: var(--hj-judul); }
  .lg-field input::-ms-reveal, .lg-field input::-ms-clear { display: none; }
  .lg-field input::-webkit-credentials-auto-fill-button, .lg-field input::-webkit-contacts-auto-fill-button { visibility: hidden; display: none !important; pointer-events: none; }
  .lg-eye { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 36px; height: 36px; border: 0; border-radius: 50%; background: transparent; color: var(--hj-tua); font-size: 17px; cursor: pointer; }
  .lg-eye:hover { background: rgba(15, 92, 60, .09); }
  .lg-eye:focus-visible { outline: 2px solid var(--hj-teks); outline-offset: 1px; }

  .lg-ruang { flex: 1; min-height: 22px; }
  .lg-btn {
    display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; height: 50px; border: 0; border-radius: 999px;
    background: var(--hj-tua); color: #fff; font: 700 15px/1 'Montserrat', system-ui, sans-serif; cursor: pointer;
    box-shadow: 0 12px 22px -12px rgba(15, 92, 60, .9); transition: background .15s, transform .15s;
  }
  .lg-btn:hover { background: #0b4a31; }
  .lg-btn:active { transform: scale(.985); }
  .lg-btn:focus-visible { outline: 3px solid rgba(63, 138, 105, .5); outline-offset: 2px; }
  .lg-btn:disabled { opacity: .8; cursor: wait; }

  .lg-kaki { margin: 14px 0 0; text-align: center; font-size: 11.5px; font-weight: 600; color: var(--hj-redup); line-height: 1.6; }
  .lg-kaki a { color: var(--hj-tua); font-weight: 700; text-decoration: none; }
  .lg-kaki a:hover { text-decoration: underline; }
  .lg-hak { display: block; margin-top: 2px; font-size: 10.5px; letter-spacing: .04em; opacity: .85; }

  @media (max-width: 480px) { .lg-field input { font-size: 16px; } }  /* cegah auto-zoom iOS saat fokus */

  /* ---- HP: tampilan penuh layar, tanpa latar belakang/kartu mengambang ---- */
  @media (max-width: 600px) {
    body.login-page { padding: 0; background: #fff; align-items: flex-start; }
    /* Tinggi layar dikunci saat halaman dibuka (--tinggi, diisi JS) agar tata letak TIDAK bergeser/terhimpit
       ketika keyboard HP muncul. Tanpa JS, memakai tinggi layar biasa. */
    body.login-page.tinggi-tetap { bottom: auto; height: var(--tinggi); }
    .lg-card { transition: transform .18s ease; will-change: auto; }
    .lg-card {
      margin: 0; max-width: none; border-radius: 0; box-shadow: none;
      min-height: 100vh; min-height: var(--tinggi, 100vh);
    }
    .lg-hero { height: clamp(250px, 38vh, 340px); height: clamp(250px, calc(var(--tinggi, 100vh) * .38), 340px); }
    .lg-back { top: calc(16px + env(safe-area-inset-top, 0px)); }
    .lg-logo { top: env(safe-area-inset-top, 0px); }
    .lg-body { padding: 34px 28px calc(22px + env(safe-area-inset-bottom, 0px)); }
  }
  @media (prefers-reduced-motion: reduce) { .lg-back, .lg-btn, .lg-field input, .lg-card { transition: none !important; } }
</style>
</head>
<body class="login-page">
  <main class="lg-card">

    <div class="lg-hero">
      <!-- Latar daun hijau (SVG, tanpa file gambar tambahan) -->
      <svg viewBox="0 0 380 232" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
        <defs>
          <linearGradient id="lgBg" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#072a1c"/><stop offset=".55" stop-color="#0d4a31"/><stop offset="1" stop-color="#14663f"/>
          </linearGradient>
          <linearGradient id="lgDaunA" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#0a3a27"/><stop offset="1" stop-color="#1f8454"/>
          </linearGradient>
          <linearGradient id="lgDaunB" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#0c4a30"/><stop offset="1" stop-color="#2a9a63"/>
          </linearGradient>
          <linearGradient id="lgDaunC" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#061f15"/><stop offset="1" stop-color="#0f5b3a"/>
          </linearGradient>
          <radialGradient id="lgSorot" cx=".5" cy=".38" r=".55">
            <stop offset="0" stop-color="#0b3a27" stop-opacity=".55"/><stop offset="1" stop-color="#0b3a27" stop-opacity="0"/>
          </radialGradient>
          <!-- bentuk daun + tulang daun; warna isi diberikan lewat <use> -->
          <g id="lgDaun">
            <path d="M0 0 C 46 -74 168 -86 262 -24 C 178 38 66 52 0 0 Z"/>
            <path d="M6 -2 Q 120 -34 252 -24" fill="none" stroke="rgba(255,255,255,.14)" stroke-width="2.2" stroke-linecap="round"/>
          </g>
        </defs>
        <rect width="380" height="232" fill="url(#lgBg)"/>
        <use href="#lgDaun" fill="url(#lgDaunC)" transform="translate(-70 70) rotate(-24) scale(1.35)"/>
        <use href="#lgDaun" fill="url(#lgDaunA)" transform="translate(-30 215) rotate(-38) scale(1.25)"/>
        <use href="#lgDaun" fill="url(#lgDaunB)" transform="translate(110 -20) rotate(52) scale(1.15)"/>
        <use href="#lgDaun" fill="url(#lgDaunC)" transform="translate(420 20) rotate(150) scale(1.3)"/>
        <use href="#lgDaun" fill="url(#lgDaunA)" transform="translate(440 190) rotate(205) scale(1.2)"/>
        <use href="#lgDaun" fill="url(#lgDaunB)" transform="translate(230 235) rotate(-62) scale(.9)" opacity=".85"/>
        <use href="#lgDaun" fill="url(#lgDaunC)" transform="translate(-10 -10) rotate(30) scale(.8)" opacity=".9"/>
        <rect width="380" height="232" fill="url(#lgSorot)"/>
      </svg>
      <a href="index.php" class="lg-back" id="btnKembali" title="Kembali" aria-label="Kembali"><i class="bi bi-chevron-left"></i></a>
      <div class="lg-logo"><?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="Logo <?= e($namaSekolah) ?>"><?php else: ?><i class="bi bi-qr-code-scan" aria-hidden="true"></i><?php endif; ?></div>
    </div>

    <div class="lg-body">
      <h1 class="lg-judul">Selamat Datang</h1>
      <p class="lg-sub">Masuk ke akun Absensi Anda</p>

      <form method="post" autocomplete="off" class="lg-form">
        <?= csrf_field() ?>
        <?php if ($error): ?>
          <div class="lg-err" role="alert"><i class="bi bi-exclamation-circle-fill"></i><span><?= e($error) ?></span></div>
        <?php endif; ?>
        <div class="lg-field">
          <input type="text" name="username" id="username" placeholder="Username" aria-label="Username" required autocapitalize="none" spellcheck="false">
        </div>
        <div class="lg-field pw">
          <input type="password" name="password" id="password" placeholder="Password" aria-label="Password" required>
          <button type="button" class="lg-eye" id="togglePw" aria-label="Tampilkan password"><i class="bi bi-eye-slash-fill"></i></button>
        </div>
        <div class="lg-ruang"></div>
        <button type="submit" class="lg-btn" id="btnMasuk">Masuk</button>
      </form>

      <p class="lg-kaki">
        Bukan petugas? <a href="index.php">Kembali ke beranda</a>
        <span class="lg-hak"><?= e($namaSekolah) ?> · SIAP ABSEN &copy; <?= date('Y') ?></span>
      </p>
    </div>

  </main>
<script>
  (function () {
    // Tombol kembali: pindah ke halaman sebelumnya bila datang dari halaman lain di situs ini.
    // Jika dibuka langsung (tanpa halaman sebelumnya), atau sebelumnya halaman login itu sendiri
    // (mis. setelah salah password), tautan bawaannya mengarah ke beranda.
    document.getElementById('btnKembali').addEventListener('click', function (e) {
      try {
        var ref = document.referrer ? new URL(document.referrer) : null;
        if (ref && ref.origin === location.origin && ref.pathname !== location.pathname && history.length > 1) {
          e.preventDefault();
          history.back();
        }
      } catch (err) { /* biarkan tautan ke beranda berjalan */ }
    });

    // Kunci tinggi tampilan di HP: ukuran diambil saat keyboard BELUM muncul, lalu tidak ikut mengecil
    // ketika keyboard terbuka. Hanya diperbarui bila lebar layar berubah (layar diputar) atau tinggi benar-benar membesar.
    var hp = window.matchMedia('(max-width: 600px)');
    var root = document.documentElement, tinggi = 0, lebar = 0;
    function kunci() {
      if (!hp.matches) { document.body.classList.remove('tinggi-tetap'); root.style.removeProperty('--tinggi'); tinggi = lebar = 0; return; }
      var h = window.innerHeight, w = window.innerWidth;
      var aktif = document.activeElement, mengetik = aktif && (aktif.tagName === 'INPUT' || aktif.tagName === 'TEXTAREA');
      if (tinggi && w === lebar && (h <= tinggi || mengetik)) return;   // keyboard muncul/menutup: abaikan
      tinggi = h; lebar = w;
      root.style.setProperty('--tinggi', h + 'px');
      document.body.classList.add('tinggi-tetap');
    }
    kunci();
    window.addEventListener('resize', kunci);
    hp.addEventListener && hp.addEventListener('change', function () { tinggi = 0; kunci(); });

    // Pengaman: tata letak tetap diam saat keyboard muncul. Hanya bila kolom yang sedang diketik tertutup keyboard
    // (layar pendek), kartu digeser naik SEPERLUNYA saja agar kolom itu terlihat; kembali normal saat keyboard menutup.
    var vv = window.visualViewport, kartu = document.querySelector('.lg-card'), geserNow = 0;
    function jagaTerlihat() {
      if (!vv || !hp.matches) return;
      var a = document.activeElement, geser = 0;
      var keyboardBuka = tinggi && (tinggi - vv.height) > 120;
      if (keyboardBuka && a && a.tagName === 'INPUT') {
        var bawahAsli = a.getBoundingClientRect().bottom - geserNow;           // posisi tanpa geseran
        var batas = vv.offsetTop + vv.height;                                  // tepi bawah area yang terlihat
        geser = Math.min(Math.max(0, Math.ceil(bawahAsli + 14 - batas)), 220);
      }
      if (geser !== geserNow) { geserNow = geser; kartu.style.transform = geser ? 'translateY(' + (-geser) + 'px)' : ''; }
    }
    if (vv) { vv.addEventListener('resize', jagaTerlihat); vv.addEventListener('scroll', jagaTerlihat); }
    document.addEventListener('focusin', function () { setTimeout(jagaTerlihat, 60); setTimeout(jagaTerlihat, 350); });
    document.addEventListener('focusout', function () { setTimeout(jagaTerlihat, 60); });

    // Fokus otomatis ke username hanya di perangkat dengan mouse (desktop); di HP keyboard tidak boleh muncul sendiri.
    if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) { document.getElementById('username').focus(); }

    var pw = document.getElementById('password'), tg = document.getElementById('togglePw');
    tg.addEventListener('click', function () {
      var show = pw.type === 'password';
      pw.type = show ? 'text' : 'password';
      tg.innerHTML = '<i class="bi bi-eye' + (show ? '-fill' : '-slash-fill') + '"></i>';
      tg.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
      pw.focus();
    });
    document.querySelector('form').addEventListener('submit', function () {
      var b = document.getElementById('btnMasuk');
      setTimeout(function () { b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm"></span>Memproses...'; }, 0);
    });
  })();
</script>
</body>
</html>