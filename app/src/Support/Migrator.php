<?php

declare(strict_types=1);

namespace Flownatic\Support;

use RuntimeException;
use Throwable;

/**
 * Logika migracji, wspolna dla CLI i uruchomienia przez HTTP.
 *
 * Powod istnienia osobnej klasy: na serwerze nie ma powloki, wiec migracje
 * trzeba czasem uruchomic jednorazowym skryptem przez przegladarke.
 * Zamiast dublowac te sama logike w dwoch miejscach, obie drogi wolaja to samo.
 */
final class Migrator
{
    public function __construct(private readonly string $katalog)
    {
    }

    /** Tworzy rejestr migracji, jesli go nie ma. */
    public function zapewnijRejestr(): void
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

    /** @return list<string> nazwy plikow migracji, posortowane */
    public function dostepne(): array
    {
        $pliki = glob($this->katalog . '/*.sql') ?: [];
        sort($pliki, SORT_STRING);

        return array_map('basename', $pliki);
    }

    /** @return list<string> nazwy juz zastosowanych */
    public function zastosowane(): array
    {
        $this->zapewnijRejestr();

        return array_column(Db::all('SELECT filename FROM migrations ORDER BY filename'), 'filename');
    }

    /** @return list<string> nazwy czekajace na zastosowanie */
    public function oczekujace(): array
    {
        $zrobione = $this->zastosowane();

        return array_values(array_filter(
            $this->dostepne(),
            static fn (string $n): bool => !in_array($n, $zrobione, true)
        ));
    }

    /**
     * Stosuje wszystkie oczekujace migracje.
     *
     * DDL w MySQL i MariaDB powoduje niejawny commit, wiec transakcja wokol
     * migracji nic by nie dala - stad wykonywanie po jednym poleceniu
     * i zatrzymanie na pierwszym bledzie.
     *
     * @return list<array{plik:string,polecen:int}>
     */
    public function uruchom(): array
    {
        $wynik = [];

        foreach ($this->oczekujace() as $nazwa) {
            $sciezka   = $this->katalog . '/' . $nazwa;
            $polecenia = self::podzielNaPolecenia((string) file_get_contents($sciezka));

            foreach ($polecenia as $i => $polecenie) {
                try {
                    Db::conn()->exec($polecenie);
                } catch (Throwable $e) {
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
            $wynik[] = ['plik' => $nazwa, 'polecen' => count($polecenia)];
        }

        return $wynik;
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
    public static function podzielNaPolecenia(string $sql): array
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
}
