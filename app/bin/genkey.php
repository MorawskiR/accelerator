<?php

declare(strict_types=1);

/**
 * Generuje APP_KEY do wpisania w .env.
 *
 * Uruchomienie: php app/bin/genkey.php
 *
 * Klucz sluzy do szyfrowania tokenow Salesforce (AES-256-GCM).
 * Zmiana klucza po zapisaniu tokenow sprawi, ze przestana sie odszyfrowywac -
 * trzeba bedzie polaczyc org od nowa.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Flownatic\Support\Crypto;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ten skrypt uruchamia sie wylacznie z linii polecen.' . PHP_EOL);
}

echo PHP_EOL;
echo 'Wklej ponizsza linie do .env:' . PHP_EOL . PHP_EOL;
echo 'APP_KEY=' . Crypto::generateKey() . PHP_EOL . PHP_EOL;
echo 'Uwaga: zmiana APP_KEY unieważnia juz zapisane tokeny Salesforce.' . PHP_EOL;
