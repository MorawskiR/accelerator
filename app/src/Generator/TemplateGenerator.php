<?php

declare(strict_types=1);

namespace Flownatic\Generator;

/**
 * Zamienia Flow Digest w konkretne przypadki testowe. Reguly, ZERO AI.
 *
 * Ta klasa jest odpowiedzia na pytanie, ktore stoi u zrodla calego projektu:
 * tester ma gotowa checkliste TC-001...TC-026, ale zanim jej uzyje, musi
 * przeczytac Flow w Flow Builderze i przepisac kazdy ogolny punkt na kroki
 * dla tego jednego Flow. To wlasnie te 2-4 godziny na Flow.
 *
 * Digest z Fazy 3 ma juz wszystko, czego do tego trzeba: nazwy elementow,
 * obiekt wyzwalacza, kryteria wejscia, galezie decyzji z warunkami, petle,
 * operacje zapisu i pola ekranow. Instancjonujemy wiec checkliste tymi
 * nazwami - mechanicznie i powtarzalnie.
 *
 * Czego ta klasa NIE zrobi: nie napisze kroku ladniejszym jezykiem niz szablon
 * i nie wymysli scenariusza, ktorego nie ma w metadanych. Od tego jest most
 * przez schowek (PromptBuilder + ClipboardImporter) - te same dane, proza
 * modelu, dalej zero kosztow API.
 */
final class TemplateGenerator implements TestCaseSource
{
    public function zrodlo(): string
    {
        return 'reguly';
    }

    /**
     * @param array<string,mixed>       $digest
     * @param list<array<string,mixed>> $ryzyka
     * @return list<array<string,mixed>>
     */
    public function generuj(array $digest, array $ryzyka): array
    {
        $typ = Framework::typFlow($digest);

        // Czy ryzyka pokrywaja juz test masowy. Jesli tak, nie dokladamy
        // drugiego takiego samego - ten z ryzyka jest lepszy, bo wskazuje
        // konkretny element wykonujacy zapis w petli.
        $bulkZRyzyka = $this->maRegule($ryzyka, 'dml_w_petli');

        $przypadki = array_merge(
            $this->wyzwalacz($digest, $typ),
            $this->decyzje($digest),
            $this->petle($digest),
            $this->zapytania($digest),
            $this->zapisy($digest),
            $this->ekrany($digest),
            $this->bulk($digest, $typ, $bulkZRyzyka),
            $this->zRyzyk($ryzyka),
            $this->uprawnienia($digest),
        );

        return $this->ponumeruj($przypadki, $typ);
    }

    // ── Wyzwalacz ────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $d
     * @return list<array<string,mixed>>
     */
    private function wyzwalacz(array $d, string $typ): array
    {
        return match ($typ) {
            'RT'  => $this->wyzwalaczRekordowy($d),
            'SCH' => $this->wyzwalaczHarmonogram($d),
            'SF'  => $this->wyzwalaczEkranowy($d),
            default => $this->wyzwalaczAutomat($d),
        };
    }

    /**
     * @param array<string,mixed> $d
     * @return list<array<string,mixed>>
     */
    private function wyzwalaczRekordowy(array $d): array
    {
        $w        = (array) ($d['wyzwalacz'] ?? []);
        $obiekt   = (string) ($w['obiekt'] ?? 'rekordu');
        $kryteria = (array) ($w['kryteria_wejscia'] ?? []);
        $operacje = (string) ($w['operacje'] ?? '');
        $kiedy    = (string) ($w['kiedy'] ?? '');
        $wynik    = [];

        $opisKryteriow = $kryteria !== []
            ? implode(' oraz ', $kryteria)
            : ((string) ($w['formula_wejscia'] ?? '') !== '' ? (string) $w['formula_wejscia'] : '');

        // Operacje, na ktorych Flow ma sie uruchomic. CreateAndUpdate to dwa
        // osobne przypadki - w praktyce to wlasnie tu wychodza bledy.
        $naTworzenie = str_contains($operacje, 'Create');
        $naZmiane    = str_contains($operacje, 'Update');
        $naUsuniecie = str_contains($operacje, 'Delete') || $kiedy === 'RecordBeforeDelete';

        if ($naTworzenie) {
            $wynik[] = $this->tc('RT-001', 'Uruchomienie przy utworzeniu rekordu ' . $obiekt, [
                'preconditions' => $this->warunkiWstepne($d, $opisKryteriow),
                'steps' => [
                    'Utwórz rekord ' . $obiekt . ($opisKryteriow !== '' ? ' spełniający kryteria: ' . $opisKryteriow : ''),
                    'Zapisz rekord',
                    'Sprawdź w logach debug, czy Flow się uruchomił',
                ],
                'expected' => 'Flow uruchamia się dokładnie raz, wykonuje swoje akcje i kończy bez błędu.',
            ]);
        }

        if ($naZmiane) {
            $wynik[] = $this->tc('RT-003', 'Uruchomienie przy aktualizacji rekordu ' . $obiekt, [
                'preconditions' => $this->warunkiWstepne($d, $opisKryteriow),
                'steps' => [
                    'Otwórz istniejący rekord ' . $obiekt,
                    $opisKryteriow !== ''
                        ? 'Zmień pole tak, aby rekord spełnił kryteria: ' . $opisKryteriow
                        : 'Zmień dowolne pole rekordu',
                    'Zapisz rekord',
                ],
                'expected' => 'Flow uruchamia się po zapisie i wykonuje swoje akcje.',
            ]);
        }

        if ($naUsuniecie) {
            $wynik[] = $this->tc('TC-001', 'Uruchomienie przy usunięciu rekordu ' . $obiekt, [
                'steps' => [
                    'Usuń rekord ' . $obiekt . ' objęty zakresem Flow',
                    'Sprawdź w logach debug, czy Flow się uruchomił',
                ],
                'expected' => 'Flow uruchamia się przed usunięciem rekordu i wykonuje swoje akcje.',
            ]);
        }

        if ($opisKryteriow !== '') {
            $wynik[] = $this->tc('RT-002', 'Rekord niespełniający kryteriów nie uruchamia Flow', [
                'steps' => [
                    'Utwórz rekord ' . $obiekt . ' NIE spełniający kryteriów: ' . $opisKryteriow,
                    'Zapisz rekord',
                    'Sprawdź logi debug',
                ],
                'expected' => 'Flow NIE uruchamia się. Brak jakichkolwiek zmian wynikających z Flow.',
            ]);

            $wynik[] = $this->tc('TC-003', 'Kryteria wejścia filtrują poprawnie', [
                'preconditions' => 'Zestaw rekordów ' . $obiekt . ': część spełnia kryteria, część nie.',
                'steps' => [
                    'Przygotuj co najmniej 5 rekordów spełniających kryteria i 5 niespełniających',
                    'Wykonaj masową aktualizację całego zestawu',
                    'Porównaj, które rekordy zostały zmienione przez Flow',
                ],
                'expected' => 'Flow przetworzył wyłącznie rekordy spełniające kryteria: ' . $opisKryteriow,
            ]);
        } else {
            // Brak kryteriow nie zawsze jest bledem, ale zawsze zasluguje
            // na swiadome potwierdzenie - stad przypadek, a nie tylko ryzyko.
            $wynik[] = $this->tc('TC-002', 'Flow bez kryteriów wejścia uruchamia się przy każdej zmianie', [
                'steps' => [
                    'Zmień w rekordzie ' . $obiekt . ' pole niezwiązane z celem Flow',
                    'Zapisz rekord',
                    'Sprawdź w logach debug, czy Flow się uruchomił',
                ],
                'expected' => 'Flow uruchamia się także przy zmianach niezwiązanych z jego celem. '
                    . 'Potwierdź z autorem, czy jest to zamierzone.',
                'priority' => Framework::PRIORYTET_WAZNY,
            ]);
        }

        if ($kiedy === 'RecordBeforeSave' || $kiedy === 'RecordAfterSave') {
            $przed = $kiedy === 'RecordBeforeSave';

            $wynik[] = $this->tc('RT-006', $przed
                ? 'Before Save — zmiany bez dodatkowej operacji zapisu'
                : 'After Save — zmiany zapisane po utrwaleniu rekordu', [
                'steps' => [
                    'Uruchom Flow przez zapis rekordu ' . $obiekt,
                    'Sprawdź wartości pól na rekordzie zaraz po zapisie',
                    'Sprawdź w logach debug liczbę operacji DML w transakcji',
                ],
                'expected' => $przed
                    ? 'Rekord ma docelowe wartości bez dodatkowej operacji DML — Before Save modyfikuje rekord w locie.'
                    : 'Rekord ma docelowe wartości; zmiany rekordu wyzwalającego wymagały osobnej operacji DML.',
            ]);
        }

        return $wynik;
    }

    /**
     * @param array<string,mixed> $d
     * @return list<array<string,mixed>>
     */
    private function wyzwalaczHarmonogram(array $d): array
    {
        $w = (array) ($d['wyzwalacz'] ?? []);
        $obiekt = (string) ($w['obiekt'] ?? 'rekordów');

        return [
            $this->tc('SCH-001', 'Harmonogram uruchamia Flow zgodnie z konfiguracją', [
                'steps' => [
                    'Sprawdź w Setup częstotliwość, godzinę i strefę czasową harmonogramu',
                    'Poczekaj na najbliższe uruchomienie albo wymuś je ręcznie',
                    'Sprawdź w Paused and Failed Flow Interviews, czy przebieg się zakończył',
                ],
                'expected' => 'Flow uruchamia się o zaplanowanej porze i kończy bez błędu.',
            ]),
            $this->tc('SCH-002', 'Harmonogram przetwarza wyłącznie właściwe rekordy ' . $obiekt, [
                'steps' => [
                    'Przygotuj rekordy ' . $obiekt . ' wewnątrz i na zewnątrz zakresu Flow',
                    'Uruchom Flow',
                    'Porównaj, które rekordy zostały zmienione',
                ],
                'expected' => 'Zmienione zostały wyłącznie rekordy objęte zakresem.',
            ]),
            $this->tc('SCH-004', 'Dwukrotne uruchomienie nie duplikuje efektów', [
                'steps' => [
                    'Uruchom Flow na przygotowanym zestawie rekordów',
                    'Zapisz stan danych',
                    'Uruchom Flow ponownie na tym samym zestawie',
                ],
                'expected' => 'Drugi przebieg nie tworzy duplikatów ani nie nadpisuje danych w niezamierzony sposób.',
            ]),
        ];
    }

    /**
     * @param array<string,mixed> $d
     * @return list<array<string,mixed>>
     */
    private function wyzwalaczEkranowy(array $d): array
    {
        $ekrany = (array) ($d['ekrany'] ?? []);
        $ile    = count($ekrany);

        $wynik = [
            $this->tc('SF-001', 'Happy Path — przejście całego Flow', [
                'steps' => array_merge(
                    ['Uruchom Flow z miejsca, w którym jest udostępniony użytkownikowi'],
                    array_map(
                        static fn (array $e): string => 'Wypełnij poprawnie ekran „'
                            . (string) ($e['etykieta'] ?? $e['nazwa'] ?? '?') . '" i przejdź dalej',
                        $ekrany
                    ),
                    ['Zakończ Flow']
                ),
                'expected' => 'Flow kończy się sukcesem, dane zostały zapisane zgodnie z wprowadzonymi wartościami.',
            ]),
        ];

        if ($ile > 1) {
            $wynik[] = $this->tc('SF-004', 'Nawigacja Wstecz nie gubi wprowadzonych danych', [
                'steps' => [
                    'Wypełnij pierwszy ekran i przejdź dalej',
                    'Na kolejnym ekranie kliknij Wstecz',
                    'Sprawdź wartości pól na poprzednim ekranie',
                ],
                'expected' => 'Powrót działa, a wcześniej wprowadzone dane są zachowane.',
            ]);
        }

        return $wynik;
    }

    /**
     * @param array<string,mixed> $d
     * @return list<array<string,mixed>>
     */
    private function wyzwalaczAutomat(array $d): array
    {
        $wejscia = array_values(array_filter(
            (array) ($d['zmienne'] ?? []),
            static fn (array $z): bool => !empty($z['wejsciowa'])
        ));

        $wyjscia = array_values(array_filter(
            (array) ($d['zmienne'] ?? []),
            static fn (array $z): bool => !empty($z['wyjsciowa'])
        ));

        $wynik = [
            $this->tc('AL-001', 'Wywołanie Flow przez element wywołujący', [
                'steps' => [
                    'Uruchom Flow z miejsca, które go wywołuje (Apex, inny Flow, akcja)',
                    'Sprawdź w logach debug przebieg wykonania',
                ],
                'expected' => 'Flow wykonuje się poprawnie i kończy bez nieobsłużonego wyjątku.',
            ]),
        ];

        if ($wejscia !== []) {
            $wynik[] = $this->tc('AL-002', 'Zmienne wejściowe w różnych kombinacjach', [
                'preconditions' => 'Zmienne wejściowe: ' . $this->nazwyZmiennych($wejscia),
                'steps' => [
                    'Wywołaj Flow z kompletem poprawnych wartości wejściowych',
                    'Powtórz wywołanie z wartościami pustymi lub null',
                    'Powtórz z wartościami skrajnymi (maksymalna długość, wartość ujemna)',
                ],
                'expected' => 'Każda kombinacja obsłużona bez błędu; brak wartości nie powoduje wyjątku.',
            ]);
        }

        if ($wyjscia !== []) {
            $wynik[] = $this->tc('AL-003', 'Zmienne wyjściowe zwracają poprawne wartości', [
                'preconditions' => 'Zmienne wyjściowe: ' . $this->nazwyZmiennych($wyjscia),
                'steps' => [
                    'Wywołaj Flow z danymi o znanym, oczekiwanym wyniku',
                    'Odczytaj wartości zmiennych wyjściowych po zakończeniu',
                ],
                'expected' => 'Zmienne wyjściowe zawierają wartości zgodne z oczekiwaniem.',
            ]);
        }

        return $wynik;
    }

    // ── Logika ───────────────────────────────────────────────────

    /**
     * Kazda galaz decyzji to osobny przypadek - to jest dokladnie ta czesc,
     * ktora przy recznej pracy zajmuje najwiecej czasu i najlatwiej ja pominac.
     *
     * @param array<string,mixed> $d
     * @return list<array<string,mixed>>
     */
    private function decyzje(array $d): array
    {
        $wynik = [];

        foreach ((array) ($d['decyzje'] ?? []) as $decyzja) {
            $nazwaDecyzji = (string) ($decyzja['etykieta'] ?? $decyzja['nazwa'] ?? '?');

            foreach ((array) ($decyzja['galezie'] ?? []) as $galaz) {
                $nazwaGalezi = (string) ($galaz['etykieta'] ?? $galaz['nazwa'] ?? '?');
                $warunki     = (array) ($galaz['warunki'] ?? []);
                $prowadzi    = (string) ($galaz['prowadzi'] ?? '');

                $wynik[] = $this->tc('TC-005', 'Decyzja „' . $nazwaDecyzji . '" — gałąź „' . $nazwaGalezi . '"', [
                    'preconditions' => $warunki !== []
                        ? 'Dane spełniające warunek: ' . implode(' oraz ', $warunki)
                        : null,
                    'steps' => [
                        $warunki !== []
                            ? 'Przygotuj rekord tak, aby spełniał: ' . implode(' oraz ', $warunki)
                            : 'Przygotuj rekord kierujący Flow w gałąź „' . $nazwaGalezi . '"',
                        'Uruchom Flow',
                        'Prześledź ścieżkę wykonania w logach debug',
                    ],
                    'expected' => 'Flow wchodzi w gałąź „' . $nazwaGalezi . '"'
                        . ($prowadzi !== '' ? ' i przechodzi do elementu ' . $prowadzi : '') . '.',
                ]);
            }

            $domyslna = (string) ($decyzja['domyslna'] ?? 'gałąź domyślna');
            $celDom   = (string) ($decyzja['prowadzi_domyslnie'] ?? '');

            $wynik[] = $this->tc('TC-006', 'Decyzja „' . $nazwaDecyzji . '" — ' . $domyslna, [
                'steps' => [
                    'Przygotuj rekord niespełniający żadnego z warunków decyzji',
                    'Uruchom Flow',
                    'Prześledź ścieżkę wykonania',
                ],
                'expected' => 'Flow wchodzi w ścieżkę domyślną'
                    . ($celDom !== '' ? ' i przechodzi do elementu ' . $celDom : '')
                    . ', bez błędu i bez zatrzymania.',
            ]);
        }

        return $wynik;
    }

    /**
     * @param array<string,mixed> $d
     * @return list<array<string,mixed>>
     */
    private function petle(array $d): array
    {
        $wynik = [];

        foreach ((array) ($d['petle'] ?? []) as $petla) {
            $nazwa    = (string) ($petla['etykieta'] ?? $petla['nazwa'] ?? '?');
            $kolekcja = (string) ($petla['kolekcja'] ?? '');
            $wCiele   = (array) ($petla['w_ciele'] ?? []);

            $wynik[] = $this->tc('TC-007', 'Pętla „' . $nazwa . '" iteruje po właściwej kolekcji', [
                'preconditions' => $kolekcja !== '' ? 'Kolekcja wejściowa: ' . $kolekcja : null,
                'steps' => [
                    'Przygotuj kolekcję zawierającą co najmniej 3 rekordy',
                    'Uruchom Flow',
                    'Sprawdź w logach debug liczbę obiegów pętli'
                        . ($wCiele !== [] ? ' i wykonania elementów: ' . implode(', ', $wCiele) : ''),
                ],
                'expected' => 'Liczba obiegów odpowiada liczbie elementów kolekcji; każdy element przetworzony dokładnie raz.',
            ]);

            $wynik[] = $this->tc('TC-007', 'Pętla „' . $nazwa . '" przy pustej kolekcji', [
                'steps' => [
                    'Przygotuj dane tak, aby kolekcja wejściowa była pusta',
                    'Uruchom Flow',
                ],
                'expected' => 'Flow pomija ciało pętli i przechodzi dalej bez błędu.',
                'priority' => Framework::PRIORYTET_WAZNY,
            ]);
        }

        return $wynik;
    }

    // ── Operacje na danych ───────────────────────────────────────

    /**
     * @param array<string,mixed> $d
     * @return list<array<string,mixed>>
     */
    private function zapytania(array $d): array
    {
        $wynik = [];

        foreach ((array) ($d['zapytania'] ?? []) as $z) {
            $nazwa  = (string) ($z['etykieta'] ?? $z['nazwa'] ?? '?');
            $obiekt = (string) ($z['obiekt'] ?? '?');
            $filtry = (array) ($z['filtry'] ?? []);

            $wynik[] = $this->tc('TC-010', 'Pobranie „' . $nazwa . '" zwraca właściwe rekordy ' . $obiekt, [
                'preconditions' => $filtry !== []
                    ? 'Filtry elementu: ' . implode(' oraz ', $filtry)
                    : 'Element nie ma filtrów — pobiera wszystkie rekordy ' . $obiekt . '.',
                'steps' => [
                    'Przygotuj rekordy ' . $obiekt . ' pasujące i niepasujące do warunków',
                    'Uruchom Flow',
                    'Sprawdź w logach debug, które rekordy trafiły do zmiennej wynikowej',
                ],
                'expected' => $filtry !== []
                    ? 'Pobrane zostały wyłącznie rekordy spełniające: ' . implode(' oraz ', $filtry)
                    : 'Potwierdź, czy pobranie wszystkich rekordów ' . $obiekt . ' jest zamierzone.',
            ]);

            $wynik[] = $this->tc('TC-010', 'Pobranie „' . $nazwa . '" gdy nie ma pasujących rekordów', [
                'steps' => [
                    'Przygotuj dane tak, aby żaden rekord ' . $obiekt . ' nie spełniał warunków',
                    'Uruchom Flow',
                ],
                'expected' => 'Flow obsługuje pusty wynik bez błędu — nie próbuje czytać pól z pustej zmiennej.',
                'priority' => Framework::PRIORYTET_WAZNY,
            ]);
        }

        return $wynik;
    }

    /**
     * @param array<string,mixed> $d
     * @return list<array<string,mixed>>
     */
    private function zapisy(array $d): array
    {
        $kody = [
            'utworzenie'   => 'TC-011',
            'aktualizacja' => 'TC-012',
            'usuniecie'    => 'TC-013',
        ];

        $czasowniki = [
            'utworzenie'   => 'tworzy',
            'aktualizacja' => 'aktualizuje',
            'usuniecie'    => 'usuwa',
        ];

        $wynik = [];

        foreach ((array) ($d['dml'] ?? []) as $el) {
            $operacja = (string) ($el['operacja'] ?? 'aktualizacja');
            $nazwa    = (string) ($el['etykieta'] ?? $el['nazwa'] ?? '?');
            $obiekt   = (string) ($el['obiekt'] ?? '?');
            $pola     = (array) ($el['pola'] ?? []);

            $wynik[] = $this->tc($kody[$operacja] ?? 'TC-012',
                'Zapis „' . $nazwa . '" ' . ($czasowniki[$operacja] ?? 'zmienia') . ' rekord ' . $obiekt, [
                    'preconditions' => $pola !== [] ? 'Ustawiane pola: ' . implode(', ', $pola) : null,
                    'steps' => [
                        'Uruchom Flow na danych, które doprowadzą wykonanie do elementu ' . $nazwa,
                        'Odszukaj rekord ' . $obiekt . ' po zakończeniu Flow',
                        'Porównaj wartości pól z oczekiwanymi',
                    ],
                    'expected' => $pola !== []
                        ? 'Rekord ' . $obiekt . ' ma wartości: ' . implode(', ', $pola)
                        : 'Rekord ' . $obiekt . ' został zapisany zgodnie z zamierzeniem Flow.',
                ]);
        }

        return $wynik;
    }

    /**
     * @param array<string,mixed> $d
     * @return list<array<string,mixed>>
     */
    private function ekrany(array $d): array
    {
        $wynik = [];

        foreach ((array) ($d['ekrany'] ?? []) as $ekran) {
            $nazwa = (string) ($ekran['etykieta'] ?? $ekran['nazwa'] ?? '?');
            $pola  = (array) ($ekran['pola'] ?? []);

            $wymagane = array_values(array_filter(
                $pola,
                static fn (array $p): bool => !empty($p['wymagane'])
            ));

            if ($wymagane !== []) {
                $nazwyPol = implode(', ', array_map(
                    static fn (array $p): string => (string) ($p['etykieta'] ?? $p['nazwa'] ?? '?'),
                    $wymagane
                ));

                $wynik[] = $this->tc('SF-002', 'Ekran „' . $nazwa . '" — walidacja pól wymaganych', [
                    'preconditions' => 'Pola wymagane: ' . $nazwyPol,
                    'steps' => [
                        'Otwórz ekran „' . $nazwa . '"',
                        'Pozostaw pola wymagane puste',
                        'Kliknij Dalej',
                    ],
                    'expected' => 'Pojawia się komunikat walidacji, Flow nie przechodzi do kolejnego ekranu.',
                ]);
            }

            foreach ($pola as $pole) {
                $walidacja = (string) ($pole['walidacja'] ?? '');

                if ($walidacja === '') {
                    continue;
                }

                $wynik[] = $this->tc('SF-003',
                    'Ekran „' . $nazwa . '" — reguła walidacji pola ' . (string) ($pole['nazwa'] ?? '?'), [
                        'steps' => [
                            'Otwórz ekran „' . $nazwa . '"',
                            'Wprowadź wartość naruszającą regułę walidacji pola',
                            'Kliknij Dalej',
                        ],
                        'expected' => 'Wyświetla się komunikat: ' . $walidacja,
                    ]);
            }
        }

        return $wynik;
    }

    /**
     * Test masowy. Dla Flow rekordowych framework wymaga go zawsze (RT-005),
     * niezaleznie od tego, czy parser wykryl DML w petli.
     *
     * @param array<string,mixed> $d
     * @return list<array<string,mixed>>
     */
    private function bulk(array $d, string $typ, bool $juzWRyzykach): array
    {
        if ($typ !== 'RT' || $juzWRyzykach) {
            return [];
        }

        $obiekt = (string) ($d['wyzwalacz']['obiekt'] ?? 'rekordów');

        return [
            $this->tc('RT-005', 'Bulk — 200 rekordów ' . $obiekt . ' w jednej transakcji', [
                'preconditions' => 'Plik z 200 rekordami ' . $obiekt . ' objętymi zakresem Flow.',
                'steps' => [
                    'Zaimportuj 200 rekordów przez Data Loader',
                    'Sprawdź logi debug pod kątem limitów DML i SOQL',
                    'Sprawdź, czy wszystkie rekordy zostały przetworzone',
                ],
                'expected' => 'Flow przetwarza wszystkie 200 rekordów bez przekroczenia governor limits.',
            ]),
        ];
    }

    // ── Ryzyka ───────────────────────────────────────────────────

    /**
     * Ryzyka z RiskScanner wchodza wprost - kazde ma juz pole jak_testowac,
     * ktore jest gotowym opisem kroku. Pisalismy je w Fazie 3 wlasnie pod ten
     * moment, zeby nie powtarzac tej samej wiedzy w dwoch miejscach.
     *
     * @param list<array<string,mixed>> $ryzyka
     * @return list<array<string,mixed>>
     */
    private function zRyzyk(array $ryzyka): array
    {
        $wynik = [];

        foreach ($ryzyka as $r) {
            $kod = (string) ($r['checklist'] ?? '');

            // Nieznany kod znaczy blad w regule, a nie powod do pominiecia
            // przypadku - lepiej odeslac do checklisty ogolnej niz donikad.
            if (!Framework::znany($kod)) {
                $kod = 'TC-018';
            }

            $element = (string) ($r['etykieta'] ?? $r['element'] ?? '?');

            $wynik[] = $this->tc($kod, (string) ($r['tytul'] ?? 'Ryzyko') . ' — ' . $element, [
                'preconditions' => (string) ($r['opis'] ?? ''),
                'steps' => [
                    (string) ($r['jak_testowac'] ?? 'Sprawdź zachowanie elementu ' . $element),
                    'Sprawdź logi debug pod kątem: ' . (string) ($r['skutek'] ?? 'błędu'),
                ],
                'expected' => 'Nie występuje: ' . (string) ($r['skutek'] ?? 'błąd')
                    . '. Jeśli występuje — ' . lcfirst((string) ($r['jak_naprawic'] ?? 'popraw Flow')),
                'priority' => ((string) ($r['waga'] ?? '')) === 'wysokie'
                    ? Framework::PRIORYTET_KLUCZOWY
                    : Framework::PRIORYTET_WAZNY,
            ]);
        }

        return $wynik;
    }

    /**
     * @param array<string,mixed> $d
     * @return list<array<string,mixed>>
     */
    private function uprawnienia(array $d): array
    {
        $wynik = [
            $this->tc('TC-020', 'Flow działa dla profilu użytkownika standardowego', [
                'steps' => [
                    'Zaloguj się jako użytkownik z profilem standardowym (nie administrator)',
                    'Uruchom Flow w normalny dla siebie sposób',
                    'Sprawdź wynik działania',
                ],
                'expected' => 'Flow wykonuje się bez błędu uprawnień; efekt taki sam jak dla administratora.',
            ]),
        ];

        if ((array) ($d['zapytania'] ?? []) !== []) {
            $wynik[] = $this->tc('TC-021', 'Flow nie ujawnia danych spoza uprawnień użytkownika', [
                'steps' => [
                    'Zaloguj się jako użytkownik bez dostępu do części rekordów pobieranych przez Flow',
                    'Uruchom Flow',
                    'Sprawdź, jakie dane zobaczył użytkownik',
                ],
                'expected' => 'Użytkownik nie widzi rekordów ani pól, do których nie ma uprawnień.',
                'priority' => Framework::PRIORYTET_WAZNY,
            ]);
        }

        return $wynik;
    }

    // ── Sklejanie ────────────────────────────────────────────────

    /**
     * @param array{preconditions?:?string, steps:list<string>, expected:string, priority?:string} $dane
     * @return array<string,mixed>
     */
    private function tc(string $checklistRef, string $tytul, array $dane): array
    {
        $kroki = [];
        $nr    = 1;

        foreach ($dane['steps'] as $krok) {
            $kroki[] = $nr++ . '. ' . rtrim(trim($krok), '.') . '.';
        }

        return [
            'tc_code'       => '',   // nadawany w ponumeruj()
            'checklist_ref' => $checklistRef,
            'category'      => Framework::kategoria($checklistRef) ?? 'Przypadki per typ Flow',
            'title'         => $tytul,
            'preconditions' => ($dane['preconditions'] ?? '') !== '' ? $dane['preconditions'] : null,
            'steps'         => implode("\n", $kroki),
            'expected'      => $dane['expected'],
            'priority'      => $dane['priority']
                ?? Framework::CHECKLISTA[$checklistRef]['priorytet']
                ?? Framework::PRIORYTET_KLUCZOWY,
        ];
    }

    /**
     * @param list<array<string,mixed>> $przypadki
     * @return list<array<string,mixed>>
     */
    private function ponumeruj(array $przypadki, string $typ): array
    {
        $nr = 1;

        foreach ($przypadki as $i => $tc) {
            $przypadki[$i]['tc_code']    = $typ . '-' . str_pad((string) $nr, 3, '0', STR_PAD_LEFT);
            $przypadki[$i]['sort_order'] = $nr;
            $nr++;
        }

        return array_values($przypadki);
    }

    /** @param list<array<string,mixed>> $ryzyka */
    private function maRegule(array $ryzyka, string $regula): bool
    {
        foreach ($ryzyka as $r) {
            if (($r['regula'] ?? null) === $regula) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $d */
    private function warunkiWstepne(array $d, string $kryteria): ?string
    {
        $czesci = ['Flow „' . (string) ($d['etykieta'] ?? '?') . '" jest aktywny w org.'];

        if ($kryteria !== '') {
            $czesci[] = 'Kryteria wejścia: ' . $kryteria . '.';
        }

        return implode(' ', $czesci);
    }

    /** @param list<array<string,mixed>> $zmienne */
    private function nazwyZmiennych(array $zmienne): string
    {
        return implode(', ', array_map(
            static fn (array $z): string => (string) ($z['nazwa'] ?? '?')
                . ' (' . (string) ($z['typ'] ?? '?') . ')',
            $zmienne
        ));
    }
}
