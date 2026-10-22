# Re-review: branch `fix/security-review` at e5a1e9b (3 new commits on d0f500a)

**Verdict: With fixes.** The re-bind redesign and almost all minors work as described, and nothing regressed. But all the login limits, old and new, can be bypassed by sending requests in parallel, so Important 1 is not actually closed.
**Counts:** Critical 0 · Important 1 · Minor 5.
**Top item:** the lockout check, the password check and the failure record aren't atomic. 40 simultaneous wrong-password requests from one IP all got a password check (limit: 5), and 40 simultaneous requests while the 60-second delay was active all got one too.

I reviewed the diff d0f500a..e5a1e9b (18 files) in one pass, then ran it again: PHP 8.2-FPM (`pm.max_children` raised to 60 for the concurrency test), MariaDB 10.11 and Nginx 1.22. I ran two vhosts:

- **(A)** an aaPanel-style server block: the aaPanel PHP regex location comes first, then README part A from an `include`d "rewrite" file, then aaPanel's own forbidden-files and static-cache locations. It runs on a database imported from 8977f17's `database.sql`.
- **(B)** README part B with part A pasted in, on a fresh `database.sql`.

Everything below was run, not inferred. I didn't change the repo.

---

### Strengths

- **The Nginx docs are now correct for aaPanel.** Part A inside an aaPanel-style server block passes `nginx -t`. Because of `^~`, `/config/`, `/includes/`, `/database/` and `/portal_siswa/` return 404 even though aaPanel's PHP regex is matched first. Part B parses as well. On both vhosts, 28 paths behave as intended (internal folders, `*.sql`, `README.md`, dotfiles, `uploads/izin/` and `uploads/*.php` → 404; `/`, `/login`, `/presensi/`, `/portal`, `/siswa/1001`, `/api/scan`, static assets and `.well-known` → served or routed).
- **The re-bind is now two-phase and runs only after success, and that holds up.** I tested each step:
  - A copied UUID in a check-in rejected for GPS leaves the binding untouched.
  - A copied UUID after the student was already present (`ALREADY_PRESENT`) doesn't re-key.
  - The real phone's successful check-in moves it to pending, and its next check-in with the cookie makes it active.
  - Once active, a copied UUID gets `NOT_FOUND` on both check-in and the passive home-page call.
  - The real phone's passive call takes the secret back after an attacker's passive call.
  - A cookie that belongs to another binding blocks passive migration.
  - `device_legacy_until` in the past rejects UUID-only matching, and a date in the future accepts it.
  - New registrations are active immediately.
  - Manajemen Perangkat shows the not-yet-migrated and pending counts.
- **The login redesign does what it says when requests arrive one at a time:**
  - 5 failures from one IP lock it, even with a rotating X-Forwarded-For.
  - The admin's known IP isn't affected by that lock, or by 100 failures spread over 100 IPs.
  - An unknown IP gets a short delay instead of a lock (the 220-failure case → "Coba lagi dalam 1 menit").
  - IPv6 is grouped by /64 (`2001:db8:1:2::5` and `…:2:ffff::1` share one bucket; `…:3::` doesn't).
  - A successful login only clears failures from that IP.
  - The portal behaves the same (known IP recorded as `portal / masuk:0011`).
- **No regressions on upgrade.** On the 8977f17 database:
  - The forced change still redirects every route, including `papan?data=1`.
  - A 7-character password is now refused.
  - The student portal still logs in with a trailing-slash `base_url` (cookie path `/portal`).
  - GPS and QR settings are left unchanged.
  - All three migrations ran twice with no errors and add `secret_aktif_at`, `device_legacy_until` and `login_ip_dikenal`.
  - All 35 panel modules return 200 (or the expected 404/405) with no PHP warnings.
- **Fresh install:** forced change, signed class QR, a new device registered active, and a second browser with the same UUID gets `NOT_FOUND`.
- **The no-ALTER fallback works.** With a SELECT/INSERT/UPDATE/DELETE-only DB user on the old schema, `admin/admin123` is now forced to Profil through the session fallback, and the dashboard says to run `migration_keamanan.sql`. After the change, the next login goes straight to the dashboard.

---

### Issues

#### Critical (Must Fix)

None.

#### Important (Should Fix)

**1. Every login limit can be bypassed by sending requests in parallel** (`login.php:33` → `:44` → `:63`; `includes/functions.php:770-797` `pembatas_sisa()`; the portal uses the same path)
- **What:** The code checks the limit, then runs `password_verify` (slow on purpose), and only then inserts the failure. Requests that arrive together all see the old count. I tested this with FPM allowing 60 workers:
  - 40 parallel wrong-password requests from one fresh IP all got a password check ("password salah", 40 rows recorded). The rule is 5 per account + IP.
  - With 220 recent failures (60-second delay active), 40 parallel requests from 40 unknown IPs all got a password check. The rule is one every 60 s.
  This race was already in the code before this branch. What's new is that Important 1's fix now rests entirely on these counts (the README and summary say "5 per IP" and "about 1,440 guesses a day").
- **Why it matters:** One attacker on one IP can try about as many passwords per 15 minutes as there are PHP workers (typically 30-60 on aaPanel), not 5. A small botnet can try one burst per delay slot. That's tens of thousands of guesses a day against `admin` or any NISN, instead of the claimed ~1,440.
- **Fix:** Make check + verify + record atomic per account. The simplest option on MySQL/MariaDB is `SELECT GET_LOCK(CONCAT('siap_login_', :jenis, ':', :akun), 10)` before `pembatas_sisa()`, and `RELEASE_LOCK` after the attempt has been recorded. This serialises attempts per account, so the delay and the 5-per-IP rule hold. Another option: insert the attempt row *before* `password_verify`, count including it, and delete it on success. Add a per-IP lock as well if you want the per-IP rule to hold for many accounts at once.

#### Minor (Nice to Have)

1. **One-session takeover of a not-yet-migrated device, and the window stays open by default** (`includes/device_binding.php:177-217`, `api/beranda.php:23`). Someone with a copied UUID calls `api/beranda` (gets a pending cookie), then immediately does one successful check-in with that cookie. GPS coordinates come from the client, so a script just sends the school's coordinates. The binding becomes active for the attacker, and the real phone then gets `NOT_FOUND`/`MISMATCH` until an admin resets it. I tested this on a legacy binding with GPS on. Before the passive path existed, the attacker needed two successful check-ins on separate days, with no passive call from the victim in between. The summary documents this residual, and `device_legacy_until` closes it, but that field is empty by default. *Fix:* only activate a pending secret that was issued by a successful check-in on an earlier day (store `secret_issued_at` + source). A passively issued secret could then never be activated in the same session. Also consider defaulting `device_legacy_until` to "upgrade date + 30 days" (passive migration moves most phones on their first home-page visit).
2. **IPv4-mapped IPv6 addresses all fall into one bucket** (`includes/functions.php:683-690`). `kunci_ip_pembatas('::ffff:1.2.3.4')` returns `::/64` (tested). On a server where PHP sees mapped addresses (for example Nginx `listen [::]:80 ipv6only=off`), every IPv4 visitor shares one IP. One person can then trigger the per-IP lock for everyone, and once the admin logs in anywhere, everyone counts as "known", which turns off the per-IP rule and the delay. *Fix:* unwrap `::ffff:a.b.c.d` to IPv4 first (in `get_client_ip()`, which also helps `ip_dalam_daftar()`).
3. **Base-URL guessing still has two gaps** (`config/database.php:46-68`). With a vhost that has no `server_name` (the part B template leaves it as a comment), the guess falls back to `http://localhost` without any visible sign. Tested: `/dashboard` → `Location: http://localhost/login.php`. That's exactly what C1 was meant to prevent. On Apache, `SERVER_NAME` comes from the client's Host header unless `UseCanonicalName On`, so Host injection is back there. (aaPanel with a real `server_name` was fine: `http://siap.test:8081/login.php`.) *Fix:* if `base_url` is unset outside the CLI, show a 503 with "isi base_url" instead of guessing. Or at least don't fall back to localhost, and document `UseCanonicalName On`.
4. **A known shared IP removes the IP-wide cap for accounts known there** (`includes/functions.php:775-776`). Students log into the portal from the school Wi-Fi, so the school NAT IP becomes "known" for most NISNs, and the 100-per-IP rule no longer applies to them from that IP. A student on school Wi-Fi can try 5 passwords per classmate every 15 minutes across the whole school (5,000+ guesses), where it used to be 100 in total. *Fix:* keep a much higher but still finite per-IP cap for known IPs (for example 300 per 15 min across all accounts), or apply the known-IP exemption only to the per-account rules.
5. **An admin on a new network may not get in during a sustained attack.** Every attacker failure resets `lalu` (the time since the last failure), so an attacker who sends one attempt per slot takes every slot. The "waits up to 60 seconds" in the summary is optimistic, but the known-IP exemption covers the usual case. Mention it in the README, and consider letting a successful password through regardless of the delay, with the delay applied only to failures.

---

### Per-issue verification

| Item | Status | Evidence |
|---|---|---|
| **Important 1** admin lockout DoS | **Partial** | No global hard lock; the known IP passed after 100 distributed failures; /64 grouping works; clear-per-IP works (all tested). The limits don't hold under concurrency (Important 1 above) |
| **Important 2** legacy re-bind hijack | **Closed as specified** (residual = Minor 1) | Rejected check-ins don't re-key; two-phase works; passive migration and take-back work; cut-off works; counts shown (all tested). One-session takeover remains until a cut-off date is set |
| M1 forced change without ALTER | **Fixed** | The session fallback forces Profil on the old schema with a limited user (tested) |
| M2 warning text without the column | **Fixed** | The dashboard says to run `migration_keamanan.sql` (tested) |
| M3 Host header in a guessed BASE_URL | **Partial** | `SERVER_NAME` used, warning logged once. Falls back to localhost without `server_name`; Host-derived on Apache (Minor 3) |
| M4 missing local config message | **Fixed** | 503 shows "config/database.local.php belum dibuat; lihat README bagian 11" (tested) |
| M5 Nginx/aaPanel docs | **Fixed** | Part A inside an aaPanel-style block and part B both pass `nginx -t` and route correctly (tested) |
| M7 future izin in month totals | **Fixed** | `siswa_detail.php:24`, `portal_siswa/beranda.php:14` now use `tanggal <= CURDATE()` (read) |
| M8 admin password length | **Fixed** | 8 minimum in Profil (tested) and Pengguna (read) |
| M9 stale migration reference | **Fixed** | The README line is replaced (read) |
| Regressions: upgrade, forced change, portal | **None found** | See Strengths |

### Declined to judge

- **M6 (login_bersihkan per IP):** not in the requested list. It's done and tested anyway (clear only for the IP that just logged in).
- **Earlier agreed skips** (I4, IP-validation enforcement, original minors #2/#4/#5/#6) and the classroom screen's panel-login requirement: out of scope, and the summary documents them.
- **Client-supplied GPS coordinates:** an existing design limit. I mentioned it only because it makes Minor 1 scriptable.
- **`api/beranda` showing the full student name to a legacy UUID:** existing behaviour, not introduced here.
- **A real Android WebView, HTTPS, MySQL 8, and Apache's actual `SERVER_NAME` behaviour:** not testable here. The Apache point comes from the documented default `UseCanonicalName Off`.
- **The exact progressive-delay values (1-60 s, 30-day known-IP window):** policy choices.

### Recommendations

1. Add a per-account `GET_LOCK` around the login attempt (Important 1). It's a few lines in `login.php` and `portal_coba_login()`, and it makes the rest of the new limiter design actually hold.
2. Unwrap IPv4-mapped addresses in `get_client_ip()` (Minor 2). It's one line.
3. Make passively issued device secrets non-activatable in the same session, and suggest (or default) a cut-off date in the deploy notes (Minor 1).
4. Make an unset `base_url` an error rather than a guess (Minor 3).

### Assessment

**Ready to merge?** With fixes

**Reasoning:** The re-bind redesign, the Nginx/aaPanel docs and the minors are solid and upgrade cleanly. However, the new login limiter only holds when requests arrive one at a time, which leaves the brute-force protection for Important 1 open, and the fix (a per-account lock) is small.
