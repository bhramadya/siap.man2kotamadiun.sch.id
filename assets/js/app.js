document.addEventListener('DOMContentLoaded', function () {
  const toggle = document.getElementById('toggleSidebar');
  const sidebar = document.getElementById('sidebar');

  // Ambil backdrop dari HTML kalau sudah ada (header.php terbaru), atau
  // BUAT SENDIRI via JS kalau belum ada -- supaya fitur ini tetap jalan
  // walau file header.php di server belum sempat/lupa ditimpa, cukup
  // app.js (dan style.css utk CSS-nya) saja yang perlu ter-upload.
  let backdrop = document.getElementById('sidebarBackdrop');
  if (!backdrop && sidebar) {
    backdrop = document.createElement('div');
    backdrop.id = 'sidebarBackdrop';
    backdrop.className = 'sidebar-backdrop';
    sidebar.parentNode.insertBefore(backdrop, sidebar);
  }

  // Jaga-jaga kalau style.css juga belum ter-upload: paksa gaya backdrop
  // inline juga, supaya overlay-nya tetap berfungsi (menutupi & menerima
  // klik) walau CSS eksternalnya ketinggalan versi lama.
  if (backdrop) {
    backdrop.style.position = 'fixed';
    backdrop.style.inset = '0';
    backdrop.style.background = 'rgba(0,0,0,.35)';
    backdrop.style.zIndex = '1040';
    backdrop.style.display = 'none';
  }

  function bukaSidebar() {
    sidebar.classList.add('show');
    if (backdrop) backdrop.style.display = 'block';
  }
  function tutupSidebar() {
    sidebar.classList.remove('show');
    if (backdrop) backdrop.style.display = 'none';
  }

  if (toggle && sidebar) {
    toggle.addEventListener('click', function () {
      // Desktop: minimize/perluas sidebar. Mobile: buka/tutup menu geser.
      if (window.innerWidth >= 993 && window.toggleSidebarDesktop) { window.toggleSidebarDesktop(); return; }
      sidebar.classList.contains('show') ? tutupSidebar() : bukaSidebar();
    });

    // Tap di luar sidebar (area konten/backdrop) -> tutup.
    if (backdrop) backdrop.addEventListener('click', tutupSidebar);

    // Pilih menu di sidebar (mobile) -> langsung tutup, jangan nunggu
    // halaman baru selesai dimuat (terasa lebih responsif), dan tetap
    // menutup pada kasus link yg tidak memuat ulang halaman (mis. href="#").
    sidebar.querySelectorAll('a.nav-link').forEach(function (link) {
      link.addEventListener('click', tutupSidebar);
    });

    // Kalau layar di-resize/rotasi ke lebar desktop selagi sidebar mobile
    // terbuka, tutup otomatis -- backdrop-nya jangan sampai nyangkut.
    window.addEventListener('resize', function () {
      if (window.innerWidth >= 992) tutupSidebar();
    });
  }

  // Auto-dismiss alert setelah 5 detik
  document.querySelectorAll('.alert-dismissible').forEach(function (alertEl) {
    setTimeout(function () {
      const alert = bootstrap.Alert.getOrCreateInstance(alertEl);
      alert.close();
    }, 5000);
  });
});

/**
 * Tombol simpan pintar: pada <form data-dirty-guard>, tombol submit nonaktif
 * selama isian belum berubah dan aktif begitu ada perubahan.
 * Dilewati kalau halaman menampilkan error validasi (agar bisa kirim ulang).
 */
(function () {
  function snapshot(form) {
    var parts = [];
    new FormData(form).forEach(function (v, k) {
      if (k === 'csrf_token') return;
      parts.push(k + '=' + (v instanceof File ? v.name + ':' + v.size : v));
    });
    return parts.join('&');
  }

  function init() {
    if (document.querySelector('.alert-danger')) return;
    document.querySelectorAll('form[data-dirty-guard]').forEach(function (form) {
      var buttons = form.querySelectorAll('button[type="submit"]');
      if (!buttons.length) return;
      var awal = snapshot(form);

      function cek() {
        var berubah = snapshot(form) !== awal;
        buttons.forEach(function (b) {
          b.disabled = !berubah;
          b.title = berubah ? '' : 'Belum ada perubahan';
        });
      }

      cek();
      ['input', 'change', 'click', 'keyup'].forEach(function (ev) {
        form.addEventListener(ev, function () { setTimeout(cek, 0); });
      });
      // Menangkap perubahan lewat script (mis. tombol "Ambil Lokasi" GPS).
      setInterval(cek, 400);
    });
  }

  if (document.readyState === 'complete') init();
  else window.addEventListener('load', init);
})();


// Minimize / perluas sidebar (desktop) lewat tombol garis tiga di header. Status disimpan di localStorage.
(function () {
  var root = document.documentElement;
  var links = document.querySelectorAll('#sidebar .nav-link');
  links.forEach(function (a) { a.dataset.label = a.textContent.trim(); });
  function tooltip() {
    var tutup = root.classList.contains('sidebar-collapsed');
    links.forEach(function (a) { if (tutup) a.title = a.dataset.label; else a.removeAttribute('title'); });
  }
  window.toggleSidebarDesktop = function () {
    root.classList.toggle('sidebar-collapsed');
    try { localStorage.setItem('sidebar_collapsed', root.classList.contains('sidebar-collapsed') ? '1' : '0'); } catch (e) {}
    tooltip();
  };
  tooltip();
})();


/**
 * Pencarian langsung: form[data-live] otomatis memuat hasil saat mengetik (tanpa Enter)
 * atau saat pilihan filter diubah. Hanya elemen data-live-target yang diganti,
 * jadi kursor tetap di kolom pencarian dan halaman tidak berkedip.
 */
(function () {
  document.querySelectorAll('form[data-live]').forEach(function (form) {
    var sel = form.getAttribute('data-live-target');
    var timer = null, ctrl = null;

    function jalankan() {
      var p = new URLSearchParams(new FormData(form));
      p.delete('page');
      var url = location.pathname + '?' + p.toString();
      if (ctrl) ctrl.abort();
      ctrl = new AbortController();
      var target = document.querySelector(sel);
      if (target) target.style.opacity = '.55';
      fetch(url, { signal: ctrl.signal, credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) {
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var baru = doc.querySelector(sel), lama = document.querySelector(sel);
          if (baru && lama) {
            lama.replaceWith(baru);
            if (window.aktifkanSelectKustom) window.aktifkanSelectKustom(baru);
            history.replaceState(null, '', url);
          } else {
            location.href = url;   // cadangan bila struktur tidak ditemukan
          }
        })
        .catch(function (err) {
          if (err.name !== 'AbortError') { var t = document.querySelector(sel); if (t) t.style.opacity = ''; }
        });
    }

    form.addEventListener('input', function (e) {
      if (e.target.matches('input[type="text"], input[type="search"], input[type="number"]')) {
        clearTimeout(timer);
        timer = setTimeout(jalankan, 350);
      }
    });
    form.addEventListener('change', function (e) {
      if (e.target.matches('select, input[type="date"]')) { clearTimeout(timer); jalankan(); }
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      clearTimeout(timer);
      jalankan();
    });
  });
})();


/**
 * Select kustom: mengganti daftar pilihan bawaan browser (kotak persegi) dengan daftar
 * melengkung. <select> asli tetap ada (tersembunyi) sehingga form, validasi, dan event
 * "change" (mis. pencarian langsung) berjalan seperti biasa.
 */
(function () {
  var terbuka = null; // {menu, btn}

  function tutup() {
    if (!terbuka) return;
    terbuka.menu.classList.remove('open');
    terbuka.btn.setAttribute('aria-expanded', 'false');
    terbuka = null;
  }

  function bangun(sel) {
    var wrap = document.createElement('div');
    wrap.className = 'cs' + (sel.classList.contains('form-select-sm') ? ' cs-sm' : '');
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'cs-btn ' + sel.className.replace('cs-native', '');
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    if (sel.getAttribute('aria-label')) btn.setAttribute('aria-label', sel.getAttribute('aria-label'));
    var menu = document.createElement('div');
    menu.className = 'cs-menu';
    menu.setAttribute('role', 'listbox');

    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    wrap.appendChild(btn);
    document.body.appendChild(menu);
    sel.classList.add('cs-native');
    sel.tabIndex = -1;
    sel.setAttribute('aria-hidden', 'true');

    var terakhir = null;

    function label() {
      var o = sel.options[sel.selectedIndex];
      btn.textContent = o ? o.text : '';
      btn.disabled = sel.disabled;
      terakhir = sel.value + '|' + sel.disabled;
    }

    function isi() {
      menu.innerHTML = '';
      Array.prototype.forEach.call(sel.children, function (node) {
        if (node.tagName === 'OPTGROUP') {
          var g = document.createElement('div');
          g.className = 'cs-grup'; g.textContent = node.label;
          menu.appendChild(g);
          Array.prototype.forEach.call(node.children, function (o) { tambah(o); });
        } else tambah(node);
      });
    }

    function tambah(o) {
      var it = document.createElement('div');
      it.className = 'cs-item' + (o.selected ? ' dipilih' : '') + (o.disabled ? ' mati' : '');
      it.setAttribute('role', 'option');
      it.dataset.index = o.index;
      it.innerHTML = '<span></span>' + (o.selected ? '<i class="bi bi-check2"></i>' : '');
      it.firstChild.textContent = o.text;
      if (!o.disabled) {
        it.addEventListener('mousedown', function (e) { e.preventDefault(); });
        it.addEventListener('click', function () { pilih(o.index); });
      }
      menu.appendChild(it);
    }

    function pilih(i) {
      var berubah = sel.selectedIndex !== i;
      sel.selectedIndex = i;
      tutup();
      label();
      if (berubah) sel.dispatchEvent(new Event('change', { bubbles: true }));
      btn.focus();
    }

    function posisi() {
      var r = btn.getBoundingClientRect();
      var ruangBawah = window.innerHeight - r.bottom - 12;
      var ruangAtas = r.top - 12;
      var tinggi = Math.min(menu.scrollHeight + 2, 300);
      var keAtas = ruangBawah < Math.min(tinggi, 160) && ruangAtas > ruangBawah;
      menu.style.minWidth = r.width + 'px';
      menu.style.left = Math.max(8, Math.min(r.left, window.innerWidth - menu.offsetWidth - 8)) + 'px';
      menu.style.maxHeight = Math.max(120, Math.min(300, keAtas ? ruangAtas : ruangBawah)) + 'px';
      if (keAtas) { menu.style.top = ''; menu.style.bottom = (window.innerHeight - r.top + 6) + 'px'; }
      else { menu.style.bottom = ''; menu.style.top = (r.bottom + 6) + 'px'; }
    }

    function buka() {
      if (sel.disabled) return;
      tutup();
      isi();
      menu.classList.add('open');
      btn.setAttribute('aria-expanded', 'true');
      terbuka = { menu: menu, btn: btn };
      posisi();
      var dipilih = menu.querySelector('.dipilih');
      if (dipilih) menu.scrollTop = Math.max(0, dipilih.offsetTop - 60);
    }

    function geser(arah) {
      var items = Array.prototype.filter.call(menu.querySelectorAll('.cs-item'), function (x) { return !x.classList.contains('mati'); });
      if (!items.length) return;
      var skrg = menu.querySelector('.cs-item.aktif') || menu.querySelector('.cs-item.dipilih');
      var idx = Math.max(-1, items.indexOf(skrg)) + arah;
      idx = Math.min(items.length - 1, Math.max(0, idx));
      items.forEach(function (x) { x.classList.remove('aktif'); });
      items[idx].classList.add('aktif');
      items[idx].scrollIntoView({ block: 'nearest' });
    }

    btn.addEventListener('click', function () { (terbuka && terbuka.btn === btn) ? tutup() : buka(); });
    btn.addEventListener('keydown', function (e) {
      var aktif = terbuka && terbuka.btn === btn;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (!aktif) buka(); geser(e.key === 'ArrowDown' ? 1 : -1); }
      else if ((e.key === 'Enter' || e.key === ' ') && aktif) {
        var a = menu.querySelector('.cs-item.aktif');
        if (a) { e.preventDefault(); pilih(parseInt(a.dataset.index, 10)); }
        else { e.preventDefault(); tutup(); }
      } else if (e.key === 'Escape') tutup();
    });

    sel.addEventListener('change', label);
    label();
    // Menangkap perubahan nilai lewat skrip (mis. reset form)
    var pemantau = setInterval(function () {
      if (!sel.isConnected) { clearInterval(pemantau); menu.remove(); return; }
      if (terakhir !== sel.value + '|' + sel.disabled) label();
    }, 400);
  }

  document.addEventListener('mousedown', function (e) {
    if (terbuka && !terbuka.menu.contains(e.target) && !terbuka.btn.contains(e.target)) tutup();
  });
  window.addEventListener('resize', tutup);
  window.addEventListener('scroll', function (e) { if (terbuka && !terbuka.menu.contains(e.target)) tutup(); }, true);

  function aktifkan(root) {
    (root || document).querySelectorAll('select.form-select:not([multiple]):not([data-native]):not(.cs-native)').forEach(function (sel) {
      if ((sel.size || 0) > 1) return;
      bangun(sel);
    });
  }
  window.aktifkanSelectKustom = aktifkan;
  aktifkan(document);
})();


/**
 * Tautan/tombol yang mengikuti isi form filter terbaru (karena filter berubah tanpa memuat ulang):
 *  - <a data-live-href="URL" data-live-from="#form">  -> href dihitung saat diklik
 *  - <button data-open-form="URL">                    -> membuka URL + filter di tab baru
 */
(function () {
  function parameter(form) {
    var p = new URLSearchParams(new FormData(form));
    ['modul', 'page', 'page2', 'limit', 'per_page'].forEach(function (k) { p.delete(k); });
    Array.from(p.keys()).forEach(function (k) { if (p.get(k) === '') p.delete(k); });
    return p.toString();
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-live-href]');
    if (a) {
      var f = document.querySelector(a.getAttribute('data-live-from'));
      if (f) { var dasar = a.getAttribute('data-live-href'), q = parameter(f); a.href = dasar + (q ? (dasar.indexOf('?') >= 0 ? '&' : '?') + q : ''); }
      return;
    }
    var b = e.target.closest('[data-open-form]');
    if (b) {
      e.preventDefault();
      var form = b.closest('form');
      var q = form ? parameter(form) : '';
      var dasarB = b.getAttribute('data-open-form');
      window.open(dasarB + (q ? (dasarB.indexOf('?') >= 0 ? '&' : '?') + q : ''), '_blank');
    }
  });
})();


/**
 * Popup formulir: elemen dengan data-popup="URL" (dan data-popup-title) membuka formulir
 * di dalam modal. Formulir dikirim lewat AJAX; bila ada kesalahan, formulir ditampilkan
 * ulang lengkap dengan pesan; bila berhasil, modal ditutup dan halaman dimuat ulang.
 */
(function () {
  var modalEl = null, modal = null, bodyEl = null, titleEl = null;

  function siapkan() {
    if (modalEl) return;
    modalEl = document.createElement('div');
    modalEl.className = 'modal fade';
    modalEl.tabIndex = -1;
    modalEl.innerHTML =
      '<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">' +
      '<div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>' +
      '<div class="modal-body"></div></div></div>';
    document.body.appendChild(modalEl);
    titleEl = modalEl.querySelector('.modal-title');
    bodyEl = modalEl.querySelector('.modal-body');
    modal = new bootstrap.Modal(modalEl);
    modalEl.addEventListener('hidden.bs.modal', function () { bodyEl.innerHTML = ''; });
  }

  function foto(form) {
    var p = [];
    new FormData(form).forEach(function (v, k) {
      if (k === 'csrf_token') return;
      p.push(k + '=' + (v instanceof File ? v.name + ':' + v.size : v));
    });
    return p.join('&');
  }

  // Gambar QR ([data-qr]) dan hitung mundur ([data-countdown]) pada isi popup
  function hiasi() {
    bodyEl.querySelectorAll('[data-qr]').forEach(function (el) {
      var gambar = function () {
        var q = window.qrcode(0, 'M');
        q.addData(el.getAttribute('data-qr'));
        q.make();
        el.innerHTML = q.createImgTag(5, 4);
        var img = el.querySelector('img');
        if (img) { img.style.maxWidth = '100%'; img.style.height = 'auto'; }
      };
      if (window.qrcode) { gambar(); return; }
      var sk = document.createElement('script');
      sk.src = el.getAttribute('data-qr-lib');
      sk.onload = gambar;
      document.head.appendChild(sk);
    });
    var cd = bodyEl.querySelector('[data-countdown]');
    if (cd) {
      var akhir = parseInt(cd.getAttribute('data-countdown'), 10) * 1000;
      var iv = setInterval(function () {
        if (!cd.isConnected) { clearInterval(iv); return; }
        var sisa = Math.max(0, Math.floor((akhir - Date.now()) / 1000));
        cd.textContent = sisa ? String(Math.floor(sisa / 60)).padStart(2, '0') + ':' + String(sisa % 60).padStart(2, '0') : 'kedaluwarsa';
      }, 1000);
    }
  }

  function pasang() {
    hiasi();
    if (window.aktifkanSelectKustom) window.aktifkanSelectKustom(bodyEl);
    var form = bodyEl.querySelector('form[data-popup-form]');
    if (!form) return;
    var tombol = form.querySelector('button[type="submit"]');

    // Form edit: tombol simpan aktif hanya bila ada perubahan
    if (form.hasAttribute('data-dirty-guard') && tombol && !bodyEl.querySelector('.alert-danger')) {
      var awal = foto(form);
      var cek = function () { tombol.disabled = foto(form) === awal; };
      cek();
      form.addEventListener('input', cek);
      form.addEventListener('change', cek);
      var iv = setInterval(function () { if (!form.isConnected) clearInterval(iv); else cek(); }, 400);
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var label = tombol ? tombol.innerHTML : '';
      if (tombol) { tombol.disabled = true; tombol.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...'; }
      fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
        .then(function (r) {
          if ((r.headers.get('content-type') || '').indexOf('application/json') > -1) {
            return r.json().then(function (j) { if (j.ok) { modal.hide(); location.reload(); } });
          }
          return r.text().then(function (h) { bodyEl.innerHTML = h; pasang(); });
        })
        .catch(function () {
          if (tombol) { tombol.disabled = false; tombol.innerHTML = label; }
          bodyEl.insertAdjacentHTML('afterbegin', '<div class="alert alert-danger">Gagal mengirim data. Periksa koneksi lalu coba lagi.</div>');
        });
    });

    var fokus = form.querySelector('input:not([type="hidden"]):not([type="file"])');
    if (fokus) setTimeout(function () { fokus.focus(); }, 350);
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-popup]');
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
    e.preventDefault();
    siapkan();
    titleEl.textContent = a.getAttribute('data-popup-title') || '';
    bodyEl.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
    modal.show();
    fetch(a.getAttribute('data-popup'), { credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (h) { bodyEl.innerHTML = h; pasang(); })
      .catch(function () { bodyEl.innerHTML = '<div class="alert alert-danger mb-0">Gagal memuat formulir.</div>'; });
  });
})();