<?php

declare(strict_types=1);

namespace Flownatic\Support;

use RuntimeException;

/**
 * Odczyt konfiguracji z pliku .env.
 *
 * Swiadomie bez vlucas/phpdotenv - potrzebujemy kilkunastu kluczy, a nie
 * calego mechanizmu. Mniej zaleznosci to mniejszy vendor/, ktory i tak
 * wgrywamy na serwer przez FTP.
 *
 * Plik .env lezy w katalogu nadrzednym wzgledem src/, wiec ta sama sciezka
 * dziala lokalnie (app/.env) i na serwerze (~/flownatic-app/.env).
 */
final class Config
{
    /** @var array<string,string>|null */
    private static ?array $values = null;

    private static ?string $loadedFrom = null;

    /** Wczytuje .env. Wywolywane leniwie, ale mozna tez jawnie wskazac plik. */
    public static function load(?string $path = null): void
    {
        $path ??= dirname(__DIR__, 2) . '/.env';

        if (!is_file($path)) {
            throw new RuntimeException(
                'Nie znalazlem pliku konfiguracyjnego: ' . $path
                . '. Skopiuj .env.example jako .env i wypelnij.'
            );
        }

        self::$values     = self::parse((string) file_get_contents($path));
        self::$loadedFrom = $path;
    }

    /** Sciezka, z ktorej faktycznie wczytano konfiguracje - przydatne w diagnostyce. */
    public static function loadedFrom(): ?string
    {
        return self::$loadedFrom;
    }

    /** Podmiana wartosci w testach; bez argumentu czysci stan. */
    public static function setForTests(?array $values = null): void
    {
        self::$values     = $values;
        self::$loadedFrom = $values === null ? null : '(testy)';
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$values === null) {
            self::load();
        }

        $value = self::$values[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * Wartosc obowiazkowa. Nazwa "must", nie "require" - to drugie jest
     * slowem kluczowym PHP i czytaloby sie mylaco.
     */
    public static function must(string $key): string
    {
        $value = self::get($key);

        if ($value === null) {
            throw new RuntimeException(
                'Brak wymaganego klucza ' . $key . ' w ' . (self::$loadedFrom ?? '.env')
            );
        }

        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return ($value === null || !is_numeric($value)) ? $default : (int) $value;
    }

    /**
     * @return array<string,string>
     */
    private static function parse(string $raw): array
    {
        $out = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            // Wartosc w cudzyslowie zachowujemy w calosci; bez cudzyslowu
            // ucinamy komentarz po znaku #, zeby "APP_ENV=local # uwaga" dzialalo.
            $first = $value[0] ?? '';

            if ($first === '"' || $first === "'") {
                $value = trim($value, $first);
            } elseif (str_contains($value, ' #')) {
                $value = rtrim(substr($value, 0, (int) strpos($value, ' #')));
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
