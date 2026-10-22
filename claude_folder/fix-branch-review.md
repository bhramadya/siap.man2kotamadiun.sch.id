# Review: branch `fix/security-review` (8977f17..d0f500a)

**Verdict: With fixes.** Every listed issue is addressed in code, and the upgrade path works on a real pre-fix database. Three spots still fall short of the agreed goals.
**Counts:** Critical 0 · Important 2 · Minor 9.
**Top items:** (1) anyone with about 20 IPv4 addresses, or a single IPv6 /64, can still keep `admin` locked out for all users (the 100-per-username cap is a global hard block); (2) any request that carries a copied UUID can claim a legacy device binding, from anywhere and even when the check-in itself is rejected, and that window never closes.

I reviewed this in two passes: first the core (config, `includes/*`, login, profil, the migrations, README), then the class-QR, device and panel pages plus the one-line guards. Then I ran the code. Setup: PHP 8.2, PHP-FPM, MariaDB 10.11 and Nginx 1.22, using the Nginx block from README §6 exactly as written, with only `root`, `listen` and the socket changed. Every test described below was run, not inferred.

---

### Strengths

- **The config move is done properly.** Credentials and `BASE_URL` now live in an untracked `config/database.local.php` or in environment variables, and `rtrim` normalises the URL. I tested the student login with `base_url` written *with* a trailing slash: it reaches `/portal` (200), the cookie path is `/portal`, and there's no loop. When the local file is missing, the site shows the 503 page and the log line contains no credentials.
- **The migrations are real and idempotent.** All three `migration_*.sql` files ran twice in a row on a database imported from 8977f17's `database.sql` without errors. Their DDL matches the runtime auto-create code. `migration_keamanan.sql` flags any account that still has the seed admin123 hash, which is a nice touch.
- **Two layers of protection for internal files.** Each `includes/*`/`config/*` file has its own `SCRIPT_FILENAME` guard, and each `panel/*`/`portal_siswa/*` page has an `APP_ROUTER` guard. I tested these behind a deliberately bare Nginx config with no deny rules: `/includes/*.php`, `/config/database.php` and `/portal_siswa/*.php` return 404, and `/panel/*.php` redirects to the router. A server misconfiguration therefore no longer exposes internal pages. `panel/izin_lampiran.php` now checks `require_login()` and `izin.lihat` itself.
- **The Nginx block parses (`nginx -t` OK) and routes correctly.** `/`, `/login`, `/presensi/`, `/presensi/konfirmasi`, `/portal`, `/portal/izin`, `/dashboard`, `/siswa/1001` and `/api/scan` all reach the right script. `config/`, `includes/`, `database/*.sql`, `portal_siswa/`, `uploads/izin/`, `uploads/*.php`, `README.md`, `.htaccess` and `.user.ini` all return 404, and `/.well-known/assetlinks.json` still serves.
- **The trusted-proxy logic is correct.** It walks X-Forwarded-For from the right, stops at junk, and supports IPv4 and IPv6 CIDR. I checked 10 edge cases. A forged XFF from a normal client is ignored: rotating it didn't stop the lock after 5 failures.
- **The forced password change holds.** On a fresh install and on an upgraded one, every panel route, the `?modal=1` popups and `papan?data=1` redirect to Profil. POSTs are blocked too, because `require_login()` runs before page logic. Saving without a new password, or with `ADMIN123`, is refused. After a valid change the dashboard loads, and Profil can't be locked behind a permission because `profil` maps to `null`.
- **The class-QR HMAC is well designed.** It uses a server key (from config, or 32 random bytes stored once), signs the id, expiry and the row's own token (so Regenerate revokes every signed token), parses with a strict regex, caps the expiry against far-future values, and compares with `hash_equals(expected, given)`. In testing, tampered tokens, raw tokens in signed mode, and the print page in signed mode were all rejected or redirected. A signed check-in inside the GPS radius succeeds and one outside is rejected.
- **The device secret is solid.** It's 256-bit, only its hash is stored, the cookie is HttpOnly and SameSite=Lax (Secure on HTTPS), the column is UNIQUE, and the old two-students-one-phone path is closed (a cookie owned by another student → MISMATCH). A copied UUID without the cookie gets NOT_FOUND / MISMATCH, which I tested.
- **Smoke test:** all 35 panel modules return 200 (or the expected 404/405) on the upgraded database, with no PHP warnings, notices or fatals in the output or the log.

---

### Issues

#### Critical (Must Fix)

None.

#### Important (Should Fix)

**1. Admin lockout is still a DoS, now from a botnet or IPv6 instead of from one IP** (`includes/functions.php:666`, `:717-727`; same pattern in `includes/portal.php:28`)
- **What:** The third rule, 100 failures per username from any IP, is a hard block for *everyone*, including the real admin on the school network. Each IP gets 5 tries per username, so 20 IPv4 addresses are enough. And because IPs are compared as full addresses, an attacker with one IPv6 /64 (which any VPS gets) has unlimited "IPs". That also means the per-IP and per-username+IP limits don't slow IPv6 attackers at all: only the 100/15 min cap holds, about 9,600 guesses a day per account. In testing, I inserted 100 failures for `admin` from 100 different IPs, and the correct password from a new IP was refused with "Terlalu banyak percobaan gagal". The 20-per-IP rule also covers all staff usernames, so a student on school Wi-Fi (one NAT IP) can lock every staff login on that network by typing 20 wrong passwords for staff usernames. That rule isn't new, but the README now says outsiders "can no longer" lock `admin`.
- **Why it matters:** The earlier review's medium item was "anyone can keep admin locked out". It's harder now, but not fixed, and there's still no recovery path besides waiting or editing the database.
- **Fix:** Don't hard-block on the global per-username count. Above that threshold, require a delay or CAPTCHA, or let a login through when the IP has a recent successful login for that user (a "known IP"). Group IPv6 by /64 when counting (`inet_pton` + mask). Optionally, exempt IPs listed in a `trusted_admin_ips` config from the global cap. Fix the README claim either way.

**2. The legacy re-bind can be hijacked, from anywhere, and the window never closes** (`includes/device_binding.php:95-111`, `:296-303`; ordering in `includes/presensi_core.php:156`)
- **What:** For a binding that has no `secret_hash` yet, `cari_binding_perangkat()` trusts the UUID alone, and `check_or_register_device()` issues the server secret to whoever presents it. It does this at step 4/5, **before** the "already checked in", opening-hours, holiday and GPS checks. I tested this. A second browser with Cici's copied UUID and no cookie was identified as Cici and checked in. Cici's real phone then got NOT_FOUND / MISMATCH. I ran the attack again after Cici had already checked in that day: the response was `ALREADY_PRESENT`, but the binding was still re-keyed to the attacker. On upgraded installs "QR Kelas permanen" stays ON, so a valid gate token is always available (from a photo of a classroom poster). The attacker needs only the UUID, which is readable from the phone's `localStorage` or the `presensi_device_uuid` cookie. Legacy bindings stay claimable until the real student next checks in, which for a student who is absent for long could be weeks.
- **Why it matters:** The plan asked for graceful re-binding, and asked specifically whether an attacker could abuse the window. They can: they get a permanent proxy binding, and the real student is locked out until an admin resets them. It isn't worse than before the fix (a copied UUID used to work forever), and the summary mentions it, but it's cheap to narrow.
- **Fix:** (a) Issue the secret only after a check-in fully succeeds (move the re-bind after GPS and the duplicate check), as the plan said ("on next check-in"). (b) Also re-bind on the passive calls the real phone makes on every visit (`api/beranda.php`, `api/cek_perangkat.php`), so most phones migrate the first time they open the home page. (c) Add a cut-off, for example a `device_legacy_until` date setting (default upgrade date + 30 days), after which UUID-only matching stops. (d) Show the number of not-yet-migrated bindings in Manajemen Perangkat. A two-phase re-bind (keep accepting the UUID until the new cookie has come back once) would also protect against a WebView that loses the cookie before persisting it.

#### Minor (Nice to Have)

1. **Without ALTER rights, the forced change silently turns off, and the old warning is gone too** (`includes/auth.php:129-139`, `login.php:50-58`). With a SELECT/INSERT/UPDATE/DELETE-only DB user and `migration_keamanan.sql` not yet run, `admin/admin123` logs straight into the dashboard with no message at all (tested). The branch removed the old "password masih bawaan" flash. *Fix:* when `tandai_wajib_ganti_password()` can't store the flag, fall back to keeping the flag in the session (`$_SESSION['wajib_ganti']`) so the redirect still applies, or at least restore the flash.
2. **The red warning points to a toggle that isn't there** (`includes/functions.php:575-588`, `panel/pengaturan.php:285`). On the same no-ALTER, not-yet-migrated install, the Dashboard and Pengaturan say "Matikan 'QR Kelas permanen' di Pengaturan", but the switch is hidden because the column doesn't exist (tested: 0 matches). *Fix:* when the column is missing, say "run `database/migration_keamanan.sql`" instead.
3. **A guessed BASE_URL takes the Host header** (`config/database.php:42-53`). If `base_url` isn't set, redirects and the URLs in pages and QR content come from the client's `Host` (tested: `Host: evil.example` → `Location: http://evil.example/login.php`, and asset URLs on `/` are rewritten). The characters are filtered, so there's no header injection, but it opens cache poisoning, and admin-printed QR cards end up with whatever host the admin used. The warning is also logged on every request. *Fix:* use `SERVER_NAME` instead of `HTTP_HOST` for the guess, and/or refuse to guess (503 with a clear message) when `PHP_SAPI` isn't the built-in server. Log the warning once per process.
4. **A missing local config looks like a database outage** (`config/database.php:17-35`). After an upgrade that skipped step 1, the log only says "Access denied for user 'root'". *Fix:* when neither `database.local.php` nor `SIAP_DB_*` exists, log (and show in the 503 incident text) "config/database.local.php belum dibuat; lihat README §11".
5. **The README Nginx instructions don't fit aaPanel as written** (`README.md:167`). The text says to paste a full `server {}` block into "Conf / URL rewrite". The URL-rewrite box is included *inside* aaPanel's server block, so a nested `server` fails `nginx -t`. aaPanel's own `enable-php-XX.conf` PHP location also comes before the rewrite include. Protection still holds, thanks to `^~ /uploads/` and the PHP guards. *Fix:* split the instructions: the "URL rewrite" box gets sections 1-4 only, and the full block is for a hand-written vhost.
6. **A successful login wipes the attacker's failure history** (`includes/functions.php:749-758`). `login_bersihkan()` deletes all rows for the username from every IP, so each time the real admin logs in, a slow brute-forcer's count resets. This was already the case before the branch, but it now weakens the new per-username cap. *Fix:* delete only rows for this username + IP.
7. **Minor #3 is fixed only in the two places cited** (`panel/siswa_detail.php:24`, `portal_siswa/beranda.php:14`). These month-to-date totals still count approved future izin/sakit days as already used. *Fix:* add `AND tanggal <= CURDATE()`.
8. **Admin password rules are weak** (`panel/profil.php:54`). The minimum is 6 characters (students need 8), and `Admin1234` passes. *Fix:* use 8 to match the portal.
9. **Stale reference:** README §8 still says to run `database/migration_device_hash_v2.sql`, which doesn't exist (the summary acknowledges this). Drop the line or ship the file.

---

### Per-issue verification

| Item | Status | Evidence |
|---|---|---|
| **C1** BASE_URL loop / localhost in git | **Verified fixed** | No credentials or URL in git. Trailing slash stripped. Student login → `/portal` 200 with cookie path `/portal` (tested) |
| **I1** missing migration files / database.sql | **Verified fixed** | 3 files, each ran twice on the old schema with no errors. Fresh import has all tables |
| **I2** attachment endpoint auth | **Verified fixed** | `require_login()` + `izin.lihat` in the file. Direct hit → router redirect (tested) |
| **I3 / H2** Nginx + internal files | **Verified fixed** | `nginx -t` OK. Deny and routing matrix tested. PHP guards hold even without deny rules (see Minor 5 for the aaPanel wording) |
| **I5 + admin lockout** | **Partial** | REMOTE_ADDR + trusted proxies correct, XFF spoof ignored (tested). Global per-username hard block still a DoS (Important 1) |
| **I6 / H1** forced change, credentials | **Verified fixed** (Minor 1 caveat) | Every route redirected, default/same password refused (tested on fresh and upgraded installs). Fails open without ALTER rights and without the migration |
| **H3 GPS** | **Verified fixed** | Fresh: `gps_aktif=1` + sample-coordinate warning. Upgrade: unchanged (0) + red warning (tested) |
| **H3 class QR** | **Verified fixed** | Fresh: OFF (signed, refresh=120 s, print hidden/redirected). Upgrade: ON + red warning. Tamper/raw/print rejected (tested) |
| **H3 device binding** | **Partial** | Secret cookie + hash + MISMATCH for copied UUID work (tested). Legacy re-bind can be hijacked and runs on rejected check-ins (Important 2) |
| **Minor #1** test PDF + .gitignore | **Verified fixed** | PDF removed. `uploads/izin/*`, `uploads/siswa/*`, local config ignored |
| **Minor #3** future izin in stats | **Verified fixed** (as cited) | `dashboard.php` and `presensi_core.php` filter `<= CURDATE()`. Same pattern remains elsewhere (Minor 7) |
| **Minor #7** CSV injection | **Verified fixed** | `= + - @ \t \r` prefixed with `'` (read) |
| **Minor #8** placeholder query | **Verified fixed** | `portal_siswa/izin.php` uses a bound `:s` |

### Declined to judge

- **I4 (staff entering izin for students) and M1 (enforcing IP validation):** agreed skips.
- **Minors #2, #4, #5, #6 and the earlier M2-M5 / L1-L5 items:** not in this branch's scope. I only noted L4 because `.user.ini`'s hard-coded `open_basedir` broke my test install (removed in the test copy only).
- **Class QR tokens can be reused by anyone for ~5 minutes:** inherent in "a QR on a screen". The plan chose time-limited, not one-time use.
- **Device registration happening before the GPS check:** existing ordering. I only flagged it where it widens the re-bind hijack (Important 2).
- **A student exporting their own cookie to a friend's phone:** an accepted limit, documented in the README.
- **Real Android WebView (SIAPApp) cookie persistence and real HTTPS Secure-flag behaviour:** not testable here (no device, no TLS). I read the code, and the cookie flags are as documented.
- **MySQL 8 specifically:** migrations were tested on MariaDB 10.11 only. The syntax used is standard in both.
- **The class screen needs a logged-in panel session on the classroom device:** a design decision inherited from the gate-QR screen, and the plan accepted it. See Recommendations.
- **`api/scan.php` returns `GATE_INVALID` instead of `DB_UNAVAILABLE` when the database is down:** existing behaviour, not touched by this branch.

### Recommendations

1. Fix Important 1 and 2 before merging. Both are small, local changes in `functions.php`/`portal.php` and `device_binding.php`/`presensi_core.php`.
2. **Plan-level:** a class screen showing the signed QR must stay logged into the panel with an account that has `qr_kelas` access. In a classroom, that leaves an open admin session in front of students. Consider a separate, read-only "display link" per class: a long random URL that can be revoked, showing only the rotating QR. That fits the "screen auto-refresh" decision better than a full panel login.
3. Add the Minor 4 message and a pre-flight line to README §11, for example "Before uploading: does `config/database.local.php` exist?". That upgrade step is the one most likely to take the site down during deploy.
4. Keep the ad-hoc smoke script used for testing (login → forced change → check-in → class QR → re-bind) as a PHP/CLI test in the repo. The fixes interact in ways that one-line changes can break.

### Assessment

**Ready to merge?** With fixes

**Reasoning:** All listed issues are addressed, and the branch upgrades an existing install without locking anyone out (tested, including a DB user without ALTER rights). However, the admin-lockout DoS and the legacy re-bind hijack are still open in exactly the places the plan asked about, and each is a small, local fix.
