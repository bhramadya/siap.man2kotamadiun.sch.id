# Device enrolment refactor (SIAP)

**Branch:** `feat/simplify-enrolment`. It is a local worktree at `/work/repos/siap.man2kotamadiun.sch.id/.worktrees/simplify-enrolment`, based on `vswindows` (`c23662f`). It has not been pushed.
**Head:** `6643cb6` (4 commits). 20 files changed: 530 lines added, 686 removed.

```
f7d8dfb refactor(perangkat): one enrolment credential (admin Kode Pasangan) behind the GET_LOCK limiter
b651c54 feat(presensi): one 'Daftarkan Perangkat' screen for students
f035078 db+docs: pairing_attempts table, cap legacy unlimited codes, drop device_wajib_kode from schema
6643cb6 chore(perangkat): fix stale function name in comment
```

## New flow

There is now **one credential**: a 6-digit **Kode Pasangan** issued by an admin. It is stored hashed, works once, expires, and locks after 5 wrong tries. A single issue shows the code as digits **and** as a QR; both are the same code. There is **one student screen**: **Daftarkan Perangkat** (`presensi/daftar_hp.php`).

**Admin**
1. **One student:** go to Manajemen Perangkat or Data Siswa, choose **Kode Pasangan**, then **Buat Kode**. The popup shows the code and a QR. Both are valid for 10 minutes.
2. **A whole class or grade:** open the **Kode Pasangan** menu, pick the class or grade and a validity of 10 minutes to 7 days, then **Buat**. You get one code per student; students who already have a device are skipped. Print it, export to Excel or copy it.
3. **Changed phone:** reset the device first (no change here), then issue a code as above.

**Student**
1. On the home page or the presensi page, tap **Daftarkan Perangkat**. Alternatively, scan the admin's QR with the phone camera; the screen then opens with NIS and code already filled in.
2. Enter NIS or NISN and the code, then tap **Daftarkan Perangkat Ini**. The server sets the `SIAP_PERANGKAT` cookie.
3. Scan the gate or class QR as usual. The phone is recognised and check-in works.

**The personal QR card** can no longer bind a device, either directly or through the "first scan auto-registers" path. While device binding is **on**, the card is not used at all: `presensi/scan.php` sends the student back to device identification. While binding is **off**, the card still identifies the student for check-in. That is its only legitimate remaining use, and no binding is created.

## What was removed

- **Auto-enrolment on first check-in.** This was the photo loophole on the default `device_wajib_kode = 0`. `check_or_register_device()` became `check_device_binding()`: it only checks, and an unbound phone now gets `PAIRING_REQUIRED`.
- **The QR Pasangan token credential.** This covers `daftarkan_dengan_token`, `hash_token_pasangan`, `url_pasangan` and the `?t=` landing page.
- **Two of the three branches in `api/pasangan.php`:** the token branch and the session + code branch. Only NIS + code remains.
- **Bulk codes with "Tanpa batas waktu".** `KODE_TANPA_BATAS` and `buat_kode_pasangan_massal` were merged into one issuer, `terbitkan_kode_pasangan()`, with a 7-day maximum.
- **Redundant student screens:**
  - the home-page dialog with Kode/QR tabs and its own camera scanner (`html5-qrcode` is no longer loaded on the home page);
  - the NIS + code overlay in `presensi/index.php`;
  - the code box in `konfirmasi.php` and its "Perangkat baru akan didaftarkan" box;
  - the "device now registered" note in `berhasil.php`.
- **The "Wajib Kode/QR Pasangan" setting** (settings UI, save logic and auto-migration), plus every instruction text that mentioned QR Pasangan, "CARA 1/2", "terdaftar otomatis saat scan pertama" or "presensi berikutnya".

## Files

| File | Change |
|---|---|
| `includes/device_binding.php` | `check_device_binding()` (never binds); new section with one issuer, one enrol function (`daftarkan_perangkat_dengan_kode`), the GET_LOCK limiter wrappers, `sql_kode_pasangan_berlaku()` and `url_daftar_perangkat()`; token and auto-register code removed |
| `api/pasangan.php` | One branch: NIS + kode + device_uuid |
| `api/scan.php` | Preview state is `NEED_PAIRING` for any unbound phone and `NOT_REQUIRED` when binding is off |
| `includes/presensi_core.php` | Calls `check_device_binding`; `device_new` removed; docs updated |
| `includes/functions.php` | `get_settings()` no longer auto-adds `device_wajib_kode` |
| `presensi/daftar_hp.php` | Rewritten as **the** Daftarkan Perangkat screen. Prefill comes from `#nis=..&kode=..` (a fragment that never reaches the server and is wiped from the URL); it checks first whether the phone is already registered |
| `presensi/index.php` | Overlay removed. An unregistered phone gets "Daftarkan Perangkat"; the personal-card scanner shows only when binding is off |
| `presensi/konfirmasi.php` | Code box and new-device path removed; `NEED_PAIRING` links to the screen |
| `presensi/scan.php` | Personal card is ignored while binding is on |
| `presensi/berhasil.php` | Message plus a "Daftarkan Perangkat" button for `PAIRING_REQUIRED`; `device_new` note removed |
| `beranda.php` | Enrolment dialog, tabs, scanner and their CSS/JS removed; buttons open the screen |
| `panel/kode_pasangan.php` | Shows one code and its QR (same code); "Wajib" warning removed |
| `panel/kode_massal.php` | No unlimited option; uses `terbitkan_kode_pasangan`; "Wajib" notice removed |
| `panel/pengaturan.php` | "Wajib Kode/QR Pasangan" switch removed; one help line under Device Binding |
| `panel/device.php`, `panel/siswa_detail.php`, `panel/qr_generate.php` | Text only: new devices need a Kode Pasangan; the card cannot register a device |
| `database/database.sql` | Adds the `pairing_attempts` table; removes `settings.device_wajib_kode` (fresh installs only) |
| `database/migration_pendaftaran_perangkat.sql` | **New** and idempotent: creates `pairing_attempts`, and sets `device_wajib_kode = 1` if the column exists (so a rollback stays safe). Nothing is dropped |
| `README.md` | Section 8 and the upgrade steps describe the single method and the limiter |

No files were deleted. `daftar_hp.php` keeps its name, but its content was replaced.

## How each security mechanism is preserved

- **GET_LOCK rate limiting**
  - Login and portal are untouched (`functions.php: pembatas_mulai`).
  - **New:** enrolment now goes through the same limiter. `device_binding.php: daftarkan_perangkat_dengan_kode` calls `pasangan_mulai_percobaan` and then `pembatas_mulai('pairing_attempts', …)`. The limits are:
    - per NIS + IP: 5 failures per 15 minutes (hard lock);
    - per IP: 60 failures per 15 minutes;
    - a progressive delay above 20 failures per NIS.
  - Before this change, enrolment had only the session counter `pair_try` (bypassable) and the per-code lock. Both are kept.
  - The per-code attempt is now **reserved atomically** (`UPDATE … percobaan < 5`) before the hash is compared, so parallel guesses cannot exceed 5.
  - On a database error the limiter fails open, the same as login and portal, and the per-code lock still applies.
- **SIAP_PERANGKAT cookie (creation, checking, legacy, `device_legacy_until`)**
  - These functions are unchanged: `terbitkan_rahasia_perangkat`, `kirim_cookie_perangkat`, `binding_dari_cookie`, `cari_binding_perangkat`, `cara_cocok_perangkat`, `uuid_lama_diizinkan`, `selesaikan_migrasi_perangkat` and `migrasi_pasif_perangkat`.
  - A new binding is still issued through `_selesaikan_pendaftaran_pasangan` → `terbitkan_rahasia_perangkat` (`'daftar'`, active).
- **Same-day takeover block:** `rahasia_boleh_diaktifkan` and `selesaikan_migrasi_perangkat` are unchanged. Re-checked by running: a legacy binding goes to *pending* (`checkin`) and is not activated on the same day.
- **IP checking**
  - `get_client_ip()` is still stored on the binding (`_selesaikan_pendaftaran_pasangan`) and still checked in `check_device_binding`.
  - The limiter keys on `kunci_ip_pembatas()`, which handles trusted proxies and IPv6 /64.
- **CSRF:** `csrf_require()` is still called in `panel/kode_pasangan.php` and `panel/kode_massal.php` (both create and cancel), and in `pengaturan`. `api/pasangan.php` had no CSRF before and still has none: it is a public JSON endpoint whose protection is the code plus the limiter.
- **Code expiry and single use**
  - Codes are only accepted inside `sql_kode_pasangan_berlaku()`: unused, `expires_at > NOW()`, **and** no more than 7 days old.
  - Use is atomic: `UPDATE … used_at` with `rowCount() === 1`.
  - Issuing a new code cancels the student's older codes (`terbitkan_kode_pasangan`).
- **Hashing:** `hash_kode_pasangan()` and `hash_equals` are unchanged; only hashes are stored.
- **One device per student, one student per device:** the "bound to another student" check and the UNIQUE race net are kept in `_selesaikan_pendaftaran_pasangan`.
- **`base_url` required:** untouched (`config/database.php`). The QR URL is built from `BASE_URL` (`url_daftar_perangkat`).

## Database and migration

- Existing `device_bindings` rows are untouched, so **already-bound devices keep working with no re-enrolment**. This was verified for cookie bindings and for legacy UUID-only bindings.
- Some old data is left in place and is now unused or narrowed: `device_pairing_codes.token_hash`, `settings.device_wajib_kode` and `siswa.qr_token` (the card is still used when binding is off).
- `pairing_attempts` is created automatically by the app. The migration file is for hosts without the CREATE privilege.
- Old "Tanpa batas waktu" codes now stop working 7 days after they were created. This is enforced in code, and no data is changed.

## Deploy notes

1. Upload the code, then import `database/migration_pendaftaran_perangkat.sql`. This is optional if the database user can run CREATE.
2. Tell students that the QR card no longer registers a phone.
3. Before or right after the deploy, generate per-class codes for students who aren't bound yet (menu **Kode Pasangan**; bound students are skipped automatically). With the old default (`device_wajib_kode = 0`), those students would have self-registered on their first scan; now they need a code.

## Verified

**By running** (PHP 8.3.13 CLI static build + MariaDB 10.11.9, both run as an unprivileged user, `php -S`; 54/54 checks passed):
- `php -l` on all 17 changed PHP files.
- Admin creates a code in the popup:
  - the QR encodes `daftar_hp.php#nis=1001&kode=<same code>`;
  - only a hash is stored, with 10-minute validity.
- Student enrolment:
  - a wrong code returns "Sisa percobaan: 4";
  - the correct code returns `PAIRED` and sets `SIAP_PERANGKAT` (64 hex characters) on an ACTIVE binding with an active secret;
  - the code then cannot be reused.
- Check-in after enrolment: `api/scan` device returns `MATCH`, preview returns `MATCH`, and `api/presensi` succeeds.
- **Personal QR on an unbound phone does not bind:**
  - binding ON: the card redirects, check-in returns `NO_SESSION`, and no binding is created;
  - binding OFF: the card identifies the student and check-in succeeds, with no binding created;
  - card session, then binding turned ON: preview returns `NEED_PAIRING`, check-in returns `PAIRING_REQUIRED`, and nothing is bound.
- **Rate limiting** (a fresh session on every request, so `pair_try` is bypassed):
  - 5 wrong codes, then `TOO_MANY`;
  - an unknown NIS gets the generic error, then `TOO_MANY`;
  - a locked code is rejected even when correct;
  - a burst of 30 parallel requests compared at most 5 guesses (`percobaan ≤ 5`).
- A phone already bound to another student is rejected (`DEVICE_ALREADY_BOUND`), the code is not used up, and a code only works for its own student.
- A legacy unlimited code older than 7 days is rejected.
- Bulk codes per class: bound students are skipped; a tampered `masa=0` falls back to 1 day; a bulk code works with an NISN, and check-in works afterwards.
- A legacy UUID-only binding checks in without re-enrolment and moves to *pending* (`checkin`).
- The migration runs twice on the old `vswindows` schema and once on the new schema. The new code works on an old database (with `token_hash` in place and `pairing_attempts` auto-created).
- Pages:
  - Pengaturan saves without the removed column;
  - 12 public and admin pages return 200 with no PHP warnings in the server log;
  - the home page no longer loads the scanner or the tabs.
- A grep finds no remaining references to the removed functions, endpoints, settings or texts. The only matches left are comments and the migration explaining the leftover columns.

The test harness is in `/work/bots/siap/enrolment/test/` (`reset.sh`, `gate.php`, `e2e_enrolment_test.py`; it expects the /tmp PHP and MariaDB paths noted inside).

**Not verified:** real phones, the SIAPApp Android WebView, camera QR scanning, and the look of the new screens (the HTML was checked by reading, not rendered). Apache, Nginx, HTTPS and MySQL 8 were not tested; `GET_LOCK` behaves the same in MySQL 8.

## Open decisions for the school

1. **Old unlimited bulk codes stop 7 days after they were created.** On deploy, any sheet printed more than 7 days ago becomes invalid; reissue for that class with one click. If you'd rather keep old sheets valid, remove the `created_at` clause in `sql_kode_pasangan_berlaku()`. I don't recommend that, because codes on paper that never expire are the B4 risk.
2. **Students who aren't bound yet need a code.** On the old default they enrolled themselves. Plan an onboarding moment: the wali kelas hands out the class sheet.
3. **Possible follow-ups, not done here:**
   - **B3**, a single "Ganti perangkat" (reset plus new code) action;
   - **B2**, removing the orphan pages `qr_print` and `qr_kelas_print`;
   - removing the personal-card admin pages entirely if the school never turns binding off.
