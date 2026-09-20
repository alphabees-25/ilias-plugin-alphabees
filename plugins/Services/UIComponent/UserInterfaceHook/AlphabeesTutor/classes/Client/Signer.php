<?php

declare(strict_types=1);

namespace Alphabees\Tutor\Client;

use RuntimeException;

/**
 * Ed25519 request signing, byte-compatible with the backend's
 * `moodle_signature.build_canonical_string`.
 *
 *     canonical = METHOD \n PATH \n TIMESTAMP \n NONCE \n sha256(body) \n SITE
 *
 * `key_id` is deliberately NOT part of the canonical — the header is
 * authoritative and the verifier matches it against the site's active keys.
 *
 * Base64 is emitted in the STANDARD variant with padding. The backend also
 * accepts url-safe and unpadded input, but only because it had to clean up
 * after a libsodium client once: `base64_decode` on the Python side silently
 * drops characters outside the standard alphabet, so a signature that happens
 * to contain `-` or `_` decodes to 63 bytes instead of 64 and fails with no
 * usable error. Do not switch this to SODIUM_BASE64_VARIANT_URLSAFE.
 */
final class Signer
{
    public const ALGO = 'ed25519';

    private string $privateKey;
    private string $siteIdentifier;
    private int $keyId;

    public function __construct(string $privateKeyBase64, string $siteIdentifier, int $keyId)
    {
        $raw = base64_decode($privateKeyBase64, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Stored private key is unusable; pair the site again.');
        }
        $this->privateKey = $raw;
        $this->siteIdentifier = $siteIdentifier;
        $this->keyId = $keyId;
    }

    /**
     * Generate a fresh keypair.
     *
     * The public half goes out as 32 raw bytes in standard base64 — the
     * backend unwraps that into an X.509 SubjectPublicKeyInfo itself, so no
     * PEM handling is needed on this side.
     *
     * @return array{private:string,public:string}
     */
    public static function generateKeypair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'private' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ];
    }

    /**
     * Headers for one request. The body must be passed exactly as it goes on
     * the wire — the hash covers the bytes, not the array they came from.
     *
     * @return array<string,string>
     */
    public function headers(string $method, string $path, string $body): array
    {
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = implode("\n", [
            strtoupper($method),
            $path,
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
            $this->siteIdentifier,
        ]);

        $signature = sodium_crypto_sign_detached($canonical, $this->privateKey);

        return [
            'X-Alphabees-Signature' => base64_encode($signature),
            'X-Alphabees-Algo' => self::ALGO,
            'X-Alphabees-KeyId' => (string) $this->keyId,
            'X-Alphabees-Timestamp' => (string) $timestamp,
            'X-Alphabees-Nonce' => $nonce,
            'X-Alphabees-Site' => $this->siteIdentifier,
        ];
    }
}
