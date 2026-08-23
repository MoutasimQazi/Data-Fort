# Datafort API

PHP 8.1+, MySQL 8 / MariaDB 10.4+, Apache with `mod_ssl` and `mod_rewrite`.
No Composer, no framework, no external dependencies — same shape as the
existing `pm-backend-php`.

---

## Install

```bash
# 1. Database
mysql -u folksfi1_moutasim -p folksfi1_datafort < api/migrations/001_schema.sql

# 2. Config — already written for this deployment:
#    db folksfi1_datafort, user folksfi1_moutasim, host localhost
#    (api/config.php is gitignored; config.sample.php is the template)

# 3. Verify config.php is NOT web-reachable
curl -I https://datafort.folksfirstlabs.com/api/config.php   # must be 403

# 4. OPTIONAL — sample data for testing (never on production)
mysql -u folksfi1_moutasim -p folksfi1_datafort < api/migrations/002_test_data.sql
```

### Accounts after migration

| Email | Password | Role |
|---|---|---|
| `admin@moveneticsdigital.com` | `admin@123` | admin — seeded by 001 |
| `priya@moveneticsdigital.com` | `test@123` | rep, quota 40 |
| `rahul@moveneticsdigital.com` | `test@123` | rep, quota 40, heavy user |
| `aisha@moveneticsdigital.com` | `test@123` | rep, quota 25, **already exhausted** |
| `vikram@moveneticsdigital.com` | `test@123` | rep, quota 40, light |
| `sneha@moveneticsdigital.com` | `test@123` | rep, **suspended** |

**These passwords are in version control and this repo has a GitHub
remote.** They are fine for a test database and unacceptable anywhere
holding a real lead. `admin@123` is also below the policy the app
enforces on every other password — `auth-reset.php` would refuse to set
it. Change it before this instance holds anything real.

`api/setup.php` remains available for creating an admin the proper way
(it enforces the real password policy). Delete it once you are done.

`mock-data.js` no longer exists — every page reads live data through
`api.js`. If the database is empty the pages show empty states, which is
the honest result rather than a demo that looks live.

### Grant only what each table needs

`audit_log` is append-only. That is currently a promise the code keeps;
this grant makes it something the database enforces:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON folksfi1_datafort.* TO 'folksfi1_moutasim'@'localhost';
REVOKE UPDATE, DELETE ON folksfi1_datafort.audit_log FROM 'folksfi1_moutasim'@'localhost';
REVOKE UPDATE, DELETE ON folksfi1_datafort.device_auth_log FROM 'folksfi1_moutasim'@'localhost';
REVOKE UPDATE, DELETE ON folksfi1_datafort.lead_reveals FROM 'folksfi1_moutasim'@'localhost';
```

Without the REVOKE, a SQL injection anywhere in the app can erase the
evidence trail. With it, the worst case is that the attacker's own
actions are still recorded.

---

## Endpoints

| Endpoint | Method | Who | Notes |
|---|---|---|---|
| `auth-login.php` | POST | anyone | Device check runs **before** the password |
| `auth-logout.php` | POST | anyone | Works even on a dead session |
| `auth-session.php` | GET | signed in | Feeds watermark + quota meter |
| `auth-forgot.php` | POST | anyone | Identical response either way |
| `auth-reset.php` | POST | token | Revokes all other sessions |
| `leads-list.php` | GET | signed in | **Always masked**, for everyone |
| `lead-reveal.php` | POST | signed in | Quota + ledger + watermarked PNG |
| `lead-email.php` | POST | signed in | Relay; address never leaves the server |
| `leads-assign.php` | POST | admin | Assign / recall |
| `leads-update.php` | POST | signed in | Status, note, call logged |
| `users-list.php` | GET | admin | Usage counted from the ledger |
| `users-save.php` | POST | admin | Create, quota, suspend |
| `devices-list.php` | GET | admin | Register + recent denials |
| `devices-save.php` | POST | admin | Register, assign, activate, disable, revoke |
| `audit-list.php` | GET | admin | Read only. No export, ever. |
| `security-event.php` | POST | signed in | Untrusted client signals. `contact_revealed` is NOT accepted from a client |
| `dashboard.php` | GET | admin | Everything index.html needs, one request |
| `settings-get.php` | GET | admin | Tenant policy |
| `settings-save.php` | POST | admin | Audits every field change |
| `import-commit.php` | POST | admin | CSV + XLSX; `preview=1` returns headers only |
| `import-destroy.php` | POST | admin | Records the source file was destroyed |
| `auth-change-password.php` | POST | signed in | Requires current password; revokes other sessions |
| `setup.php` | GET/POST | nobody | First admin only. **Delete after use.** |
| `dbtest.php` | GET | nobody | Connection diagnostic. **Delete after use.** |

**There is no `export-*.php`, and there must never be one.** Section 9 of
the requirements: no export endpoint exists, so there is nothing to
download.

---

## The device layer

Read `device.php` before touching any of this. The short version:

1. Apache verifies the client certificate against the private CA.
2. `sslVar()` reads Apache's verdict from `SSL_CLIENT_*` env vars —
   **never from an HTTP header**, which a client controls.
3. `normaliseSerial()` is the single definition of the stored serial
   format. Change it and every row in `company_devices` must be rewritten.
4. `verifyDevice()` maps the certificate to a device row and applies
   status, tenant, CN and expiry checks.

### Enforcement modes

Set in `tenants.device_enforcement`, changeable from the Devices page
without a deploy.

| Mode | Behaviour |
|---|---|
| `off` | No device check. Only before the CA exists. |
| `log` | Check and record, never block. **Run here during enrolment.** |
| `enforce` | Deny unknown, pending, disabled, revoked, expired. |

Do not jump `off` → `enforce`. Sit in `log` until
`device_auth_log` shows zero denials for a full working week. A laptop
whose enrolment silently failed gets a browser TLS error in `enforce`
mode, with nothing Datafort can say to explain it.

### The two-place revocation problem

Revoking a device in Datafort blocks the **user**. The certificate is
still cryptographically valid and Apache will still complete the
handshake with it. For a lost or stolen laptop you must do both:

```bash
# 1. In Datafort — Devices page → Revoke
# 2. At the CA
openssl ca -config ca.cnf -revoke laptop-001.crt
openssl ca -config ca.cnf -gencrl -out company-ca.crl
# copy company-ca.crl to the path in SSLCARevocationFile
# 3. Publish the CRL and make sure Apache is reading it
#    (SSLCARevocationFile in apache/datafort-mtls.conf)
```

Skipping step 2 means the stolen laptop still reaches the login page. It
cannot get past it — but the outer door is unlocked, and if the app is
ever misconfigured that is the only thing standing there.

---

## Test matrix

Section 20 of the requirements, as things to actually run:

| # | Certificate | Device | Password | Expect |
|---|---|---|---|---|
| 1 | valid | active | correct | **allowed** |
| 2 | none | — | — | denied, `no_certificate` |
| 3 | valid | revoked | correct | denied, `device_revoked` |
| 4 | expired | active | correct | denied, `certificate_expired` |
| 5 | valid | active | wrong | denied, generic login error |
| 6 | valid | pending | correct | denied, `device_pending` |
| 7 | valid | active, other employee | correct | denied, `device_wrong_employee` |
| 8 | valid | active | correct, session cookie moved to another laptop | denied, `device_mismatch` |

Test 8 is the one people forget. It is what makes a stolen session
cookie worthless off the device.

### Also worth testing

- Quota: set a rep to 3/day, reveal 4 contacts, expect `429` on the 4th.
- Quota re-reveal: reveal the same field twice, expect only one ledger row.
- Cross-rep: rep A requests rep B's lead ref, expect `404` — the same
  answer as a lead that does not exist.
- Masking: confirm `leads-list.php` never returns a full phone or email
  for any role, including admin.

---

## Known gaps

- **2FA is schema-only.** `users.totp_secret` exists; nothing verifies it.
- **No CSRF tokens.** Mitigated by `SameSite=Strict` cookies plus a JSON
  content type, which blocks the form-post attack. Add real tokens before
  any endpoint accepts `application/x-www-form-urlencoded`.
- **No session management UI.** A session can only be killed by suspending
  the user or changing their password.
- **Rule-based assignment is not built.** Manual assign/recall only.
- **Old `.xls` is not supported** — only `.xlsx` and `.csv`. The binary
  format needs a real library; Excel converts it in two clicks.
- **Tenant isolation is application-enforced, not database-enforced.**
  MySQL has no row-level security. Every query carries `tenant_id`; a
  missing `WHERE` clause is a cross-tenant leak rather than an empty
  result.
- **PHP was never linted.** No PHP binary on the machine these files were
  written on. Brace, paren and opening-tag structure were checked
  mechanically across all 31 files and are consistent, but that is not a
  syntax check. Run `php -l api/*.php` before deploying.

## Security findings from the last audit

**Stored XSS — fixed.** `Datafort.escape()` and `charts.js esc()` escaped
`& < >` but not quotes, and both are used inside HTML attributes:

```js
aria-label="Select ' + escape(lead.name) + '"
```

A lead imported with the name `" onmouseover="…` closed the attribute and
injected a handler. Lead names come from a customer's spreadsheet, so
they are attacker-controlled the moment anyone imports a file they were
sent — and the payload then ran in the **administrator's** session, which
can read every lead and change every quota. The session cookie is
`httpOnly` so it could not be stolen directly, but the injected script
could call the API as the admin: reveal contacts, disable device
enforcement, raise quotas.

Both helpers now escape `"` and `'` as well.

**Burst limit was bypassable — fixed.** The 2-second rate limit sat
inside the "not already paid" branch, so a rep who had legitimately
revealed forty numbers across a day could script a loop and pull all
forty back as images in seconds. Re-reveals are free by design, which
made the hole free to walk through. The limiter now covers every
attempt, tracked in `security_events` as `reveal_attempt` — that type is
**not accepted from a client**, or a browser could forge its own
rate-limit history.

**`xlsx.php` and `honeytoken.php` are now in the `.htaccess` deny list.**
Both are libraries, not endpoints. `honeytoken.php` matters most: it is
the only file that describes how decoys are generated, and anyone who
reads it can strip them out of a stolen list.

**Note on growth.** `security_events` now gains a row per reveal
attempt. At 40 reveals per rep per day this accumulates quickly and
there is still no retention policy — see the gaps list.

---

## Recently closed

- **Honeytoken seeding now runs** (`api/honeytoken.php`, called from
  `leads-assign.php`). Attribution was previously advertised but absent.
- **Admin reveals write to `lead_reveals`.** They stay uncapped when
  `daily_quota = 0`, but they are counted — the ledger no longer omits
  the one account with unrestricted access.
- **Burst limit**: reveals faster than one per 2 seconds are refused.
- **XLSX import** via `api/xlsx.php` — ZipArchive + SimpleXML, no
  Composer. XXE off, zip-bomb ceiling, cells indexed by their `r=` ref so
  a sparse row cannot shift columns.
- **One-at-a-time reveal** (`reveal.js`) — only the most recent value is
  unmasked; it re-masks after 60s or on blur. Re-revealing is free.
