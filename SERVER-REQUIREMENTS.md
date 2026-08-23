# Datafort — server requirements

**For:** the hosting / server administrator for `datafort.folksfirstlabs.com`
**From:** Movenetics Digital

This document is self-contained. You do not need the application source to
action it.

---

## What we are asking for, in one paragraph

Datafort authenticates **company laptops** using TLS client certificates
(mutual TLS). Apache must be told to request a client certificate, to
trust our private certificate authority, and to expose the result to PHP.
That configuration can only live in the **SSL VirtualHost** — it cannot
be set from `.htaccess`, because the certificate is negotiated during the
TLS handshake, before Apache knows which directory or `.htaccess` file
applies.

We need an Apache **Include** on that vhost. Four directives. Nothing else
about the server changes.

---

## 1. The Apache configuration

### What to add

```apache
# ── Datafort: mutual TLS (client certificate) ──

# Trust anchor. ONLY certificates signed by this CA are accepted.
# This is a PRIVATE company CA, not a public one — the file is
# supplied with this document.
SSLCACertificateFile    /etc/ssl/datafort/company-ca.crt

# CA -> laptop. Use 2 if we later add an intermediate.
SSLVerifyDepth          2

# 'optional' asks the browser for a certificate but still allows the
# connection without one. This is what we want to start with — the
# application logs what WOULD have been refused while we enrol laptops.
#
# We will ask you to change this to 'require' later, once enrolment is
# complete. Please do not set 'require' yet.
SSLVerifyClient         optional

# REQUIRED. Without this, Apache verifies the certificate but does not
# pass the result to PHP, and the application sees nothing at all.
# This is the line most commonly missed.
SSLOptions              +StdEnvVars +ExportCertData
```

### Where it goes

**On cPanel/WHM**, the standard per-vhost include path:

```
/etc/apache2/conf.d/userdata/ssl/2_4/folksfi1/datafort.folksfirstlabs.com/datafort-mtls.conf
```

Then rebuild and restart:

```bash
/scripts/ensure_vhost_includes --user=folksfi1
/scripts/restartsrv_httpd
```

Using the userdata include path matters: it survives cPanel rewriting
the vhost, which happens on every AutoSSL renewal. Edits made directly
to the generated vhost are lost.

**On plain Apache**, add it inside the existing
`<VirtualHost *:443>` block for this domain, or as an `Include`.

### Please do NOT change

- The **server** certificate lines (`SSLCertificateFile`,
  `SSLCertificateKeyFile`, `SSLCertificateChainFile`). AutoSSL owns
  those. We are only adding the **client** certificate block.
- `AllowOverride` — the application relies on its own `.htaccess`, so
  this must stay at `All` for the document root.

---

## 2. One file to place on disk

We will supply **`company-ca.crt`** — the public certificate of our
private CA. It contains no secret; the matching private key never
leaves our control and is never sent to the server.

```bash
mkdir -p /etc/ssl/datafort
# copy company-ca.crt into /etc/ssl/datafort/
chmod 644 /etc/ssl/datafort/company-ca.crt
```

---

## 3. How to verify it worked

Drop this file at the web root as `mtls-test.php`:

```php
<?php
header('Content-Type: text/plain');
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'SSL_') === 0) echo "$k = $v\n";
}
echo "\n--- verdict ---\n";
echo isset($_SERVER['SSL_CLIENT_VERIFY'])
    ? "OK: Apache is exposing client certificate variables.\n"
    : "NOT WORKING: SSLOptions +StdEnvVars is missing or the Include is not loaded.\n";
```

Visit `https://datafort.folksfirstlabs.com/mtls-test.php`.

**Expected before any laptop is enrolled:**

```
SSL_CLIENT_VERIFY = NONE
--- verdict ---
OK: Apache is exposing client certificate variables.
```

`SSL_CLIENT_VERIFY = NONE` is **correct** at this stage — it means Apache
asked for a certificate and the browser had none. What matters is that
the variable **exists**. If nothing prints, the configuration is not
active.

**Delete `mtls-test.php` once confirmed.**

---

## 4. PHP requirements

| Requirement | Why | How to check |
|---|---|---|
| PHP **8.1+** | Typed properties, `str_contains` | `php -v` |
| `pdo_mysql` | Database access | `php -m \| grep pdo_mysql` |
| **`gd`** | Renders revealed contact details as watermarked images. Without it the application falls back to plain text, which weakens a security feature. | `php -m \| grep -w gd` |
| **`zip`** | Reads `.xlsx` uploads (an xlsx is a zip archive) | `php -m \| grep -w zip` |
| `openssl` | Standard | `php -m \| grep openssl` |

### Upload limits

Lead lists are large. Please set at least:

```ini
upload_max_filesize = 48M
post_max_size       = 50M
max_execution_time  = 120
memory_limit        = 256M
```

`post_max_size` must exceed `upload_max_filesize`, or uploads are
discarded before PHP sees them and the user gets no useful error.

---

## 5. Mail

The application sends email on behalf of users. It currently sends as
`noreply@moveneticsdigital.com` while running on `folksfirstlabs.com`,
which will fail SPF.

Please confirm which you can support:

- **Option A** — we send as `@folksfirstlabs.com` and you confirm SPF
  and DKIM are correct for that domain (simplest)
- **Option B** — we keep `@moveneticsdigital.com` and you tell us the
  sending host/IP to add to that domain's SPF record

---

## 6. Later, not now — revocation

Once laptops are enrolled, revoking a lost or stolen one requires Apache
to check a revocation list. We will supply `company-ca.crl` and ask you
to add:

```apache
SSLCARevocationFile  /etc/ssl/datafort/company-ca.crl
SSLCARevocationCheck chain
```

**Important operational note:** a missing or expired CRL file makes
Apache reject **every** connection, not just revoked ones. Whatever
process refreshes that file must be monitored. We will agree a refresh
cadence with you before this is enabled.

---

## 7. If you cannot do this

We need a straight answer rather than a workaround, because there isn't
one — `SSLVerifyClient` genuinely cannot be set from `.htaccess`, and no
amount of application code substitutes for it.

Please tell us plainly:

1. **Can you add an Apache Include for this vhost?** Yes / No
2. If no, **can we move this domain to a VPS or dedicated server** on
   your infrastructure where we have that control?

If the answer to both is no, we will move the application to hosting
where client-certificate authentication is possible. That is a normal
outcome for shared hosting and not a criticism of the platform — it is
simply a capability shared hosting does not expose.

---

## Summary checklist

- [ ] `mkdir -p /etc/ssl/datafort`, place `company-ca.crt` there
- [ ] Add the four-directive Include to the SSL vhost
- [ ] `/scripts/ensure_vhost_includes --user=folksfi1`
- [ ] `/scripts/restartsrv_httpd`
- [ ] Confirm `mtls-test.php` prints `SSL_CLIENT_VERIFY = NONE`, then delete it
- [ ] Confirm PHP 8.1+ with `gd`, `zip`, `pdo_mysql`
- [ ] Raise `upload_max_filesize` / `post_max_size`
- [ ] Answer the mail question in section 5
- [ ] Leave `SSLVerifyClient` at `optional` — we will ask for `require` later
