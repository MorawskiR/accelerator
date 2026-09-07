<?php

declare(strict_types=1);

namespace Flownatic\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Polaczenie z baza - jedno na zadanie.
 *
 * Bez ORM-a i bez magii. Migracje to zwykle pliki .sql (patrz app/bin/migrate.php),
 * a zapytania piszemy recznie. Na POC z szescioma tabelami warstwa abstrakcji
 * kosztowalaby wiecej, niz daje.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = Config::get('DB_HOST', 'localhost');
        $name = Config::must('DB_NAME');

        // charset=utf8mb4 jest tu obowiazkowy, nie kosmetyczny: metadane Flow
        // z Salesforce potrafia zawierac emoji, a utf8 (trzybajtowy) by je zgubil.
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name);

        try {
            // DB_PASS przez get(), nie must(): puste haslo jest legalne dla
            // lokalnego roota w Laragonie. Na produkcji haslo jest ustawione,
            // a jego brak i tak skonczy sie czytelnym bledem polaczenia nizej.
            self::$pdo = new PDO($dsn, Config::must('DB_USER'), Config::get('DB_PASS', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Prawdziwe prepared statements zamiast emulowanych - baza dostaje
                // zapytanie i dane osobno, wiec typy liczbowe wracaja jako liczby.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // Komunikat PDO potrafi zawierac dane polaczenia - nie propagujemy go dalej.
            throw new RuntimeException(
                'Nie moge polaczyc sie z baza "' . $name . '" na ' . $host
                . '. Sprawdz DB_* w .env.',
                0,
                $e
            );
        }

        return self::$pdo;
    }

    /** @param array<string|int,mixed> $params */
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);

        return $st;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** Czysci polaczenie - uzywane w testach. */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
