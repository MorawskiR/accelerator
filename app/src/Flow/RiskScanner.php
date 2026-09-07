<?php

declare(strict_types=1);

namespace Flownatic\Flow;

/**
 * Wykrywa ryzyka w strukturze Flow. Reguly deterministyczne, ZERO AI.
 *
 * Dziala na wyniku DigestBuilder, nie na surowych metadanych - dzieki temu
 * reguly czyta sie jak zdania, a nie jak grzebanie w JSON-ie.
 *
 * Kazde ryzyko wskazuje konkretny element i konkretna pozycje checklisty
 * z frameworku, zeby tester wiedzial, co dopisac do przypadkow testowych.
 *
 * To jest sedno wartosci narzedzia: te cztery reguly wykrywaja bledy, ktore
 * w Salesforce nie objawiaja sie przy testach na kilku rekordach, tylko na
 * produkcji przy imporcie albo masowej aktualizacji.
 */
final class RiskScanner
{
    public const WAGA_WYSOKA  = 'wysokie';
    public const WAGA_SREDNIA = 'srednie';
    public const WAGA_NISKA   = 'niskie';

    /**
     * @param array<string,mixed> $digest wynik DigestBuilder
     * @return list<array<string,mixed>>
     */
    public function scan(array $digest): array
    {
        return array_merge(
            $this->dmlWPetli($digest),
            $this->dmlBezFaultPath($digest),
            $this->afterSaveBezKryteriow($digest),
            $this->zapytaniaBezFiltrow($digest),
        );
    }

    /**
     * Regula 1: DML wewnatrz petli.
     *
     * Salesforce ma limit 150 operacji DML na transakcje. Petla po 200
     * rekordach z Update w srodku da blad Too many DML statements: 151.
     *
     * Najgorsze jest to, ze przy testach na piaciu rekordach wszystko dziala.
     * Problem wychodzi dopiero na produkcji, przy imporcie albo masowej
     * aktualizacji - czyli wtedy, gdy najbardziej boli.
     *
     * @param array<string,mixed> $d
     * @return list<array<string,mixed>>
     */
    private function dmlWPetli(array $d): array
    {
        $ryzyka = [];

        foreach ((array) ($d['dml'] ?? []) as $el) {
            if (empty($el['w_petli'])) {
                continue;
            }

            $petle = implode(', ', (array) $el['w_petli']);

            $ryzyka[] = [
                'regula'      => 'dml_w_petli',
                'waga'        => self::WAGA_WYSOKA,
                'element'     => $el['nazwa'] ?? '?',
                'etykieta'    => $el['etykieta'] ?? null,
                'tytul'       => 'DML wewnątrz pętli',
                'opis'        => sprintf(
                    'Element %s (%s) wykonuje zapis w każdym obiegu pętli %s. '
                    . 'Przy 200 rekordach to 200 operacji DML, a limit wynosi 150.',
                    (string) ($el['etykieta'] ?? $el['nazwa'] ?? '?'),
                    (string) ($el['operacja'] ?? 'DML'),
                    $petle
                ),
                'skutek'      => 'Too many DML statements: 151',
                'checklist'   => 'TC-018',
                'jak_naprawic' => 'Zbierać rekordy do zmiennej kolekcyjnej wewnątrz pętli, '
                    . 'a zapis wykonać raz, po pętli.',
                'jak_testowac' => 'Uruchomić Flow na zestawie co najmniej 200 rekordów '
                    . '(import albo masowa aktualizacja), nie na pojedynczym rekordzie.',
            ];
        }

        return $ryzyka;
    }

    /**
     * Regula 2: DML bez sciezki bledu.
     *
     * Kazdy zapis moze sie nie udac - regula walidacji, brak uprawnien,
     * zablokowany rekord. Bez faultConnector Flow przerywa sie, a uzytkownik
     * widzi komunikat, z ktorego nic nie wynika.
     *
     * @param array<string,mixed> $d
     * @return list<array<string,mixed>>
     */
    private function dmlBezFaultPath(array $d): array
    {
        $ryzyka = [];

        foreach ((array) ($d['dml'] ?? []) as $el) {
            if (!empty($el['ma_fault'])) {
                continue;
            }

            $ryzyka[] = [
                'regula'    => 'dml_bez_fault_path',
                'waga'      => self::WAGA_SREDNIA,
                'element'   => $el['nazwa'] ?? '?',
                'etykieta'  => $el['etykieta'] ?? null,
                'tytul'     => 'Brak fault path przy zapisie',
                'opis'      => sprintf(
                    'Element %s nie ma ścieżki błędu. Nieudany zapis przerwie Flow '
                    . 'bez czytelnego komunikatu dla użytkownika.',
                    (string) ($el['etykieta'] ?? $el['nazwa'] ?? '?')
                ),
                'skutek'    => 'Flow przerywa się, użytkownik widzi błąd systemowy',
                'checklist' => 'TC-015',
                'jak_naprawic' => 'Dodać fault path prowadzący do ekranu błędu albo do '
                    . 'elementu zapisującego błąd.',
                'jak_testowac' => 'Wymusić niepowodzenie zapisu — reguła walidacji na obiekcie '
                    . 'albo odebranie uprawnień do pola — i sprawdzić, co zobaczy użytkownik.',
            ];
        }

        return $ryzyka;
    }

    /**
     * Regula 3: Record-Triggered After Save bez kryteriow wejscia.
     *
     * Flow uruchamiany po zapisie, ktory sam aktualizuje rekordy, potrafi
     * wywolac sam siebie. Kryteria wejscia sa zabezpieczeniem: bez nich Flow
     * odpala sie przy KAZDEJ zmianie.
     *
     * Zglaszamy to tylko wtedy, gdy Flow faktycznie cos zapisuje - sam brak
     * kryteriow przy Flow tylko czytajacym dane nie jest bledem.
     *
     * @param array<string,mixed> $d
     * @return list<array<string,mixed>>
     */
    private function afterSaveBezKryteriow(array $d): array
    {
        $w = (array) ($d['wyzwalacz'] ?? []);

        if (($w['kiedy'] ?? null) !== 'RecordAfterSave') {
            return [];
        }

        if (!empty($w['ma_kryteria'])) {
            return [];
        }

        // Bez zapisu nie ma rekursji - Flow tylko czytajacy jest bezpieczny.
        if (($d['dml'] ?? []) === []) {
            return [];
        }

        $obiekt = (string) ($w['obiekt'] ?? '?');

        return [[
            'regula'    => 'after_save_bez_kryteriow',
            'waga'      => self::WAGA_WYSOKA,
            'element'   => 'start',
            'etykieta'  => 'Wyzwalacz',
            'tytul'     => 'After Save bez kryteriów wejścia',
            'opis'      => sprintf(
                'Flow uruchamia się po każdym zapisie rekordu %s i sam wykonuje zapisy. '
                . 'Bez kryteriów wejścia może wywołać sam siebie.',
                $obiekt
            ),
            'skutek'    => 'Rekursja, przekroczenie limitu zagnieżdżeń, zbędne zużycie limitów',
            'checklist' => 'RT-004',
            'jak_naprawic' => 'Dodać kryteria wejścia zawężające uruchomienie, np. tylko gdy '
                . 'konkretne pole faktycznie się zmieniło.',
            'jak_testowac' => 'Zaktualizować rekord ' . $obiekt . ' i sprawdzić w logach debug, '
                . 'ile razy Flow się uruchomił.',
        ]];
    }

    /**
     * Regula 4: Get Records bez filtrow.
     *
     * Pobranie bez warunkow sciaga wszystko, co jest w obiekcie. Na
     * playgroundzie z pieccioma rekordami nie widac roznicy; na produkcji
     * z setkami tysiecy konczy sie przekroczeniem limitu wierszy.
     *
     * @param array<string,mixed> $d
     * @return list<array<string,mixed>>
     */
    private function zapytaniaBezFiltrow(array $d): array
    {
        $ryzyka = [];

        foreach ((array) ($d['zapytania'] ?? []) as $el) {
            if (!empty($el['ma_filtry'])) {
                continue;
            }

            // Pobranie jednego rekordu bez filtrow jest podejrzane, ale nie grozi
            // przekroczeniem limitu wierszy - stad nizsza waga.
            $tylkoPierwszy = !empty($el['tylko_pierwszy']);
            $obiekt = (string) ($el['obiekt'] ?? '?');

            $ryzyka[] = [
                'regula'    => 'get_records_bez_filtrow',
                'waga'      => $tylkoPierwszy ? self::WAGA_NISKA : self::WAGA_SREDNIA,
                'element'   => $el['nazwa'] ?? '?',
                'etykieta'  => $el['etykieta'] ?? null,
                'tytul'     => 'Pobranie rekordów bez filtrów',
                'opis'      => sprintf(
                    'Element %s pobiera rekordy %s bez żadnych warunków%s.',
                    (string) ($el['etykieta'] ?? $el['nazwa'] ?? '?'),
                    $obiekt,
                    $tylkoPierwszy ? ' (ograniczone do pierwszego rekordu)' : ''
                ),
                'skutek'    => $tylkoPierwszy
                    ? 'Przypadkowy rekord zamiast zamierzonego'
                    : 'Too many query rows: 50001 przy większym wolumenie danych',
                // TC-010 "Get Records pobiera wlasciwe rekordy z poprawnymi filtrami".
                // Do 2026-09-07 stalo tu TC-020, czyli "profil uzytkownika
                // standardowego" - kod wpisany z pamieci, bez zajrzenia do arkusza.
                // Zrodlo prawdy siedzi teraz w Generator\Framework.
                'checklist' => 'TC-010',
                'jak_naprawic' => 'Dodać warunki zawężające zapytanie albo ustawić limit liczby rekordów.',
                'jak_testowac' => 'Uruchomić Flow w org z dużą liczbą rekordów ' . $obiekt
                    . ', nie na kilku testowych.',
            ];
        }

        return $ryzyka;
    }

    /**
     * Podsumowanie licznikowe - do wyswietlenia obok listy.
     *
     * @param list<array<string,mixed>> $ryzyka
     * @return array<string,int>
     */
    public static function podsumuj(array $ryzyka): array
    {
        $p = [self::WAGA_WYSOKA => 0, self::WAGA_SREDNIA => 0, self::WAGA_NISKA => 0];

        foreach ($ryzyka as $r) {
            $w = (string) ($r['waga'] ?? self::WAGA_NISKA);
            $p[$w] = ($p[$w] ?? 0) + 1;
        }

        return $p;
    }
}
