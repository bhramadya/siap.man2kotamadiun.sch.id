/* Portal siswa: tampil/sembunyi password, format kode aktivasi, cek konfirmasi password, cegah kirim ganda. */
(function () {
  'use strict';

  document.querySelectorAll('[data-pw-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-pw-toggle'));
      if (!input) return;
      var tampil = input.type === 'password';
      input.type = tampil ? 'text' : 'password';
      var ic = btn.querySelector('i');
      if (ic) ic.className = tampil ? 'bi bi-eye' : 'bi bi-eye-slash';
      btn.setAttribute('aria-label', tampil ? 'Sembunyikan password' : 'Tampilkan password');
    });
  });

  // Kode aktivasi: huruf besar, tanpa karakter lain, otomatis "XXXX-XXXX".
  var kode = document.querySelector('input.kode');
  if (kode) {
    kode.addEventListener('input', function () {
      var v = kode.value.toUpperCase().replace(/[^0-9A-Z]/g, '').slice(0, 8);
      kode.value = v.length > 4 ? v.slice(0, 4) + '-' + v.slice(4) : v;
    });
  }

  // Konfirmasi password
  var p1 = document.getElementById('password'), p2 = document.getElementById('password2'), info = document.getElementById('cocok');
  if (p1 && p2 && info) {
    var cek = function () {
      if (!p2.value) { info.textContent = ''; info.className = 'pt-hint'; return; }
      var sama = p1.value === p2.value;
      info.textContent = sama ? 'Password cocok.' : 'Password belum sama.';
      info.className = 'pt-hint' + (sama ? '' : ' salah');
    };
    p1.addEventListener('input', cek); p2.addEventListener('input', cek);
  }

  // Cegah klik ganda
  document.querySelectorAll('form[data-sekali]').forEach(function (f) {
    f.addEventListener('submit', function () {
      var b = f.querySelector('button[type=submit]');
      if (b) { b.disabled = true; b.innerHTML = '<i class="bi bi-hourglass-split"></i> Memproses…'; }
    });
  });
})();
