<?php

declare(strict_types=1);

namespace Flownatic\Support;

use RuntimeException;
use SensitiveParameter;

/**
 * Szyfrowanie tokenow Salesforce - AES-256-GCM.
 *
 * GCM, a nie CBC: daje jednoczesnie poufnosc i uwierzytelnienie. Podmieniony
 * szyfrogram nie odszyfruje sie po cichu do smieci, tylko zwroci false, wiec
 * nie trzeba osobno dokladac HMAC-a.
 *
 * Format zapisu: base64(nonce[12] . tag[16] . szyfrogram).
 */
final class Crypto
{
    private const CIPHER     = 'aes-256-gcm';
    private const NONCE_LEN  = 12;
    private const TAG_LEN    = 16;
    private const KEY_LEN    = 32;

    public static function encrypt(#[SensitiveParameter] string $plain): string
    {
        $key   = self::key();
        $nonce = random_bytes(self::NONCE_LEN);
        $tag   = '';

        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_LEN);

        if ($cipher === false) {
            throw new RuntimeException('Szyfrowanie nie powiodlo sie.');
        }

        return base64_encode($nonce . $tag . $cipher);
    }

    public static function decrypt(#[SensitiveParameter] string $payload): string
    {
        $raw = base64_decode($payload, true);

        if ($raw === false || strlen($raw) < self::NONCE_LEN + self::TAG_LEN) {
            throw new RuntimeException('Uszkodzony szyfrogram - za krotki albo nie jest base64.');
        }

        $nonce  = substr($raw, 0, self::NONCE_LEN);
        $tag    = substr($raw, self::NONCE_LEN, self::TAG_LEN);
        $cipher = substr($raw, self::NONCE_LEN + self::TAG_LEN);

        $plain = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $nonce, $tag);

        if ($plain === false) {
            // GCM wykryl zmiane danych albo klucz jest inny niz przy szyfrowaniu.
            throw new RuntimeException('Nie moge odszyfrowac - dane zmienione albo zly APP_KEY.');
        }

        return $plain;
    }

    /** Generuje nowy klucz do wpisania w APP_KEY. */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(self::KEY_LEN));
    }

    private static function key(): string
    {
        $raw = base64_decode(Config::must('APP_KEY'), true);

        if ($raw === false || strlen($raw) !== self::KEY_LEN) {
            throw new RuntimeException(
                'APP_KEY musi byc 32 bajtami zakodowanymi w base64. Wygeneruj: php app/bin/genkey.php'
            );
        }

        return $raw;
    }
}
