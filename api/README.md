# Datafort API

PHP 8.1+, MySQL 8 / MariaDB 10.4+, Apache with `mod_ssl` and `mod_rewrite`.
No Composer, no framework, no external dependencies — same shape as the
existing `pm-backend-php`.

---

## Install

```bash
# 1. Database
mysql -u root -p datafort < api/migrations/001_schema.sql

# 2. Config
cp api/config.sample.php api/config.php
# edit api/config.php — database credentials, tenant slug

# 3. Verify config.php is NOT web-reachable
curl -I https://erp.moveneticsdigital.com/api/config.php   # must be 403

# 4. Delete the mock data before going live
rm mock-data.js
```

### Grant only what each table needs

`audit_log` is append-only. That is currently a promise the code keeps;
this grant makes it something the database enforces:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON datafort.* TO 'datafort_app'@'localhost';
REVOKE UPDATE, DELETE ON datafort.audit_log FROM 'datafort_app'@'localhost';
REVOKE UPDATE, DELETE ON datafort.device_auth_log FROM 'datafort_app'@'localhost';
REVOKE UPDATE, DELETE ON datafort.lead_reveals FROM 'datafort_app'@'localhost';
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
| `security-event.php` | POST | signed in | Untrusted client signals |

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
step ca revoke --serial 8A91F23B
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

- **XLSX import is not implemented.** `import.html` parses CSV in the
  browser for preview; real Excel parsing needs PhpSpreadsheet
  server-side. `import-*.php` does not exist yet.
- **Dashboard endpoints do not exist.** `index.html` still reads
  `mock-data.js`.
- **2FA is schema-only.** `users.totp_secret` exists; nothing verifies it.
- **No CSRF tokens.** Currently mitigated by `SameSite=Strict` cookies
  plus a JSON content type, which blocks the form-post attack. Add real
  tokens before any endpoint accepts `application/x-www-form-urlencoded`.
- **Tenant isolation is application-enforced, not database-enforced.**
  MySQL has no row-level security. Every query carries `tenant_id`; a
  missing `WHERE` clause is a cross-tenant leak rather than an empty
  result. Review accordingly.
- **PHP was never linted.** No PHP binary was available on the machine
  these files were written on. Run `php -l` over every file before
  deploying.
