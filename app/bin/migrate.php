<?php

declare(strict_types=1);

/**
 * Uruchamianie migracji SQL.
 *
 *   php app/bin/migrate.php            - wykonuje niezastosowane migracje
 *   php app/bin/migrate.php --status   - pokazuje, co jest zastosowane
 *   php app/bin/migrate.php --dry-run  - pokazuje, co by wykonal
 *
 * Bez ORM-a i bez frameworka migracyjnego: pliki .sql wykonywane po kolei,
 * nazwy zapisywane w tabeli migrations. Na szesciu tabelach cokolwiek
 * wiekszego kosztowaloby wiecej, niz daje.
 *
 * DDL w MySQL i MariaDB powoduje niejawny commit, wiec transakcja wokol
 * migracji nic by nie dala - stad wykonywanie po jednym poleceniu
 * i zatrzymanie na pierwszym bledzie.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Flownatic\Support\Db;
use Flownatic\Support\Config;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Migracje uruchamia sie wylacznie z linii polecen.' . PHP_EOL);
}

$status = in_array('--status', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);
$dir    = dirname(__DIR__) . '/db/migrations';

/** Tabela rejestru - tworzona przy pierwszym uruchomieniu. */
function zapewnijRejestr(): void
{
    Db::conn()->exec(
        'CREATE TABLE IF NOT EXISTS migrations (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            filename   VARCHAR(191) NOT NULL,
            applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_migrations_filename (filename)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * Dzieli plik na pojedyncze polecenia.
 *
 * Swiadomie prosty podzial po sredniku konczacym linie - nasze migracje
 * nie zawieraja srednikow wewnatrz literalow ani procedur. Gdyby kiedys
 * mialy, ten podzial trzeba bedzie zmienic i lepiej, zeby bylo to widoczne.
 *
 * @return list<string>
 */
function podzielNaPolecenia(string $sql): array
{
    $polecenia = [];
    $biezace   = '';

    foreach (preg_split('/\R/', $sql) ?: [] as $linia) {
        $trim = trim($linia);

        if ($trim === '' || str_starts_with($trim, '--')) {
            continue;
        }

        $biezace .= $linia . PHP_EOL;

        if (str_ends_with($trim, ';')) {
            $polecenia[] = trim($biezace);
            $biezace     = '';
        }
    }

    if (trim($biezace) !== '') {
        $polecenia[] = trim($biezace);
    }

    return $polecenia;
}

try {
    Config::load();
    zapewnijRejestr();

    $pliki = glob($dir . '/*.sql') ?: [];
    sort($pliki, SORT_STRING);

    if ($pliki === []) {
        exit('Brak plikow migracji w ' . $dir . PHP_EOL);
    }

    $zastosowane = array_column(Db::all('SELECT filename FROM migrations'), 'filename');

    echo 'Baza:  ' . Config::must('DB_NAME') . ' na ' . Config::get('DB_HOST', 'localhost') . PHP_EOL;
    echo 'Katalog: ' . $dir . PHP_EOL . PHP_EOL;

    if ($status) {
        foreach ($pliki as $plik) {
            $nazwa = basename($plik);
            printf('  [%s] %s%s', in_array($nazwa, $zastosowane, true) ? 'x' : ' ', $nazwa, PHP_EOL);
        }
        exit(0);
    }

    $wykonane = 0;

    foreach ($pliki as $plik) {
        $nazwa = basename($plik);

        if (in_array($nazwa, $zastosowane, true)) {
            echo '  pomijam  ' . $nazwa . ' (juz zastosowana)' . PHP_EOL;
            continue;
        }

        $polecenia = podzielNaPolecenia((string) file_get_contents($plik));

        if ($dryRun) {
            echo '  wykonalbym ' . $nazwa . ' - ' . count($polecenia) . ' polecen' . PHP_EOL;
            continue;
        }

        echo '  stosuje  ' . $nazwa . ' (' . count($polecenia) . ' polecen)' . PHP_EOL;

        foreach ($polecenia as $i => $polecenie) {
            try {
                Db::conn()->exec($polecenie);
            } catch (Throwable $e) {
                // Numer polecenia i pierwsza linia wystarcza, zeby znalezc miejsce w pliku.
                $pierwsza = strtok($polecenie, PHP_EOL);
                throw new RuntimeException(
                    sprintf(
                        'Migracja %s, polecenie %d (%s...): %s',
                        $nazwa,
                        $i + 1,
                        substr((string) $pierwsza, 0, 60),
                        $e->getMessage()
                    ),
                    0,
                    $e
                );
            }
        }

        Db::query('INSERT INTO migrations (filename) VALUES (?)', [$nazwa]);
        $wykonane++;
    }

    if ($dryRun) {
        echo PHP_EOL . 'To byl podglad - nic nie zostalo wykonane.' . PHP_EOL;
    } else {
        echo PHP_EOL . ($wykonane === 0
            ? 'Nic do zrobienia - baza jest aktualna.'
            : 'Zastosowano migracji: ' . $wykonane) . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, PHP_EOL . 'BLAD: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
