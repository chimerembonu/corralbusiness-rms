<?php

namespace App\Support\Licence;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Licence codes (Complete Edition E1.2): "CBL1.<details>.<signature>", where the details are JSON and the
 * signature is Ed25519 (libsodium) made with Corral Systems' signing key. The store server only holds the
 * matching public key (config/licence.php), so it can check a code without internet but can never make one.
 */
class LicenceCodec
{
    public const PREFIX = 'CBL1';

    /** Makes a licence code. Only run at Corral Systems, with the secret signing key. */
    public static function issue(array $details, string $secretKeyBase64, string $keyId): string
    {
        $secret = base64_decode($secretKeyBase64, true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new LicenceException(__('settings::settings.licence.errors.bad_signing_key'));
        }
        $body = self::b64(json_encode(['kid' => $keyId] + $details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $signature = sodium_crypto_sign_detached(self::PREFIX.'.'.$body, $secret);

        return self::PREFIX.'.'.$body.'.'.self::b64($signature);
    }

    /**
     * Reads and checks a code against Corral's public keys.
     *
     * @param  array<string, string>  $publicKeys  key id → base64 public key
     *
     * @throws LicenceException in plain words when the code is incomplete, not genuine or malformed
     */
    public static function read(string $code, array $publicKeys): Licence
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        $parts = explode('.', $code);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX || $parts[1] === '' || $parts[2] === '') {
            throw new LicenceException(__('settings::settings.licence.errors.incomplete'));
        }
        [, $body, $signature] = $parts;
        $json = self::unb64($body);
        $sig = self::unb64($signature);
        $details = $json === null ? null : json_decode($json, true);
        if (! is_array($details) || $sig === null || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new LicenceException(__('settings::settings.licence.errors.incomplete'));
        }

        $public = base64_decode((string) ($publicKeys[$details['kid'] ?? ''] ?? ''), true);
        if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || ! sodium_crypto_sign_verify_detached($sig, self::PREFIX.'.'.$body, $public)) {
            throw new LicenceException(__('settings::settings.licence.errors.not_genuine'));
        }

        return self::toLicence($details, $code);
    }

    private static function toLicence(array $d, string $code): Licence
    {
        $tiers = config('features.tiers', []);
        try {
            $issued = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($d['issued'] ?? ''));
            $expires = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($d['expires'] ?? ''));
        } catch (Throwable) {
            $issued = $expires = false;
        }
        $branches = $d['branches'] ?? null;
        if (! $issued || ! $expires || $expires->lt($issued) || ! isset($tiers[$d['plan'] ?? ''])
            || ! is_int($branches) || $branches < 1 || ! is_string($d['id'] ?? null) || ! is_string($d['restaurant'] ?? null)) {
            throw new LicenceException(__('settings::settings.licence.errors.malformed'));
        }

        return new Licence(
            id: $d['id'],
            restaurant: $d['restaurant'],
            tenantId: isset($d['tenant']) ? (string) $d['tenant'] : null,
            plan: $d['plan'],
            branches: $branches,
            issued: $issued,
            expires: $expires,
            extras: array_values(array_filter((array) ($d['extras'] ?? []), 'is_string')),
            code: $code,
        );
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $text): ?string
    {
        $raw = base64_decode(strtr($text, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }
}
