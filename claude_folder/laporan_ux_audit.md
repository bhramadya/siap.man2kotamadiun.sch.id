## 💡 UX & Feature Strategy Audit (Claude)

**Date:** 22 October 2026 · **Code read:** an exported snapshot of the `vswindows` branch (the security-fix code plus ngrok tooling). I read `presensi/*`, `api/*`, `includes/*`, `assets/js/*`, `beranda.php`, `login.php`, `portal_siswa/*` and the 35 `panel/*` modules. **Method:** I read the code and traced the flows by hand. Nothing was run on a phone, and I have no usage data, so any timing or percentage below is an estimate and is labelled as one.
**Constraints respected:** I keep every decision from `fixes/fix-summary.md` and `review/fix-branch-final-review.md`: the GET_LOCK login limiter, known IPs, the progressive delay, the two-phase cookie re-bind, the forced password change, HMAC class QRs and the required `base_url`. When a recommendation touches one of them, the trade-off is stated next to it.

> **This snapshot is not the `front` branch.** Several items marked "Selesai" in the sections above are **not** in this code. I checked: `api/` has 5 files and no `api/quick.php`. There is no `includes/ratelimit.php` (APCu), no manifest or service worker (PWA), and no settings cache. `device.js` is loaded without a version (`presensi/index.php:167`). `includes/header.php:50-51` and `panel/qr_*.php` still load Bootstrap from jsdelivr. `panel/kode_massal.php:12-15` still offers "Tanpa batas waktu". The findings below describe the code as it stands. Where the `front` report says that branch already changes a flow, a **Front-branch note** says so.

**Legend:** Effort **S** ≤ 1 day · **M** 2–5 days · **L** > 1 week. Impact is the benefit to students and staff. Risk covers regressions and any security trade-off.

---

### A. User Flow Analysis & Simplification (Student & Admin)

#### A.1 The daily check-in (a student whose phone is already registered: about 99% of traffic, mostly between 06:50 and 07:00)

**Path 1: the phone's own camera scans the gate QR and the student taps the link** (the gate QR encodes `BASE_URL/presensi/?gate=<token>`, `panel/qr_gerbang.php:40`)

| # | What happens | Type | Code |
|---|---|---|---|
| 1 | `GET /presensi/?gate=T`. The gate token is checked and stored in the session. The page loads Bootstrap, the icons, 2 CSS files, `device.js` and **html5-qrcode (375 KB)**, even though no camera is needed on this branch | page load 1 | `presensi/index.php:11-23, 166` |
| 2 | `POST api/scan.php` (mode `device`) identifies the student from the device cookie. **In parallel**, `POST api/cek_perangkat.php` runs only to decide whether to show the "Daftarkan Perangkat" button | 2 requests | `presensi/index.php:207-216, 246-257`, `api/scan.php:28-50` |
| 3 | On `MATCH`, a JavaScript redirect opens the confirm page | page load 2 | `presensi/index.php:216` |
| 4 | `POST api/scan.php` (mode `preview`) checks the gate token, the student, already-present and the device binding **a second time** | request | `presensi/konfirmasi.php:160-201`, `api/scan.php:52-…` |
| 5 | **Gate QR:** the student taps **KONFIRMASI PRESENSI**. Only then is GPS requested (`enableHighAccuracy`, `maximumAge: 0`, `timeout: 15000`). **Class QR:** the button is clicked automatically | tap + GPS wait | `presensi/konfirmasi.php:23-28, 188-193, 243-275` |
| 6 | `POST api/presensi.php`. `proses_simpan_presensi()` checks the token and the device **a third time** (`check_or_register_device`), then the hours, GPS and insert | request | `includes/presensi_core.php:114-263` |
| 7 | Redirect to the result page | page load 3 | `presensi/berhasil.php` |

**Total: 3 page loads, 4 API requests, 2 taps** (1 for a class QR), plus a GPS wait that only starts after the tap.

**Path 2: from the home page or the SIAPApp WebView.** The home page `beranda.php` loads and calls `api/beranda` and `api/cek_perangkat`, then polls `api/beranda` every 8 s (`beranda.php:360`). The student taps **Scan Absen** and `presensi/index.php` loads, calling `cek_perangkat` again. The camera decodes the gate QR, and the whole page **reloads** with `?gate=` instead of calling the API in place (`presensi/index.php:198`). Steps 2–7 then follow. **Total: 5 page loads, 7 API requests (`cek_perangkat` 3 times), 2 taps.**

**Friction points**

- **F1: the same student is identified three times.** For a `MATCH` device, the preview call (step 4) adds no information. Steps 2 and 6 already decide everything.
- **F2: the gate and class QRs behave differently for no stated reason.** A class QR auto-confirms, a gate QR asks for a tap (`konfirmasi.php:23-28`). Students who use both learn two behaviours.
- **F3: GPS starts late, and a failure is expensive.** GPS starts after the tap with `maximumAge: 0`, so a cheap Android phone indoors can wait seconds for a fix. If the fix fails, the request is sent with `null`, the server answers `GPS_REQUIRED`, and `api/presensi.php:42` clears the session on **every** failure. "Coba Lagi" then means scanning the QR again. The `front` report raised the same issue as RISK-4.
- **F4: the gate token dies when the screen refreshes, not when it expires.** `generate_qr_dinamis()` marks every `AKTIF` token of the same `lokasi` as `EXPIRED` each time it creates a new one (`includes/qr_dinamis.php:24-26`). The gate page creates a new token on every refresh (`panel/qr_gerbang.php:9, 15, 48-52`). A student's real window is therefore *the seconds left on the countdown*, not `qr_dinamis_durasi_detik`. With 3 page loads, a tap and a GPS fix (roughly 5–20 s at peak), anyone who scans late in the cycle gets `GATE_INVALID`. My unmeasured estimate is 5–15% of peak scans at the default 120 s. Worse, **two gate monitors that use the default `lokasi=Gerbang Utama` cancel each other's tokens** on every refresh. Signed class tokens already allow +180 s (`QR_KELAS_TOLERANSI_DETIK`, `qr_dinamis.php:63, 85`), so the gate is the odd one out.
- **F5: an `ALREADY_PRESENT` answer from the save step shows a red failure screen.** Only the preview path redirects to the friendly "Anda Sudah Presensi" view (`konfirmasi.php:173`). The save path goes to `berhasil.php?status=error` (`konfirmasi.php:258`), which shows "Presensi Tidak Berhasil". The case is rare today, but with the fast path in A1 a second scan would end up here every time.
- **F6: the home page is a dead end for new students on the default setting.** `device_wajib_kode` defaults to 0 (`database/database.sql:122`), so `/presensi` would let a new phone self-register through the student's personal QR card. But **Scan Absen** on the home page refuses any device that isn't registered and only offers the pairing-code dialog (`beranda.php:389-405`; `api/cek_perangkat.php:26` returns `BELUM` whatever the setting).
- **F7: the binding belongs to one browser.** If the camera app opens a different browser from the one that was bound, the student is "not recognised". The UI only warns about this in small print (`presensi/index.php:151`, `presensi/daftar_hp.php:48`). Path 2, inside the app, avoids the problem and should be the path the school teaches.

#### A.2 Is the confirm step (`presensi/konfirmasi.php`) still needed?

| What the step could protect against | Does the tap stop it? | What actually protects |
|---|---|---|
| Accidental scan | Hardly. The gate scan is deliberate (the camera is pointed at a screen), and the record is true (the student is at the gate), once per day (`uniq_siswa_tanggal`), with the server's time | Server time and the duplicate check |
| Wrong student on a shared phone | **No.** For `identified_via = 'device'` the identity comes from the binding (UNIQUE `device_hash` and `siswa_id`; `CLAIMED_BY_OTHER`), and the tap doesn't change it. **Yes** when the identity came from a personal QR card (`presensi/scan.php:29-30`): the name and photo are a real "is this you?" check against a borrowed or photographed card | Device binding |
| Binding a new phone by mistake | **Yes, keep it.** The binding is permanent, and a reset is capped at `maks_reset_device_per_bulan` (default 2, `reset_device()`) | The confirm step (`warnBox`) |
| A friend checking in for you, or a forwarded QR | No. The cheater taps willingly | GPS and short-lived QRs (H3) |

**Verdict:** drop the tap when `identified_via === 'device'` and the device state is `MATCH`, which is exactly what class QRs already do. Keep the confirm screen for the personal-QR path and for registering a new device. **Security cost: none that I can measure.** On installs that still run GPS off with permanent class QRs, the fix is H3 (turn GPS on), not a button.

#### A.3 Recommendations: check-in

| ID | Recommendation | Effort | Impact | Risk / security trade-off |
|---|---|---|---|---|
| **A1** | **Zero-tap fast path for registered devices.** *Step 1 (S):* in `konfirmasi.php` also set `$autoKonfirmasi` when `$_SESSION['identified_via'] === 'device'` (a one-condition change). *Step 2 (M):* in `presensi/index.php`, when `api/scan` returns `MATCH`, call `api/presensi` straight away and skip `konfirmasi.php` and the preview. Start `navigator.geolocation` **when the page loads**, in parallel with `api/scan` (`maximumAge` about 30 s), so the fix is ready. Drop the `cek_perangkat` call (show "Daftarkan" only on `NOT_FOUND`). Load html5-qrcode only when a camera is actually needed. In Path 2, decode in the page and `fetch`, with no `?gate=` reload. Treat `ALREADY_PRESENT` as success (F5) by returning its data or keeping `identified_siswa_id` for that code. **Result:** Path 1 goes from 3 page loads, 4 requests and 2 taps to **2 page loads, 2 requests and 1 tap** (opening the link). Path 2 goes from 5 page loads, 7 requests and 2 taps to about **3 page loads, 4 requests and 1 tap**. | S → M | **Very high** (about 1,000 times a day, at peak) | Low. The server is unchanged and still runs all 11 checks in `proses_simpan_presensi()`. Regression-test the `konfirmasi` states (NEW, NEED_PAIRING, MISMATCH, BLOCKED). **Front-branch note:** the `front` report says `api/quick.php` (one request per check-in) is done on that branch (§3.4 and priority table item 8). If it is merged, use it as the endpoint; starting GPS first and removing the tap still apply. The iOS-17 and SIPIKET redesign (roadmap B) restyles `konfirmasi.php` but keeps the confirm and cancel buttons, so it doesn't remove the step. |
| **A2** | **Give gate tokens a grace period instead of killing them on refresh.** In `generate_qr_dinamis()`, stop expiring the previous token straight away. Let `kadaluarsa_at` (the displayed duration plus about 60–90 s) decide, the same model the class QR already uses. Give each screen its own `lokasi` (C1 does this automatically). | S | High (removes random `GATE_INVALID` rescans and the two-monitor collision) | A forwarded screenshot stays usable for up to about 200 s instead of up to 120 s. GPS stays the main control, and class QRs already accept +180 s. |
| **A3** | **Recover from GPS errors in place.** Check `navigator.permissions` before sending. On `GPS_REQUIRED`, keep the session (an exception in `api/presensi.php:42`) and show an in-page "turn on location, then retry" card. | S | Medium–high (the most common real-world failure on low-end phones) | Low. The gate token is still checked again on retry. |
| **A4** | **Fix the home-page dead end (F6).** Return `device_wajib_kode` from `api/cek_perangkat` and only block **Scan Absen** when it is 1. If B1 below is adopted, this disappears. | S | Medium (onboarding on the default setting) | None |

#### A.4 Login flows

- **Staff.** `login.php` already has one form for staff and students. The limiter, known IPs and the forced change don't affect a normal login. What remains:
  - **(a) An extra redirect, and a 403 for some operators.** After login, `login.php:62` sends `index.php?modul=dashboard`, which costs a 301 to `/dashboard` (`index.php:105-115`). It also gives a 403 to an operator without `dashboard.lihat`. Redirecting to `BASE_URL . '/'` would use the existing "first page this user may see" logic (`index.php:89-95`). **S**, no risk.
  - **(b) Staff log in again many times a day.** The session cookie has `lifetime 0` (`includes/keamanan.php:44`) and no idle timeout is set, so PHP's default of about 24 minutes applies (README §8b says the same for the portal). An explicit idle limit such as 2 hours, the same as `PORTAL_IDLE_DETIK`, would help. **S.** *Trade-off:* an unattended PC stays logged in longer. Kiosk mode (C1) removes the main reason panel sessions sit open on shared PCs, so the net risk falls.
  - **(c) The login page is heavy.** It loads all of Bootstrap, the icon font and **two** Google Fonts families for a two-field form (`login.php:110-115`). This is cosmetic; leave it to the `front` redesign.
- **Student portal.** Students need a **second admin-issued credential**: an 8-character activation code valid for 7 days (`panel/akun_siswa.php`, `portal_terbitkan_kode()`), then NISN and a password. The bound phone already holds a stronger secret: the HttpOnly `SIAP_PERANGKAT` cookie, whose path is the site root, so `/portal` receives it (`includes/device_binding.php:90-103`).

| ID | Recommendation | Effort | Impact | Risk / security trade-off |
|---|---|---|---|---|
| **A5** | **Fix the staff post-login redirect and set an explicit idle timeout** (points a and b above). | S | Low–medium | See (b) |
| **A6** | **Optional device-trust entry to the portal.** On the home page, a recognised phone sees **"Ajukan izin"**, which starts a portal session only if `binding_dari_cookie()` returns an `ACTIVE` binding with `secret_aktif_at` set. Pending and UUID-only bindings must be refused. NISN and password stay as the fallback (lost phone, binding off). Activation codes are then only needed by the few students who ask for one. | M | Medium–high (removes one credential per student; a leave request becomes 1 tap from the home page) | Anyone holding the unlocked phone can file a leave request in the student's name. The impact is low: staff approve it, the student is notified, and the same person could already check in as the student. Accepting pending or UUID-only bindings **would reopen the copied-UUID residual** in fix-summary, so the code must not do that. The portal login limiter is untouched. |

#### A.5 The admin's day

| Task | Today | Friction |
|---|---|---|
| Gate screen | Log in on the gate PC and open `/qr/gerbang` | The session cookie dies when the browser restarts (`keamanan.php:44`), so someone logs in every morning |
| Classroom screens (secure mode, `qr_kelas_permanen = 0`) | **Every classroom device** stays logged in to the panel with an account that has `qr_kelas.lihat` | Staff credentials sit on 30 or more classroom PCs. The **Regenerate** button is on screen for anyone with `qr_kelas.generate` (`panel/qr_kelas.php:117`). One "Back" press lands in the panel. This is why schools keep permanent class QRs despite the red warning. **Fix: C1** |
| Following up absent students | The dashboard shows a **count** of "Belum Presensi" (`panel/dashboard.php:42, 175`) and says "tindak lanjuti siswa yang belum presensi" (`:229`) | **No page lists who is missing.** Data Presensi only lists rows that exist (`panel/presensi.php:10-45`). **`ALPA` is never written anywhere** (no `INSERT` sets it), so the Alpa column in `rekap.php` is always 0. Staff can't record attendance or izin for a student (I4 in fix-summary, left unfixed). **Fix: C2** |
| A student changed or reset their phone | Reset dialog (checkbox and reason, `panel/device.php:309-337`), page reload, then the "Kode Pasangan" popup (`device.php:344`), then "Buat QR & Kode" | About 6 clicks across 2 dialogs. The monthly cap of 2 applies to single resets, but a **mass** reset ignores it (`includes/device_binding.php:540`), so admins learn to use a mass reset for one student. After the cookie migration, every cleared cookie means a reset, so this gets more frequent. **Fix: B3** |
| Leave requests | Queue with a badge, bulk approve (`panel/izin.php:36-44`) | Fine |

---

### B. Recommended Features to Remove / Simplify

#### B1. Device enrollment sprawl: the biggest source of bloat

The student-side task "link this phone to me" is implemented through:

| | Count | Where |
|---|---|---|
| **Credentials** | 4 | The personal QR card (`siswa.qr_token`, which never expires and has no bulk regenerate); the 6-digit pairing code (10 min); the QR pairing token (10 min, created with the code); bulk codes (up to "no time limit") |
| **Student screens** | 5 | The home-page dialog with **Kode / QR tabs and its own camera scanner** (`beranda.php:204-240`); the `presensi/index.php` overlay with NIS and code (`:117-155`); the `konfirmasi.php` code box without NIS (`:118-129`); `presensi/daftar_hp.php` (QR token); self-registration through the personal card (`konfirmasi.php` `warnBox`) |
| **API branches** | 3 | `api/pasangan.php`: token (`:31`), NIS and code (`:37`), session and code (`:53`) |
| **Admin modules** | 5 (+ device) | `kode_pasangan`, `kode_massal`, `qr_siswa`, `qr_generate`, `qr_print` |

**Why it hurts**

- **One task, four sets of instructions, and they disagree.** The admin popup tells the student to enter **NIS and code** on the presensi page (`kode_pasangan.php`, "CARA 2"). `konfirmasi.php` asks for the code only. README §8 says the code is typed "saat konfirmasi presensi".
- **On the default setting, the personal QR card is a permanent bearer credential for enrolment.** Anyone with a photo of the card can bind their own phone as that student (C2 in the first audit; still possible here because `device_wajib_kode = 0` by default).
- **The weaknesses the `front` report fixed as D3 are still open in this snapshot.** The session counter `pair_try` (`api/pasangan.php:25-29`) can be bypassed by not sending a session cookie, and bulk codes can last forever.

**What is NOT bloat:** having both a QR pairing token and a 6-digit code. `buat_kode_pasangan()` creates both in **one row**, and using one cancels the other (`device_binding.php:657-674`). That pair is cheap and fits the "student at the admin desk" case well. Keep it.

**Target state.** One credential: an admin-issued pairing code (shown as a QR for single issues and printed as a list for bulk). One student screen: a "Daftarkan Perangkat" dialog (NIS and code), plus the QR landing page `daftar_hp.php`.

- *Step 1 (S–M, no policy change):*
  - Delete the home page's in-page QR scanner tab; the phone's own camera already opens `daftar_hp.php`.
  - Replace the `index.php` overlay and the `konfirmasi.php` code box with the same component.
  - Remove the session-and-code branch of `api/pasangan.php`, which the NIS-and-code branch already covers.
- *Step 2 (M, needs a school decision):*
  - Make `device_wajib_kode = 1` the default.
  - **Retire the personal QR card from check-in:** `presensi/scan.php`, `needPersonalBox` in `presensi/index.php:92-107`, `qr_siswa`, `qr_generate`, `qr_print` and the `qr_siswa.*` permissions.
  - Keep `kode_massal` as the start-of-year onboarding tool.

**What is lost**

- Enrolment without any admin. Replace it with bulk-code sheets per class, handed out by the wali kelas.
- Printed student QR cards. They become decorative if they are also used as ID cards.

**Migration.**

- Existing bindings are untouched.
- Mid-year, flip the setting once Manajemen Perangkat shows most students are bound.
- Keep the `siswa.qr_token` column (`NOT NULL`), so there is no schema change.
- Tell students that the cards no longer work.

**Security.** A net gain: it closes enrolment from a photo of a card and removes two code paths.

**Effort:** S–M plus M. **Impact: High:** fewer screens, fewer support questions, less attack surface. **Risk: Medium:** onboarding-day logistics and a policy decision.

#### B2–B8. Smaller removals and merges

| ID | Item | Evidence | What is lost | Effort | Impact | Risk |
|---|---|---|---|---|---|---|
| **B2** | **Delete the orphan print pages** `panel/qr_print.php` (280 lines) and `panel/qr_kelas_print.php` (347 lines), and their `DAFTAR_MODUL`/route entries | Nothing links to either page (grep). Printing already happens inside the pages: `qr_siswa.php:252` `previewCetakSiswa()` and `qr_kelas.php:385` `cetakKelasGrid()`. `qr_kelas_print` redirects away in signed mode anyway | Old bookmarks | S | Low (maintenance only) | Low |
| **B3** | **Merge reset and re-pair into one "Ganti perangkat" action** (reset, then show the new QR and code in the same dialog). Replace "mass reset bypasses the cap" with an explicit admin override that needs a reason | `device.php:309-344`, `kode_pasangan.php`, `device_binding.php:540` | Nothing. Reason and audit log are kept | S–M | Medium (the most frequent admin support task) | Neutral: same `device.kelola` permission |
| **B4** | **Remove "Tanpa batas waktu" from bulk codes** (maximum 7 days) | `kode_massal.php:12-15`, `KODE_TANPA_BATAS` (`device_binding.php:792`) | Codes on paper that never expire, which are a liability | S | Low–medium | Security gain. **Front-branch note:** per the report, D3 already caps this at 7 days on `front` |
| **B5** | **Delete the dead setting** "Catat & tampilkan validasi IP (informatif)" | `validasi_ip_aktif` is saved (`pengaturan.php:73, 88`) but read nowhere | Nothing (keep the column) | S | Low (one less confusing toggle) | None |
| **B6** | **Delete `portal_siswa/masuk.php`** | The router 301s `/portal/masuk` to `/login` (`portal_siswa/router.php`). README §8b already says it can be deleted (fix-summary minor #2) | Nothing | S | Low | None |
| **B7** | **Cut log noise.** Stop writing `DEVICE_MATCH` on every check-in, and default the log retention to 365 days instead of forever | Each check-in writes **2** security-log rows: `DEVICE_MATCH` (`device_binding.php:432`) and `PRESENCE_SUCCESS` with the device hash (`presensi_core.php:246`). That buries the MISMATCH and anomaly events admins should look at. `log_retensi_hari` defaults to 0, meaning never deleted (`database.sql:116`) | A redundant row per check-in | S | Medium (a readable Log Keamanan; fewer writes at peak) | Low. `PRESENCE_SUCCESS` keeps the forensic trail |
| **B8** | **Make Papan a kiosk display type** (see C1) instead of a page that needs a logged-in panel | Papan (`panel/papan.php`) overlaps the public home-page board, which shows masked names. Papan's real value is full names on a staff-room TV | Nothing; staff can still open it in the panel | Included in C1 | Medium | See C1 |

#### B9. Honest list: what is NOT bloat

- **Data Presensi, Rekap and Dashboard** do different jobs: the daily list and export, the monthly figures per student for printing, and the at-a-glance view. Don't merge them. Link them instead: a dashboard tile opens a filtered Data Presensi, and "Belum Presensi" opens the list in C2. The daily-only export (`presensi_export.php`, one date) is a small gap; a date range would help.
- **The leave workflow is lean.** One form, one approval step, and 5 states that are each needed. `dicabut` (revoked) is rare but it is the only safe way to undo the presensi rows an approval wrote (`izin_cabut()` deletes only `PENGAJUAN` rows). Rules live in constants (`includes/izin.php:17-26`). What is missing is additive (staff-entered izin, notifying parents), not complexity to remove. One practical gap: `izin.*` isn't in `IZIN_DEFAULT_OPERATOR`, so on a default install only admins can approve.
- **Two log pages** (Log Keamanan and Log Aktivitas) serve different readers: security investigation and admin accountability. Merging them into tabs saves one menu item at best.
- **Pengguna and Hak Akses** (2 roles), **Master Kelas** and **Kenaikan Kelas** are used rarely but are essential and small.
- **The security work** (GET_LOCK, known IPs, the two-phase re-bind, the forced change, signed QRs) costs normal users almost nothing. The migration is invisible and the forced change happens once. Keep all of it. The UX cost of security in this code comes from *screens that need logins* (fixed by C1) and *the gate token lifetime* (fixed by A2), not from the protections themselves.

---

### C. Recommended Crucial New Features (ranked by impact ÷ effort)

| Rank | Feature | Impact | Effort | Risk |
|---|---|---|---|---|
| 1 | **C1: Kiosk display links** (gate, class, board) | High | M | Low–medium (see below) |
| 2 | **C2: "Belum hadir" list, manual marking and real ALPA** | High | M | Medium (manual check-in by staff could be abused) |
| 3 | **C3: Absence summary shared through a WhatsApp link** | Medium–high | S | Low (privacy policy) |
| 4 | **C4: Outbound webhook** (signed JSON) | Medium | M | Low–medium |
| 5 | **C5: Automatic WhatsApp to parents through a gateway** | High | L | Medium (consent, cost, data protection) |

**C1: Kiosk display links.** The final security review recommended this, and fix-summary lists it under "Left unfixed".

- *Data:* a new table `layar_tampilan` with these columns: `id`, `jenis` (gerbang, kelas or papan), `kelas`, `lokasi` (UNIQUE), `label`, `token_hash` (CHAR 64), `ip_izin` (optional CIDR list), `dibuat_oleh`, `dibuat_at`, `dicabut_at`, `terakhir_dilihat_at`, `terakhir_ip`.
- *Panel:* a "Layar" page under QR Code. **Create** shows the URL once, plus a QR of the URL so it can be opened on the screen device. **Revoke** takes one click. A "last seen" column catches dead screens.
- *Public side:* `/layar/<43-character base64url token>` serves a minimal page. `/layar/<token>/data` returns JSON every N seconds:
  - **gerbang:** a token from `generate_qr_dinamis()` for this screen's own `lokasi`, which fixes the F4 collision;
  - **kelas:** `qr_kelas_token_bertanda()`, already signed and short-lived;
  - **papan:** counts and the latest check-ins, with names masked unless the request comes from the allowed school network.
- The client redraws in place, with no meta-refresh flicker.
- *Security:*
  - The URL is a capability (a bearer secret): 256 bits, stored only as a hash, revocable, optionally limited to school IPs through the existing `ip_dalam_daftar()` (`functions.php:481`), with `noindex`.
  - The worst case for a leaked class link is that someone can show that class's QR anywhere. That is the same as a forwarded photo of the screen: they still need the bound phone, the right class (`presensi_core.php:141`), and GPS if it is on.
  - **Net security gain:** no staff sessions and no Regenerate button on classroom PCs. It also makes the signed class QR (H3) practical, so schools can finally turn permanent mode off.
- *Effort:* M (2–4 days).

**C2: "Belum hadir" list, manual marking and real ALPA.**

- *List:* for each class and day, the active students with no presensi row.
- *Actions:* **Hadir (manual)**, **Izin**, **Sakit** and **Alpa**, each with a required note. Rows get `metode = 'MANUAL'`, are written to `catat_aktivitas`, need a new permission `presensi.ubah`, and never overwrite a QR row. A **"Tandai Alpa sisanya"** button per class after a cut-off time gives the rekap a real Alpa figure.
- *Holidays:* this needs a holiday date list. `hari_efektif` only lists weekdays, so without it a national holiday would count as Alpa.
- *What it closes:* I4 from fix-summary, and the dead text on the dashboard.
- *Risk:* staff could record a manual Hadir for an absent student. Mitigate with the audit trail, a separate "Manual" label in exports and the rekap, and an optional rule that a manual Hadir needs a reason. Effort M.

**C3: Absence summary through a WhatsApp link.** On the C2 list, a button builds a `https://wa.me/?text=…` link with the class, the date and the names of students who are missing or Alpa. The wali kelas forwards it to the class or parent group. No gateway, no phone-number database, no cost. *Risk:* minors' names in WhatsApp groups is a school decision under Indonesia's personal data protection law (UU PDP 27/2022). Effort S.

**C4: Outbound webhook.** One URL and one HMAC secret in Pengaturan. Events: check-in, izin decision, device anomaly. A small queue table with retries, using the same "run on page load, at most once a minute" trick as `jalankan_pembersihan_log_otomatis()`, because there is no cron. It lets the school connect any Indonesian WhatsApp gateway or a Google Sheet without changing code. *Risk:* data leaving the system. Sign every payload and send the minimum fields. Effort M.

**C5: Automatic WhatsApp to parents.** This needs a parent phone column (the `siswa` table has none), import support, consent, a gateway contract and a queue. Do it after C4. Effort L.

*Deliberately not recommended now:* selfie verification and SSE live boards (from the first audit). Both add weight. A selfie adds a camera step to every check-in, which is the opposite of A1. The 8-second polling is adequate.

---

### Prioritised roadmap: top 5

1. **Week 1: fix the check-in quick wins (all S).**
   - A1 step 1: auto-confirm when the device matches.
   - A2: gate-token grace period, one `lokasi` per screen.
   - A3: retry GPS in place.
   - F5: show `ALREADY_PRESENT` as success.

   This removes the tap, the random rescans and the most common dead end without touching any security control.
2. **Full fast path (A1 step 2, M).** No confirm page for a matched device, GPS started on page load, no duplicate calls. Each check-in drops from 3–5 page loads and 4–7 requests to 2–3 page loads and 2–4 requests. If the `front` branch's `api/quick.php` is merged, use it as the endpoint.
3. **Kiosk display links (C1, M), then turn "QR Kelas permanen" off.** Classroom and gate screens without staff logins. This is the security review's open item and the precondition for H3 in practice.
4. **"Belum hadir" list, manual marking and the WhatsApp share link (C2 and C3, M plus S).** Turns the dashboard count into action, makes ALPA real and closes I4.
5. **Consolidate device enrolment (B1, M) together with B2–B7 and "Ganti perangkat" (B3).** One credential and one student screen; delete the orphan pages and the dead setting. Needs the school's decision on making "Wajib Kode Pasangan" the default and retiring the personal QR card.

**What I could not verify:**

- Real timings on low-end Android phones: GPS fix time, html5-qrcode start-up, and how many gate scans actually fail under F4. The 5–15% figure is my estimate.
- Which browser the phone's own camera opens, especially inside the `SIAPApp` WebView.
- How often admin features are used (there is no analytics). For example, I can't tell whether anyone still prints student QR cards.
- The `front` branch code itself. Front-branch notes come from the report text only.
- Behaviour on the production server (Nginx/aaPanel).
