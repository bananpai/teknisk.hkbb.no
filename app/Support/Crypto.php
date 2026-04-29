<?php
// app/Support/Crypto.php

declare(strict_types=1);

namespace App\Support;

/**
 * AES-256-GCM kryptering for persondata i databasen.
 *
 * Krypterte verdier er alltid prefikset med "enc:" slik at ukrypterte
 * (legacy) verdier kan leses og krypteres gradvis via migrasjon.
 *
 * Format: enc:<base64(iv[12] + tag[16] + ciphertext)>
 */
class Crypto
{
    private static function getKey(): string
    {
        $appKey = (string)(($_ENV['APP_KEY'] ?? null) ?: getenv('APP_KEY') ?: '');

        if ($appKey === '') {
            throw new \RuntimeException('APP_KEY er ikke konfigurert i .env.');
        }

        if (str_starts_with($appKey, 'base64:')) {
            $raw = base64_decode(substr($appKey, 7), true);
            if ($raw === false) {
                throw new \RuntimeException('APP_KEY har ugyldig base64-format.');
            }
            // Normaliser til nøyaktig 32 bytes for AES-256
            return str_pad(substr($raw, 0, 32), 32, "\0");
        }

        // Rå streng: hash til 32 bytes
        return hash('sha256', $appKey, true);
    }

    /**
     * Krypter en streng. Returnerer "enc:<base64>".
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::getKey();
        $iv  = random_bytes(12); // 96-bit nonce for GCM
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Kryptering feilet (openssl).');
        }

        return 'enc:' . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Dekrypter en "enc:"-verdi. Kaster RuntimeException ved feil.
     */
    public static function decrypt(string $encoded): string
    {
        if (!str_starts_with($encoded, 'enc:')) {
            throw new \InvalidArgumentException('Verdien er ikke kryptert (mangler enc:-prefiks).');
        }

        $key = self::getKey();
        $raw = base64_decode(substr($encoded, 4), true);

        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('Ugyldig kryptert verdi (for kort eller ugyldig base64).');
        }

        $iv         = substr($raw, 0, 12);
        $tag        = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Dekryptering feilet – feil nøkkel eller ødelagte data.');
        }

        return $plaintext;
    }

    /**
     * Dekrypter hvis kryptert, returner som den er ellers (legacy-støtte).
     * Returnerer null hvis input er null eller tom streng.
     */
    public static function decryptOrNull(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (!str_starts_with($value, 'enc:')) {
            return $value; // Ukryptert legacy-verdi
        }

        try {
            return self::decrypt($value);
        } catch (\Throwable $e) {
            error_log('Crypto::decryptOrNull feilet: ' . $e->getMessage());
            return null;
        }
    }

    public static function isEncrypted(?string $value): bool
    {
        return $value !== null && str_starts_with($value, 'enc:');
    }
}
