<?php
/**
 * crypto.php — encrypts platform_tenants.db_pass_enc.
 *
 * The platform database stores a connection password for every tenant
 * database. Storing that in cleartext would mean one leak of this one
 * database (a backup, a SQL injection, a misconfigured export) hands
 * over credentials to every customer's data, not just the registry —
 * so it is AES-256-GCM ciphertext instead, and the key that opens it
 * lives only in the platform vhost's config.php, never in this
 * database and never in version control.
 *
 * GCM is authenticated: platformDecrypt() throws rather than returning
 * garbage if the ciphertext was tampered with or the key is wrong,
 * which is what you want for a credential rather than silent corruption.
 */

declare(strict_types=1);

const PLATFORM_CIPHER = 'aes-256-gcm';
const PLATFORM_NONCE_LEN = 12;
const PLATFORM_TAG_LEN = 16;

/** Decodes the base64 key from config into raw bytes, validating length. */
function platformCryptoKey(string $configKeyBase64): string
{
    $key = base64_decode($configKeyBase64, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException(
            "multi_tenant.tenant_secret_key must be a base64-encoded 32-byte key. " .
            "Generate one with: openssl rand -base64 32"
        );
    }
    return $key;
}

/**
 * Encrypts a tenant DB password for storage in db_pass_enc.
 * Returns raw bytes: nonce (12) + tag (16) + ciphertext — one blob,
 * so the column stays a single VARBINARY rather than three.
 */
function platformEncrypt(string $plaintext, string $configKeyBase64): string
{
    $key   = platformCryptoKey($configKeyBase64);
    $nonce = random_bytes(PLATFORM_NONCE_LEN);
    $tag   = '';

    $ciphertext = openssl_encrypt(
        $plaintext, PLATFORM_CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, '', PLATFORM_TAG_LEN
    );
    if ($ciphertext === false) {
        throw new RuntimeException('Encryption failed');
    }
    return $nonce . $tag . $ciphertext;
}

/** Reverses platformEncrypt(). Throws if the key is wrong or the blob was altered. */
function platformDecrypt(string $blob, string $configKeyBase64): string
{
    $key = platformCryptoKey($configKeyBase64);
    if (strlen($blob) < PLATFORM_NONCE_LEN + PLATFORM_TAG_LEN) {
        throw new RuntimeException('Ciphertext too short to be valid');
    }

    $nonce      = substr($blob, 0, PLATFORM_NONCE_LEN);
    $tag        = substr($blob, PLATFORM_NONCE_LEN, PLATFORM_TAG_LEN);
    $ciphertext = substr($blob, PLATFORM_NONCE_LEN + PLATFORM_TAG_LEN);

    $plaintext = openssl_decrypt(
        $ciphertext, PLATFORM_CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag
    );
    if ($plaintext === false) {
        throw new RuntimeException('Decryption failed — wrong key, or the ciphertext was altered');
    }
    return $plaintext;
}
