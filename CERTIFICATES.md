# Issuing a Datafort laptop certificate

How to create a company CA, issue a certificate to one laptop, find its
serial number, and register it in Datafort.

Everything here is per requirements §13. The Apache side lives in
[`apache/datafort-mtls.conf`](apache/datafort-mtls.conf); the verification
logic lives in `api/device.php`.

---

## The short version

```bash
# 1. Issue the certificate (CN must equal the device code you will register)
step certificate create "LAPTOP-001" laptop-001.crt laptop-001.key \n     --profile leaf --ca company-ca.crt --ca-key company-ca.key \n     --not-after 8760h --no-password --insecure

# 2. Read the serial
openssl x509 -in laptop-001.crt -noout -serial
#   serial=8A91F23B      <-- this is what Datafort wants

# 3. Package for Windows
openssl pkcs12 -export -out laptop-001.p12 \
    -inkey laptop-001.key -in laptop-001.crt -certfile company-ca.crt

# 4. Install on the laptop, key NOT exportable
certutil -user -importPFX -p "<pfx-password>" My laptop-001.p12 NoExport
```

Then: **Datafort → Devices → Register laptop** → device code `LAPTOP-001`,
serial `8A91F23B` → **Activate**.

---

## Step 1 — Create the CA (once, ever)

### Do you need a `ca.` hostname? No.

`step ca init --dns ca.folksfirstlabs.com --address :443` sets up step-ca
as an **online service** — a long-running HTTPS server that laptops
connect to in order to request certificates. That `--dns` value is the
hostname clients dial and the name the CA's own server certificate
covers. It only means something if you actually run that service.

**For a handful of laptops, do not run it.** An online CA is a server to
patch, a port to expose, and a machine holding your signing key that is
reachable from the network. Signing seven certificates a year does not
justify any of that.

Use an **offline CA** instead: the key lives on one admin machine or an
encrypted USB stick, you sign certificates by hand, and there is nothing
to attack because there is nothing running.

- **No DNS record needed**
- **No host to provision**
- **No open port**
- The CA key is offline, which is the single most valuable property it
  can have

Only revisit this if you reach the scale where IT is enrolling machines
continuously and wants automated renewal.

### Create the root, offline

```bash
step certificate create "Movenetics Digital Device CA"      company-ca.crt company-ca.key      --profile root-ca      --not-after 87600h            # 10 years
```

It will ask for a password to encrypt `company-ca.key`. Use one.

<details>
<summary>Or with plain OpenSSL, if you would rather not install step</summary>

```bash
openssl req -x509 -newkey rsa:4096     -keyout company-ca.key -out company-ca.crt     -days 3650     -subj "/CN=Movenetics Digital Device CA"
```
</details>

> **The CA private key is the root of the entire device layer.** Anyone
> who obtains it can mint a certificate for any laptop, and every control
> in `api/device.php` becomes decorative. Keep it on an encrypted volume
> or a hardware token — never on the web server, and never in this
> repository. `*.key` and `*.crt` are in `.gitignore` for that reason.

Copy **only the public certificate** to the web server. The `.key` never
leaves the machine you created it on:

```bash
scp company-ca.crt server:/etc/ssl/datafort/company-ca.crt
```

That path is what `SSLCACertificateFile` points at in
`apache/datafort-mtls.conf`.

### What about the CRL?

Also no hostname required. Apache reads the revocation list from a
**local file** (`SSLCARevocationFile`), so you generate the CRL offline
and copy it across the same way. There is no CRL distribution point to
host.

---

## Step 2 — Issue a certificate per laptop

One certificate per machine. **Never reuse one across laptops** — the
certificate *is* the device identity, and sharing it means the audit log
can no longer tell two machines apart.

```bash
step certificate create "LAPTOP-001" laptop-001.crt laptop-001.key \
     --profile leaf \
     --ca company-ca.crt --ca-key company-ca.key \
     --not-after 8760h \
     --no-password --insecure     # the .p12 password protects it instead
```

<details>
<summary>Or with plain OpenSSL</summary>

```bash
openssl req -newkey rsa:2048 -nodes \
    -keyout laptop-001.key -out laptop-001.csr \
    -subj "/CN=LAPTOP-001"

openssl x509 -req -in laptop-001.csr \
    -CA company-ca.crt -CAkey company-ca.key -CAcreateserial \
    -out laptop-001.crt -days 365 \
    -extfile <(echo "extendedKeyUsage=clientAuth")
```

The `clientAuth` extended key usage is not optional. Without it some TLS
stacks refuse the certificate for client authentication even though the
chain verifies perfectly — and the failure looks like a chain problem,
which sends you debugging the wrong thing.
</details>

### The CN must match the device code exactly

`api/device.php` cross-checks `SSL_CLIENT_S_DN_CN` against
`company_devices.device_code`. If the certificate says `LAPTOP-001` and
you register `Laptop-001`, the comparison is case-insensitive and passes —
but `LAPTOP001` will be refused with `cn_mismatch`.

Pick a scheme and keep it: `LAPTOP-001`, `LAPTOP-002`, …

---

## Step 3 — Find the serial number

This is the value Datafort stores and matches on.

```bash
openssl x509 -in laptop-001.crt -noout -serial
```

```
serial=8A91F23B
```

### How Datafort normalises it

`normaliseSerial()` in `api/device.php` is the single definition:

| Input | Stored as |
|---|---|
| `8A91F23B` | `8A91F23B` |
| `8a91f23b` | `8A91F23B` |
| `8A:91:F2:3B` | `8A91F23B` |
| `0x8A91F23B` | `8A91F23B` |
| `008A91F23B` | `8A91F23B` |

Uppercase hex, colons stripped, leading zeros removed. The registration
form applies the same rule as you type, so paste whichever form OpenSSL
gave you.

> A serial mismatch is the classic way this integration fails: every
> device is rejected, the certificate looks perfect, and the cause is a
> colon. If you ever change `normaliseSerial()`, every row already in
> `company_devices` must be rewritten to match.

Other useful reads:

```bash
openssl x509 -in laptop-001.crt -noout -subject     # CN check
openssl x509 -in laptop-001.crt -noout -dates       # notBefore / notAfter
openssl x509 -in laptop-001.crt -noout -fingerprint -sha256
```

---

## Step 4 — Package for Windows

```bash
openssl pkcs12 -export -out laptop-001.p12 \
    -inkey laptop-001.key \
    -in laptop-001.crt \
    -certfile company-ca.crt
```

Set a password when prompted. Send the `.p12` and the password by
**separate channels** — a `.p12` with its password in the same email is a
laptop certificate anyone in that thread can install.

---

## Step 5 — Install on the laptop

```powershell
certutil -user -importPFX -p "<pfx-password>" My laptop-001.p12 NoExport
```

### `NoExport` is the whole point

Without it, the employee can export the certificate to a USB stick and
install it on a personal machine, and the device-binding argument
collapses into *"the file is on the company laptop, please leave it
there."*

Where the hardware has a TPM, generate the key **inside the TPM** so it
cannot be extracted even with administrator rights:

```powershell
# Windows, TPM-backed key (requires an AD CS or SCEP enrolment path)
certreq -new laptop-001.inf laptop-001.req     # KeyProtection = TPM
```

> **Honest limit:** a local administrator with the right tooling can
> still extract a software-protected key. `NoExport` stops the casual
> copy; TPM-backed keys resist a determined one. Neither stops someone
> photographing the screen — that is what the watermark and honeytokens
> are for.

Then delete the `.p12` from the laptop and from wherever you sent it.

---

## Step 6 — Register it in Datafort

**Devices → Register laptop**

| Field | Value |
|---|---|
| Device code | `LAPTOP-001` — must equal the certificate CN |
| Certificate serial | `8A91F23B` |
| Assign to employee | the person who will use it |
| Expires | the `notAfter` date from step 3 |

It lands as **pending** and cannot sign in until you press **Activate**.
Registering and authorising are two separate decisions on purpose.

---

## Step 7 — Verify before you trust it

On the enrolled laptop, open Datafort. Then check on the server:

```sql
SELECT device_code, certificate_serial, verify_result, outcome, reason, at
  FROM device_auth_log
 ORDER BY at DESC LIMIT 5;
```

You want `verify_result = SUCCESS`, `outcome = allowed`, `reason = ok`.

If `SSL_CLIENT_VERIFY` is empty, Apache is not exposing the variables —
add `SSLOptions +StdEnvVars +ExportCertData` to the vhost. That single
missing line is the most common mTLS setup failure, and from inside PHP
it is indistinguishable from "no certificate was sent".

---

## Revoking — two places, always both

**1. In Datafort:** Devices → Revoke.

**2. At the CA.** With an offline CA there is no revocation service to
call, so you add the certificate to a CRL and republish it:

```bash
# Mark it revoked in the OpenSSL index, then regenerate the list
openssl ca -config ca.cnf -revoke laptop-001.crt
openssl ca -config ca.cnf -gencrl -out company-ca.crl

# Copy to the web server — Apache reads it from local disk
scp company-ca.crl server:/etc/ssl/datafort/company-ca.crl
```

Then reload Apache so it picks up the new list.

**Revoking in Datafort blocks the user. It does not block the
certificate.** The certificate stays cryptographically valid and Apache
will still complete the TLS handshake with it, so a stolen laptop can
still reach the login page — it just cannot get past it.

For the outer door to close, the CA revocation must reach Apache:

```apache
SSLCARevocationFile  /etc/ssl/datafort/company-ca.crl
SSLCARevocationCheck chain
```

Publish the CRL on a schedule and **monitor that cron job** — a stale or
missing CRL file makes Apache reject *everything*, which is a total
outage rather than a quiet degradation.

---

## Renewal

Certificates are issued for one year. The Devices page flags anything
expiring within 60 days.

Do not let one lapse. An expired certificate fails at the TLS layer,
which means the employee gets a raw browser error and Datafort never runs
to explain it — there is no page on which to show them a helpful message.

Renewal with an offline CA is simply issuing a new certificate — there
is no `renew` command without a CA server:

```bash
# Same command as the original issue, new files
step certificate create "LAPTOP-001" laptop-001-new.crt laptop-001-new.key \
     --profile leaf \
     --ca company-ca.crt --ca-key company-ca.key \
     --not-after 8760h --no-password --insecure

openssl x509 -in laptop-001-new.crt -noout -serial   # serial CHANGES
```

The serial changes, so **update the device record** or the next sign-in
is refused with `unknown_serial`.

---

## Rollout order

Never jump straight to enforcement. From `apache/datafort-mtls.conf`:

| Stage | Apache | Datafort | Meaning |
|---|---|---|---|
| A | `SSLVerifyClient none` | `off` | Certificates ignored |
| B | `SSLVerifyClient optional` | `log` | Checked, recorded, never blocked |
| C | `SSLVerifyClient require` | `enforce` | No certificate, no connection |

Sit in **stage B** until `device_auth_log` shows zero unexpected denials
for a full working week.

In stage C, a laptop whose enrolment silently failed does not see a
Datafort error page — it sees a browser TLS failure with no explanation
and no way for you to give one.
