# Final review: branch `fix/security-review` at f4d3cc5 (3 new commits on e5a1e9b)

**Verdict: Ready to merge.** The login race is closed for both staff and portal logins under real PHP-FPM concurrency, with no stuck locks or deadlocks. Re-review Minors 1–4 are fixed, and the Minor 5 decision is sound.
**Counts:** Critical 0 · Important 0 · Minor 3.
**Top items:** (1) the new 300-failure cap for known IPs means one student on the school Wi-Fi can lock the student portal for everyone at school for 15 minutes; (2) the documented device-migration residual (a two-day takeover, or keeping a phone "pending" forever) still depends on the school setting a cut-off date; (3) the two-lock design needs MySQL ≥ 5.7.5 / MariaDB ≥ 10.0.2, which isn't documented.

I read the diff e5a1e9b..f4d3cc5 (10 files) in one pass, then tested it on a separate machine: PHP 8.2-FPM (`pm = static`, 80 workers so requests really run in parallel), MariaDB 10.11 and Nginx 1.22. I ran two vhosts:

- **(A)** an aaPanel-style server block: aaPanel's PHP regex location first, then README part A from an included "rewrite" file, then aaPanel's own static and forbidden-file locations. It runs on a database imported from 8977f17's `database.sql` with legacy device bindings, upgraded to f4d3cc5.
- **(B)** README part B with part A pasted in, no `server_name`, on a fresh `database.sql`.

Parallel tests used `X-Forwarded-For` from a `trusted_proxies` entry to simulate many client IPs. Everything below was run, not inferred, unless marked "(read)". I didn't change the repo.

---

### Strengths

- **The race fix is correct and minimal.** `pembatas_mulai()` takes the IP lock and then the account lock, always in that order. Under both locks it checks the limits and inserts the attempt row *before* `password_verify`, then releases both locks in `finally`. A failure updates the row, a success deletes it, and a non-attempt (a password-rule error after a correct activation code or old password) cancels it. Every caller follows this: staff login, portal login, portal activation and portal password change. The critical section is a few queries, so the password check itself stays outside the lock.
- **The limits now hold under parallel load** (each test sent all requests at once; wall time ≈ one request):

  | Test | Rule | Result |
  |---|---|---|
  | Staff, 1 IP, 40 parallel wrong passwords | 5 per account + IP | **5** checked, 35 locked, 5 rows |
  | Staff, 60-s delay active, 40 parallel from 40 unknown IPs | 0 while the delay is active | **0** checked |
  | Staff, delay slot just opened, 40 parallel wrong passwords from 40 IPs | 1 per slot | **1** checked, 39 locked |
  | Same with the correct password | 1 per slot | **1** login, 39 locked |
  | Staff, 1 IP, 60 parallel across 10 accounts | 20 per IP | **20** checked |
  | Portal, 1 IP, 40 parallel wrong passwords | 5 per NISN + IP | **5** checked |
  | Portal, delay slot open, 40 parallel from 40 IPs | 1 per slot | **1** checked |
  | Portal, known IP, 400 parallel across 80 NISNs | 300 per IP | **300** checked, 100 locked |
  | Portal password change, 6 wrong old passwords | 5 | 5 checked, 6th "Terlalu banyak" |

- **No stuck locks or deadlocks:**
  - After every test, `IS_FREE_LOCK` was 1 for all lock names.
  - With a DB error inside the locked section (a renamed column), the locks were released. The login then fails open as designed, and the error is logged.
  - A process that exits while holding a lock frees it, because MySQL releases it with the connection. No persistent connections are used.
  - With the account lock held elsewhere for 8 s, a login waited 5 s, then got "Coba lagi dalam 5 detik", so it fails closed briefly and doesn't hang.
  - Deadlock isn't possible: every path takes the same two locks in the same order, and nothing nests them.
  - A normal admin login afterwards took 0.05 s.
- **IPv4-mapped addresses are unwrapped everywhere they matter.** `::ffff:1.2.3.4` → `1.2.3.4` in `get_client_ip()`, in the X-Forwarded-For chain and in `ip_dalam_daftar()`. I checked this end to end through Nginx with `listen [::]:8084 ipv6only=off`: `REMOTE_ADDR ::ffff:127.0.0.1` → limiter key `127.0.0.1`. `2001:db8:1:2::5` still groups to `/64`.
- **An unset `base_url` now fails loudly instead of guessing.** Behind Nginx with no `base_url` and a forged `Host: evil.example`:
  - `/`, `/login`, `/dashboard`, `/portal` and `/presensi/` return a 503 page showing "CFG-… — isi base_url di config/database.local.php".
  - `/api/*` and `papan?data=1` return a JSON 503 (`CONFIG_MISSING`).
  - Static assets still load, and the error log has one line per request.
  - With `base_url` set, everything works.
  - `php -S` still guesses (`http://127.0.0.1:8099/login.php`) and the CLI gets `http://localhost`, as documented.
  - Apache (`apache2handler`) and LiteSpeed also get the 503 because the check is "not cli / cli-server" (read).
- **The device fix does exactly what was asked** (all on the upgraded 8977f17 database, columns added at runtime):
  - Copied UUID → home-page call (pasif cookie) → successful check-in: the cookie is replaced with a `checkin` cookie and stays **pending**.
  - A second check-in the same day with that cookie: still pending.
  - Rejected check-in (GPS out of range): no secret issued.
  - Normal phone: pasif on day 1, `checkin` after its check-in, **active** after its check-in on day 2. After that, a copied UUID gets `NOT_FOUND` from scan and no personal data from the home page.
  - The real phone opening the app between the attacker's two days resets the attacker's cookie.
  - New registrations on a fresh install are `daftar` and active immediately, and work the next day with the cookie.
- **Migration step 5b:** `migration_keamanan.sql` (with the other two files) ran twice on a copy of the old database with no errors and added `secret_terbit_at`/`secret_terbit_oleh` once. Legacy rows get NULL, which is treated as "not activatable", as intended. The runtime auto-ALTER adds the same columns.
- **The documentation is honest.** The README states the limits and the concurrency guarantee accurately, and spells out the Minor 5 trade-off.

---

### Issues

#### Critical (Must Fix)

None.

#### Important (Should Fix)

None.

#### Minor (Nice to Have)

1. **The known-IP cap makes a school-wide portal lockout easy** (`includes/functions.php:797` together with `includes/portal.php:28`, `PORTAL_MAKS_GAGAL_IP_DIKENAL = 300`)
   - **What:** Re-review Minor 4 is fixed: guessing from a known shared IP now stops at 300 per 15 minutes (tested). The flip side is that known IPs are no longer exempt from the per-IP lock. The limiter writes a row for any NISN-shaped input, even one that doesn't exist. So one student on the school Wi-Fi can send 5 wrong passwords for each of 60 made-up NISNs, which is 300 requests. That locks the school IP out of the portal for 15 minutes for everyone, including students whose IP is "known". I tested it: after the 300 failures, a known student with the correct password got "Terlalu banyak percobaan", and the attacker can repeat it every 15 minutes.
   - **Why it matters:** The portal (izin requests, notifications) becomes unusable from school Wi-Fi during school hours. Students can still use mobile data, check-in is not affected, and staff aren't affected (their rows only exist for real usernames, 5 each). This is the trade-off of the option I suggested in the re-review, so part of this is on the plan.
   - **How to fix (pick one):**
     - Don't count rows for NISNs without an account toward the per-IP caps. Keep recording them so response timing stays the same, but give them their own column or flag.
     - Count the 300 cap only over failures against accounts that are known on that IP.
     - Or keep it as is and add one line to README "Batas yang tersisa" saying so.

2. **The device-migration residual still depends on the cut-off date** (`includes/device_binding.php:185-190`, `:232-243`; `settings.device_legacy_until` empty by default)
   - **What:** Re-review Minor 1 is fixed: no one-session takeover (tested). What remains, as the README and summary say:
     - Someone with a copied UUID who checks in successfully on two different days, with no activity from the real phone in between, takes the binding (tested, S3).
     - The reverse also works: the same person can keep the real phone **pending forever** by calling the home page with the UUID every day, because each passive call replaces the real phone's `checkin` cookie. While pending, the copied UUID keeps working for check-ins, which is the pre-fix situation. Once a cut-off date is set and passes, a phone still pending this way is locked out and needs an admin reset.
   - **Why it matters:** The protection is only as good as the cut-off date, and the default is "never".
   - **How to fix:**
     - In the deploy notes, recommend a concrete cut-off, for example upgrade date + 30 days. Most phones become active on their second check-in day.
     - Mention that the "Belum termigrasi / menunggu" counts on Manajemen Perangkat show when it's safe.
     - No code change is required.

3. **The lock design has an undocumented database version requirement** (`includes/functions.php:822-846`)
   - **What:** Holding two `GET_LOCK`s at once needs MySQL ≥ 5.7.5 or MariaDB ≥ 10.0.2. On older servers, taking the account lock silently releases the IP lock (no error, and `RELEASE_LOCK` just returns NULL). The per-account rules stay atomic, but the per-IP caps (20 / 100 / 300 across accounts) could race again.
   - **Smaller point:** lock names are server-wide, not per database. Two SIAP installs on one MySQL server share lock names. That's harmless, just extra waiting.
   - **How to fix:** add "MySQL 5.7.5+ / MariaDB 10.0.2+" to the README requirements, and optionally add `DB_NAME` to the lock name.

---

### Per-issue verification

| Item | Status | Evidence |
|---|---|---|
| **Re-review Important 1** (login limits bypassed in parallel) | **Fixed** | All parallel tests in Strengths, staff and portal, single-IP and multi-IP during the delay. Locks are released on error, on process exit and after timeouts |
| **Re-review Minor 1** (one-session device takeover) | **Fixed** | A pasif cookie is never activated, and same-day check-in cookies are never activated (tested). The documented two-day residual is Minor 2 above |
| **Re-review Minor 2** (`::ffff:` mapped IPv4) | **Fixed** | Unit and end-to-end tests through Nginx with `ipv6only=off` |
| **Re-review Minor 3** (base-URL guessing) | **Fixed** | 503 with the hint behind Nginx without `base_url` (any Host header). Normal behaviour with it set. Dev server and CLI fall back as documented |
| **Re-review Minor 4** (known-IP removes the per-IP cap) | **Fixed** | 400 parallel requests from a known IP → 300 checked. Side effect is Minor 1 above |
| **Re-review Minor 5** (admin on a new network during an attack) | **Decision accepted** | Documented, not changed. The reasoning is right: letting a correct password through during the delay means checking every attempt during the delay, which is the same as having no delay. My earlier suggestion was wrong on this. A known IP is the recovery path and is unaffected |
| Upgrade from the 8977f17 database (incl. step 5b) | **No regression** | Migrations run twice cleanly. Runtime ALTER works. `qr_kelas_permanen = 1` after upgrade, GPS unchanged, legacy phones migrate |
| Fresh install | **No regression** | GPS on, QR permanent off, signed `k1.…` class QR, new device active (`daftar`), second browser with the same UUID → `NOT_FOUND` |
| Forced password change | **No regression** | Every route including `papan?data=1` → Profil. Re-using `admin123` refused. After the change the dashboard loads (upgrade and fresh) |
| Student portal | **No regression** | Login → `/portal` 200, cookie path `/portal`. Password-change limiter works |
| Device check-in | **No regression** | See Strengths |
| base_url 503 behind Nginx | **Works as intended** | With and without `base_url` |
| Nginx README blocks | **Unchanged since e5a1e9b** | Both vhosts pass `nginx -t`. Internal folders, dotfiles, `*.sql`/`*.md`, `uploads/izin` and `uploads/*.php` → 404; app routes served |
| `php -l` | **Clean** | All 75 PHP files |
| Panel smoke test | **Clean** | 19 panel routes return 200 and no PHP errors appear in the FPM/Nginx log |

### Declined to judge

- **Partial schema with a DB user that can't ALTER:** I tested a database that has `secret_hash`/`secret_aktif_at` but not the new `secret_terbit_*` columns, with an app user that can't ALTER. `siapkan_kolom_rahasia_perangkat()` then returns false for everything, so device matching drops back to UUID-only: the home page shows the student's name to a copied UUID, though check-in itself was still refused (`MISMATCH`). That state only exists for someone who ran e5a1e9b (never released) and skips step 5b. Not worth a finding.
- **Fail-open when the limiter tables error:** an existing, documented design choice (so admins aren't locked out by a database problem), not part of this round.
- **The 5-second lock wait and the brief fail-closed message under extreme contention:** a tuning choice. 400 simultaneous requests finished in under 1.5 s.
- **Earlier agreed skips** (I4, IP-validation enforcement, original minors #2/#4/#5/#6) and the classroom screen needing a panel login: out of scope.
- **Client-supplied GPS coordinates:** an existing design limit. I mention it only because it makes the device residual scriptable.
- **A real Android WebView, HTTPS, MySQL 8, Apache:** not testable here. The Apache/LiteSpeed 503 path was checked by reading the SAPI check.

### Recommendations

1. Before deploying: create `config/database.local.php` with `base_url` (the site now returns 503 without it), then run the three migration files in order. This is documented in README §11.
2. Pick a device cut-off date (for example upgrade + 30 days) and set it in Pengaturan once Manajemen Perangkat shows few devices still pending (Minor 2).
3. Decide whether a 15-minute school-wide portal lockout by one student is acceptable. If not, keep made-up NISNs out of the per-IP count (Minor 1).
4. Add the MySQL/MariaDB minimum version to the README (Minor 3).

### Assessment

**Ready to merge?** Yes

**Reasoning:** Every Critical and Important finding from the three review rounds is fixed and verified under real concurrency, the upgrade from the current production schema is clean, and nothing regressed. The three remaining minors are a documented policy trade-off, a residual the school closes by setting a date, and a version note. None of them blocks a merge.
