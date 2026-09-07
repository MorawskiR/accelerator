<?php

declare(strict_types=1);

namespace Flownatic\Generator;

/**
 * Tresc frameworku przepisana z SalesforcCloud_FTF.xlsx.
 *
 * To jest **jedyne miejsce w kodzie**, gdzie zyja kody TC-001...TC-026 oraz
 * przypadki per typ Flow. Kazde odwolanie z generatora i z RiskScannera musi
 * wskazywac na kod, ktory tu istnieje - inaczej eksport z Fazy 5 wyladuje
 * w zlym wierszu arkusza, a tester dostanie odnosnik donikad.
 *
 * Zrodlo: arkusze "Universal Checklist" i "Test Cases" z SalesforcCloud_FTF.xlsx,
 * odczytane 2026-09-07. Pliku xlsx nie edytujemy - to material zrodlowy.
 *
 * ⚠️ Sprawdzenie tych kodow przy realnym arkuszu wykazalo blad w Fazie 3:
 * RiskScanner odsylal "Get Records bez filtrow" do TC-020, a TC-020 dotyczy
 * profilu uzytkownika standardowego. Wlasciwy kod to TC-010. Stad ta klasa:
 * kody maja byc weryfikowalne, a nie pisane z pamieci.
 */
final class Framework
{
    /** Priorytety dokladnie w brzmieniu z arkusza - Faza 5 przepisuje je wprost. */
    public const PRIORYTET_KLUCZOWY  = 'Kluczowe';
    public const PRIORYTET_WAZNY     = 'Ważne';
    public const PRIORYTET_STANDARD  = 'Standardowe';

    /**
     * Universal Checklist - 26 przypadkow w 6 kategoriach.
     *
     * @var array<string,array{kategoria:string, tytul:string, priorytet:string}>
     */
    public const CHECKLISTA = [
        // 🔵 Warunki uruchomienia
        'TC-001' => ['kategoria' => 'Warunki uruchomienia', 'tytul' => 'Flow uruchamia się przy właściwym zdarzeniu (Create/Update/Delete/Schedule)', 'priorytet' => self::PRIORYTET_KLUCZOWY],
        'TC-002' => ['kategoria' => 'Warunki uruchomienia', 'tytul' => 'Flow NIE uruchamia się gdy warunki wejścia nie są spełnione', 'priorytet' => self::PRIORYTET_KLUCZOWY],
        'TC-003' => ['kategoria' => 'Warunki uruchomienia', 'tytul' => 'Entry Criteria filtruje poprawnie rekordy', 'priorytet' => self::PRIORYTET_KLUCZOWY],
        'TC-004' => ['kategoria' => 'Warunki uruchomienia', 'tytul' => 'Flow nie uruchamia się na rekordach spoza zakresu', 'priorytet' => self::PRIORYTET_WAZNY],

        // 🟠 Logika i decyzje
        'TC-005' => ['kategoria' => 'Logika i decyzje', 'tytul' => 'Każda gałąź Decision Node prowadzi do poprawnego wyniku', 'priorytet' => self::PRIORYTET_KLUCZOWY],
        'TC-006' => ['kategoria' => 'Logika i decyzje', 'tytul' => 'Warunek Default w Decision działa gdy żaden warunek nie pasuje', 'priorytet' => self::PRIORYTET_WAZNY],
        'TC-007' => ['kategoria' => 'Logika i decyzje', 'tytul' => 'Pętle (Loop) iterują po poprawnej kolekcji rekordów', 'priorytet' => self::PRIORYTET_WAZNY],
        'TC-008' => ['kategoria' => 'Logika i decyzje', 'tytul' => 'Assignment ustawia zmienne poprawnie', 'priorytet' => self::PRIORYTET_STANDARD],
        'TC-009' => ['kategoria' => 'Logika i decyzje', 'tytul' => 'Zmienne formuł obliczają poprawne wartości', 'priorytet' => self::PRIORYTET_STANDARD],

        // 🟢 Operacje na danych
        'TC-010' => ['kategoria' => 'Operacje na danych', 'tytul' => 'Get Records pobiera właściwe rekordy z poprawnymi filtrami', 'priorytet' => self::PRIORYTET_KLUCZOWY],
        'TC-011' => ['kategoria' => 'Operacje na danych', 'tytul' => 'Create Records tworzy rekordy z poprawnymi wartościami pól', 'priorytet' => self::PRIORYTET_KLUCZOWY],
        'TC-012' => ['kategoria' => 'Operacje na danych', 'tytul' => 'Update Records aktualizuje właściwe rekordy i pola', 'priorytet' => self::PRIORYTET_KLUCZOWY],
        'TC-013' => ['kategoria' => 'Operacje na danych', 'tytul' => 'Delete Records usuwa właściwe rekordy (jeśli dotyczy)', 'priorytet' => self::PRIORYTET_KLUCZOWY],
        'TC-014' => ['kategoria' => 'Operacje na danych', 'tytul' => 'Bulkifikacja — Flow działa poprawnie na wielu rekordach jednocześnie', 'priorytet' => self::PRIORYTET_WAZNY],

        // 🔴 Obsluga bledow
        'TC-015' => ['kategoria' => 'Obsługa błędów', 'tytul' => 'Fault Path jest zdefiniowany i obsługuje błędy gracefully', 'priorytet' => self::PRIORYTET_KLUCZOWY],
        'TC-016' => ['kategoria' => 'Obsługa błędów', 'tytul' => 'Komunikat błędu jest zrozumiały dla użytkownika', 'priorytet' => self::PRIORYTET_WAZNY],
        'TC-017' => ['kategoria' => 'Obsługa błędów', 'tytul' => 'Flow nie powoduje wyjątku UNABLE_TO_LOCK_ROW', 'priorytet' => self::PRIORYTET_WAZNY],
        'TC-018' => ['kategoria' => 'Obsługa błędów', 'tytul' => 'Flow nie przekracza governor limits (DML, SOQL, CPU)', 'priorytet' => self::PRIORYTET_KLUCZOWY],

        // 🟣 Uprawnienia i bezpieczenstwo
        'TC-019' => ['kategoria' => 'Uprawnienia i bezpieczeństwo', 'tytul' => 'Flow działa poprawnie dla profilu Administratora', 'priorytet' => self::PRIORYTET_STANDARD],
        'TC-020' => ['kategoria' => 'Uprawnienia i bezpieczeństwo', 'tytul' => 'Flow działa poprawnie dla profilu użytkownika standardowego', 'priorytet' => self::PRIORYTET_KLUCZOWY],
        'TC-021' => ['kategoria' => 'Uprawnienia i bezpieczeństwo', 'tytul' => 'Flow nie ujawnia danych do których user nie ma dostępu', 'priorytet' => self::PRIORYTET_KLUCZOWY],
        'TC-022' => ['kategoria' => 'Uprawnienia i bezpieczeństwo', 'tytul' => 'Running User ma wymagane uprawnienia do operacji Flow', 'priorytet' => self::PRIORYTET_WAZNY],

        // ⚙️ Specyficzne dla Screen Flow
        'TC-023' => ['kategoria' => 'Screen Flow', 'tytul' => 'Nawigacja (Wstecz/Dalej/Zakończ) działa poprawnie', 'priorytet' => self::PRIORYTET_KLUCZOWY],
        'TC-024' => ['kategoria' => 'Screen Flow', 'tytul' => 'Walidacje na ekranach blokują przejście przy błędnych danych', 'priorytet' => self::PRIORYTET_KLUCZOWY],
        'TC-025' => ['kategoria' => 'Screen Flow', 'tytul' => 'Componenty ekranowe wyświetlają się poprawnie na różnych przeglądarkach', 'priorytet' => self::PRIORYTET_WAZNY],
        'TC-026' => ['kategoria' => 'Screen Flow', 'tytul' => 'Dane wprowadzone przez użytkownika są poprawnie przenoszone', 'priorytet' => self::PRIORYTET_KLUCZOWY],
    ];

    /**
     * Przypadki per typ Flow z arkusza "Test Cases".
     *
     * @var array<string,array<string,string>>
     */
    public const PER_TYP = [
        'RT' => [
            'RT-001' => 'Flow na Create',
            'RT-002' => 'Flow na Create — negatywny',
            'RT-003' => 'Flow na Update',
            'RT-004' => 'Rekursja',
            'RT-005' => 'Bulk — 200 rekordów',
            'RT-006' => 'Before Save vs After Save',
        ],
        'SF' => [
            'SF-001' => 'Happy Path',
            'SF-002' => 'Walidacja — puste pola wymagane',
            'SF-003' => 'Walidacja — błędny format',
            'SF-004' => 'Nawigacja Wstecz',
            'SF-005' => 'Anuluj Flow',
            'SF-006' => 'Conditional Visibility',
        ],
        'SCH' => [
            'SCH-001' => 'Harmonogram',
            'SCH-002' => 'Filtr rekordów',
            'SCH-003' => 'Batch size',
            'SCH-004' => 'Idempotentność',
        ],
        'AL' => [
            'AL-001' => 'Wywołanie z Apex',
            'AL-002' => 'Zmienne wejściowe',
            'AL-003' => 'Zmienne wyjściowe',
            'AL-004' => 'Error handling',
        ],
    ];

    /** Czy taki kod istnieje w frameworku. */
    public static function znany(string $kod): bool
    {
        if (isset(self::CHECKLISTA[$kod])) {
            return true;
        }

        foreach (self::PER_TYP as $przypadki) {
            if (isset($przypadki[$kod])) {
                return true;
            }
        }

        return false;
    }

    /** Tytul pozycji frameworku - do pokazania obok wygenerowanego przypadku. */
    public static function tytul(string $kod): ?string
    {
        if (isset(self::CHECKLISTA[$kod])) {
            return self::CHECKLISTA[$kod]['tytul'];
        }

        foreach (self::PER_TYP as $przypadki) {
            if (isset($przypadki[$kod])) {
                return $przypadki[$kod];
            }
        }

        return null;
    }

    public static function kategoria(string $kod): ?string
    {
        return self::CHECKLISTA[$kod]['kategoria'] ?? null;
    }

    /**
     * Prefiks typu Flow wg arkusza "Test Cases".
     *
     * ⚠️ ProcessType NIE wystarcza: Record-Triggered i Scheduled maja obie
     * wartosc AutoLaunchedFlow. Rozroznia je dopiero TriggerType - to samo
     * ustalenie, co przy inwentarzu w Fazie 2.
     *
     * @param array<string,mixed> $digest
     */
    public static function typFlow(array $digest): string
    {
        $kiedy = (string) ($digest['wyzwalacz']['kiedy'] ?? '');
        $typ   = (string) ($digest['typ'] ?? '');

        if (in_array($kiedy, ['RecordBeforeSave', 'RecordAfterSave', 'RecordBeforeDelete'], true)) {
            return 'RT';
        }

        if ($kiedy === 'Scheduled') {
            return 'SCH';
        }

        if ($typ === 'Flow') {
            return 'SF';
        }

        return 'AL';
    }
}
