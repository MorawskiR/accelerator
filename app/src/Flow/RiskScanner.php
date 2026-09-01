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
                'tytul'       => 'Operacja zapisu wewnatrz petli',
                'opis'        => sprintf(
                    'Element %s (%s) wykonuje zapis w kazdym obiegu petli %s. '
                    . 'Przy 200 rekordach to 200 operacji DML, a limit wynosi 150.',
                    (string) ($el['etykieta'] ?? $el['nazwa'] ?? '?'),
                    (string) ($el['operacja'] ?? 'DML'),
                    $petle
                ),
                'skutek'      => 'Too many DML statements: 151',
                'checklist'   => 'TC-018',
                'jak_naprawic' => 'Zbierac rekordy do zmiennej kolekcyjnej wewnatrz petli, '
                    . 'a zapis wykonac raz, po petli.',
                'jak_testowac' => 'Uruchomic Flow na zestawie co najmniej 200 rekodow '
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
                'tytul'     => 'Zapis bez obslugi bledu',
                'opis'      => sprintf(
                    'Element %s nie ma sciezki bledu. Nieudany zapis przerwie Flow '
                    . 'bez czytelnego komunikatu dla uzytkownika.',
                    (string) ($el['etykieta'] ?? $el['nazwa'] ?? '?')
                ),
                'skutek'    => 'Flow przerywa sie, uzytkownik widzi blad systemowy',
                'checklist' => 'TC-015',
                'jak_naprawic' => 'Dodac fault path prowadzacy do ekranu bledu albo do '
                    . 'elementu zapisujacego blad.',
                'jak_testowac' => 'Wymusic niepowodzenie zapisu - regula walidacji na obiekcie '
                    . 'albo odebranie uprawnien do pola - i sprawdzic, co zobaczy uzytkownik.',
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
            'tytul'     => 'After Save bez kryteriow wejscia',
            'opis'      => sprintf(
                'Flow uruchamia sie po kazdym zapisie rekordu %s i sam wykonuje zapisy. '
                . 'Bez kryteriow wejscia moze wywolac sam siebie.',
                $obiekt
            ),
            'skutek'    => 'Rekursja, przekroczenie limitu zagniezdzen, zbedne zuzycie limitow',
            'checklist' => 'RT-004',
            'jak_naprawic' => 'Dodac kryteria wejscia zawezajace uruchomienie, np. tylko gdy '
                . 'konkretne pole faktycznie sie zmienilo.',
            'jak_testowac' => 'Zaktualizowac rekord ' . $obiekt . ' i sprawdzic w logach debug, '
                . 'ile razy Flow sie uruchomil.',
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
                'tytul'     => 'Pobranie rekordow bez filtrow',
                'opis'      => sprintf(
                    'Element %s pobiera rekordy %s bez zadnych warunkow%s.',
                    (string) ($el['etykieta'] ?? $el['nazwa'] ?? '?'),
                    $obiekt,
                    $tylkoPierwszy ? ' (ograniczone do pierwszego rekordu)' : ''
                ),
                'skutek'    => $tylkoPierwszy
                    ? 'Przypadkowy rekord zamiast zamierzonego'
                    : 'Too many query rows: 50001 przy wiekszym wolumenie danych',
                'checklist' => 'TC-020',
                'jak_naprawic' => 'Dodac warunki zawezajace zapytanie albo ustawic limit liczby rekordow.',
                'jak_testowac' => 'Uruchomic Flow w org z duza liczba rekordow ' . $obiekt
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
