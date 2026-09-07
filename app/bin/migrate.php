<?php

declare(strict_types=1);

/**
 * Uruchamianie migracji z linii polecen.
 *
 *   php app/bin/migrate.php            - wykonuje niezastosowane migracje
 *   php app/bin/migrate.php --status   - pokazuje, co jest zastosowane
 *   php app/bin/migrate.php --dry-run  - pokazuje, co by wykonal
 *
 * Cala logika siedzi w Support\Migrator, bo na serwerze nie ma powloki
 * i te same migracje trzeba czasem uruchomic przez przegladarke.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Flownatic\Support\Config;
use Flownatic\Support\Migrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Migracje uruchamia sie wylacznie z linii polecen.' . PHP_EOL);
}

try {
    Config::load();

    $migrator = new Migrator(dirname(__DIR__) . '/db/migrations');

    echo 'Baza:    ' . Config::must('DB_NAME') . ' na ' . Config::get('DB_HOST', 'localhost') . PHP_EOL;
    echo 'Katalog: ' . dirname(__DIR__) . '/db/migrations' . PHP_EOL . PHP_EOL;

    $zastosowane = $migrator->zastosowane();

    if (in_array('--status', $argv, true)) {
        foreach ($migrator->dostepne() as $nazwa) {
            printf('  [%s] %s%s', in_array($nazwa, $zastosowane, true) ? 'x' : ' ', $nazwa, PHP_EOL);
        }
        exit(0);
    }

    $oczekujace = $migrator->oczekujace();

    if (in_array('--dry-run', $argv, true)) {
        foreach ($oczekujace as $nazwa) {
            echo '  wykonalbym ' . $nazwa . PHP_EOL;
        }
        echo PHP_EOL . ($oczekujace === [] ? 'Nic nie czeka - baza jest aktualna.' : 'To byl podglad - nic nie zostalo wykonane.') . PHP_EOL;
        exit(0);
    }

    foreach ($migrator->uruchom() as $krok) {
        echo '  zastosowano ' . $krok['plik'] . ' (' . $krok['polecen'] . ' polecen)' . PHP_EOL;
    }

    echo PHP_EOL . ($oczekujace === [] ? 'Nic do zrobienia - baza jest aktualna.' : 'Gotowe.') . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, PHP_EOL . 'BLAD: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
