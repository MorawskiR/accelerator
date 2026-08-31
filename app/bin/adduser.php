<?php

declare(strict_types=1);

/**
 * Zaklada konto uzytkownika.
 *
 *   php app/bin/adduser.php adres@example.com [haslo]
 *
 * Bez podanego hasla generuje losowe i wypisuje je raz. POC ma jedno konto,
 * wiec nie ma tu rejestracji przez formularz - i dobrze, bo aplikacja stoi
 * pod publicznym adresem.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Flownatic\Support\Config;
use Flownatic\Support\Db;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ten skrypt uruchamia sie wylacznie z linii polecen.' . PHP_EOL);
}

$email = $argv[1] ?? null;

if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, 'Uzycie: php app/bin/adduser.php adres@example.com [haslo]' . PHP_EOL);
    exit(1);
}

try {
    Config::load();

    $haslo     = $argv[2] ?? null;
    $wygenerowane = $haslo === null;

    if ($wygenerowane) {
        // 18 bajtow -> 24 znaki base64; bez znakow mylacych w odczycie
        $haslo = rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'Aa'), '=');
    }

    if (strlen($haslo) < 8) {
        fwrite(STDERR, 'Haslo musi miec co najmniej 8 znakow.' . PHP_EOL);
        exit(1);
    }

    $istnieje = Db::one('SELECT id FROM users WHERE email = ?', [$email]);

    if ($istnieje !== null) {
        Db::query('UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($haslo, PASSWORD_DEFAULT), (int) $istnieje['id']]);
        echo 'Zaktualizowano haslo dla ' . $email . PHP_EOL;
    } else {
        Db::query('INSERT INTO users (email, password_hash) VALUES (?, ?)',
            [$email, password_hash($haslo, PASSWORD_DEFAULT)]);
        echo 'Utworzono konto ' . $email . PHP_EOL;
    }

    if ($wygenerowane) {
        echo PHP_EOL . 'Haslo: ' . $haslo . PHP_EOL;
        echo 'Zapisz je teraz - nie da sie go odczytac ponownie.' . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'BLAD: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
