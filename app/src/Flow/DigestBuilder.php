<?php

declare(strict_types=1);

namespace Flownatic\Flow;

/**
 * Zamienia surowe metadane Flow na czytelny opis struktury.
 *
 * Najwazniejszy plik w projekcie. Metadane z Salesforce to obiekt z okolo
 * piecdziesiecioma kluczami, w wiekszosci pustymi listami i nullami. Model
 * dostaje stad maly, uporzadkowany opis - nie surowy JSON. Wrzucanie surowych
 * metadanych do modelu dawaloby losowa jakosc.
 *
 * Zero AI. Wszystko tutaj jest deterministyczne i powtarzalne.
 *
 * Struktura zrodlowa: Dev/reference/flow-metadata.md
 */
final class DigestBuilder
{
    /** Klucze metadanych zawierajace elementy wykonywalne. */
    private const RODZAJE = [
        'recordCreates'  => 'utworzenie',
        'recordUpdates'  => 'aktualizacja',
        'recordDeletes'  => 'usuniecie',
        'recordLookups'  => 'pobranie',
        'loops'          => 'petla',
        'decisions'      => 'decyzja',
        'assignments'    => 'przypisanie',
        'screens'        => 'ekran',
        'subflows'       => 'podflow',
        'actionCalls'    => 'akcja',
        'waits'          => 'oczekiwanie',
    ];

    /** Rodzaje wykonujace zapis do bazy - na nich zalezy nam najbardziej. */
    private const DML = ['recordCreates', 'recordUpdates', 'recordDeletes'];

    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public function build(array $meta): array
    {
        $elementy = $this->indeksElementow($meta);
        $wPetli   = $this->elementyWPetlach($meta, $elementy);

        return [
            'etykieta'     => $meta['label'] ?? null,
            'typ'          => $meta['processType'] ?? null,
            'status'       => $meta['status'] ?? null,
            'opis'         => $meta['description'] ?? null,
            'wyzwalacz'    => $this->wyzwalacz($meta),
            'podsumowanie' => $this->podsumowanie($meta),
            'dml'          => $this->dml($meta, $wPetli),
            'zapytania'    => $this->zapytania($meta),
            'petle'        => $this->petle($meta, $wPetli),
            'decyzje'      => $this->decyzje($meta),
            'ekrany'       => $this->ekrany($meta),
            'zmienne'      => $this->zmienne($meta),
        ];
    }

    /** @param array<string,mixed> $meta @return array<string,mixed> */
    private function wyzwalacz(array $meta): array
    {
        $s = $meta['start'] ?? [];

        if (!is_array($s)) {
            return ['rodzaj' => 'brak'];
        }

        $kryteria = $this->warunki($s['filters'] ?? [], $s['filterLogic'] ?? null);

        return [
            'obiekt'           => $s['object'] ?? null,
            'kiedy'            => $s['triggerType'] ?? null,
            'operacje'         => $s['recordTriggerType'] ?? null,
            'kryteria_wejscia' => $kryteria,
            'ma_kryteria'      => $kryteria !== [] || !empty($s['filterFormula']),
            'formula_wejscia'  => $s['filterFormula'] ?? null,
            'sciezki_czasowe'  => count((array) ($s['scheduledPaths'] ?? [])),
            'pierwszy_element' => $s['connector']['targetReference'] ?? null,
        ];
    }

    /**
     * Wszystkie elementy w jednym miejscu: nazwa => rodzaj, klucz, dane.
     *
     * @param array<string,mixed> $meta
     * @return array<string,array{rodzaj:string,klucz:string,el:array<string,mixed>}>
     */
    private function indeksElementow(array $meta): array
    {
        $indeks = [];

        foreach (self::RODZAJE as $klucz => $rodzaj) {
            foreach ((array) ($meta[$klucz] ?? []) as $el) {
                if (!is_array($el) || empty($el['name'])) {
                    continue;
                }

                $indeks[(string) $el['name']] = ['rodzaj' => $rodzaj, 'klucz' => $klucz, 'el' => $el];
            }
        }

        return $indeks;
    }

    /**
     * Nazwy elementow, do ktorych prowadzi dany element.
     *
     * Rozne rodzaje maja rozne wyjscia: decyzja ma po jednym na kazda regule
     * plus domyslne, petla ma osobne dla ciala i dla wyjscia. Bez tego
     * przejscie grafu gubiloby galezie.
     *
     * @param array<string,mixed> $el
     * @return list<string>
     */
    private function nastepniki(array $el, bool $zPetlaWyjsciowa = true): array
    {
        $cele = [];

        foreach (['connector', 'faultConnector', 'nextValueConnector', 'defaultConnector'] as $k) {
            if (!empty($el[$k]['targetReference'])) {
                $cele[] = (string) $el[$k]['targetReference'];
            }
        }

        if ($zPetlaWyjsciowa && !empty($el['noMoreValuesConnector']['targetReference'])) {
            $cele[] = (string) $el['noMoreValuesConnector']['targetReference'];
        }

        foreach ((array) ($el['rules'] ?? []) as $regula) {
            if (!empty($regula['connector']['targetReference'])) {
                $cele[] = (string) $regula['connector']['targetReference'];
            }
        }

        foreach ((array) ($el['waitEvents'] ?? []) as $zdarzenie) {
            if (!empty($zdarzenie['connector']['targetReference'])) {
                $cele[] = (string) $zdarzenie['connector']['targetReference'];
            }
        }

        return array_values(array_unique($cele));
    }

    /**
     * Ktore elementy leza w ciele ktorej petli.
     *
     * To jest sedno wykrywania DML w petli. Idziemy od nextValueConnector
     * i zbieramy wszystko osiagalne, ZANIM wrocimy do petli. Celowo NIE
     * schodzimy przez noMoreValuesConnector, bo to juz wyjscie z petli.
     *
     * Naiwne sprawdzenie w stylu „Flow ma petle oraz ma DML” dawaloby falszywe
     * alarmy: DML wykonany PO petli jest zupelnie poprawny.
     *
     * @param array<string,mixed> $meta
     * @param array<string,array{rodzaj:string,klucz:string,el:array<string,mixed>}> $indeks
     * @return array<string,list<string>>
     */
    private function elementyWPetlach(array $meta, array $indeks): array
    {
        $wynik = [];

        foreach ((array) ($meta['loops'] ?? []) as $petla) {
            if (!is_array($petla) || empty($petla['name'])) {
                continue;
            }

            $nazwaPetli = (string) $petla['name'];
            $start      = $petla['nextValueConnector']['targetReference'] ?? null;

            if ($start === null) {
                continue;
            }

            $doOdwiedzenia = [(string) $start];
            $odwiedzone    = [];

            while ($doOdwiedzenia !== []) {
                $biezacy = array_shift($doOdwiedzenia);

                if ($biezacy === $nazwaPetli || isset($odwiedzone[$biezacy])) {
                    continue;
                }

                $odwiedzone[$biezacy] = true;

                if (!isset($indeks[$biezacy])) {
                    continue;
                }

                $wynik[$biezacy][] = $nazwaPetli;

                $czyPetla = $indeks[$biezacy]['klucz'] === 'loops';

                foreach ($this->nastepniki($indeks[$biezacy]['el'], !$czyPetla) as $cel) {
                    $doOdwiedzenia[] = $cel;
                }
            }
        }

        return array_map(static fn (array $l): array => array_values(array_unique($l)), $wynik);
    }

    /** @param array<string,mixed> $meta @return array<string,int> */
    private function podsumowanie(array $meta): array
    {
        $p = [];
        foreach (self::RODZAJE as $klucz => $rodzaj) {
            $ile = count((array) ($meta[$klucz] ?? []));
            if ($ile > 0) { $p[$rodzaj] = $ile; }
        }
        return $p;
    }

    /**
     * Operacje zapisu - co, na czym, czy w petli, czy ma obsluge bledu.
     *
     * @param array<string,mixed> $meta
     * @param array<string,list<string>> $wPetli
     * @return list<array<string,mixed>>
     */
    private function dml(array $meta, array $wPetli): array
    {
        $wynik = [];
        foreach (self::DML as $klucz) {
            foreach ((array) ($meta[$klucz] ?? []) as $el) {
                if (!is_array($el)) { continue; }
                $nazwa = (string) ($el['name'] ?? '?');
                $wynik[] = [
                    'nazwa'    => $nazwa,
                    'etykieta' => $el['label'] ?? null,
                    'operacja' => self::RODZAJE[$klucz],
                    'obiekt'   => $el['object'] ?? ($el['inputReference'] ?? null),
                    'w_petli'  => $wPetli[$nazwa] ?? [],
                    // faultConnector pojawia sie jako klucz TYLKO gdy jest ustawiony.
                    'ma_fault' => !empty($el['faultConnector']['targetReference']),
                    'pola'     => $this->pola($el),
                ];
            }
        }
        return $wynik;
    }

    /** @param array<string,mixed> $meta @return list<array<string,mixed>> */
    private function zapytania(array $meta): array
    {
        $wynik = [];
        foreach ((array) ($meta['recordLookups'] ?? []) as $el) {
            if (!is_array($el)) { continue; }
            $filtry = $this->warunki($el['filters'] ?? [], $el['filterLogic'] ?? null);
            $wynik[] = [
                'nazwa'          => $el['name'] ?? null,
                'etykieta'       => $el['label'] ?? null,
                'obiekt'         => $el['object'] ?? null,
                'filtry'         => $filtry,
                'ma_filtry'      => $filtry !== [],
                'tylko_pierwszy' => (bool) ($el['getFirstRecordOnly'] ?? false),
                'ma_fault'       => !empty($el['faultConnector']['targetReference']),
            ];
        }
        return $wynik;
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,list<string>> $wPetli
     * @return list<array<string,mixed>>
     */
    private function petle(array $meta, array $wPetli): array
    {
        $wynik = [];
        foreach ((array) ($meta['loops'] ?? []) as $el) {
            if (!is_array($el) || empty($el['name'])) { continue; }
            $nazwa = (string) $el['name'];
            $cialo = [];
            foreach ($wPetli as $element => $petle) {
                if (in_array($nazwa, $petle, true)) { $cialo[] = $element; }
            }
            $wynik[] = [
                'nazwa'     => $nazwa,
                'etykieta'  => $el['label'] ?? null,
                'kolekcja'  => $el['collectionReference'] ?? null,
                'kolejnosc' => $el['iterationOrder'] ?? null,
                'w_ciele'   => $cialo,
                'po_petli'  => $el['noMoreValuesConnector']['targetReference'] ?? null,
            ];
        }
        return $wynik;
    }

    /** @param array<string,mixed> $meta @return list<array<string,mixed>> */
    private function decyzje(array $meta): array
    {
        $wynik = [];
        foreach ((array) ($meta['decisions'] ?? []) as $el) {
            if (!is_array($el)) { continue; }
            $galezie = [];
            foreach ((array) ($el['rules'] ?? []) as $regula) {
                $galezie[] = [
                    'nazwa'    => $regula['name'] ?? null,
                    'etykieta' => $regula['label'] ?? null,
                    'warunki'  => $this->warunki($regula['conditions'] ?? [], $regula['conditionLogic'] ?? null),
                    'prowadzi' => $regula['connector']['targetReference'] ?? null,
                ];
            }
            $wynik[] = [
                'nazwa'              => $el['name'] ?? null,
                'etykieta'           => $el['label'] ?? null,
                'galezie'            => $galezie,
                'domyslna'           => $el['defaultConnectorLabel'] ?? null,
                'prowadzi_domyslnie' => $el['defaultConnector']['targetReference'] ?? null,
            ];
        }
        return $wynik;
    }

    /** @param array<string,mixed> $meta @return list<array<string,mixed>> */
    private function ekrany(array $meta): array
    {
        $wynik = [];
        foreach ((array) ($meta['screens'] ?? []) as $el) {
            if (!is_array($el)) { continue; }
            $pola = [];
            foreach ((array) ($el['fields'] ?? []) as $pole) {
                if (!is_array($pole)) { continue; }
                $pola[] = [
                    'nazwa'     => $pole['name'] ?? null,
                    'rodzaj'    => $pole['fieldType'] ?? ($pole['dataType'] ?? null),
                    'etykieta'  => $pole['fieldText'] ?? null,
                    'wymagane'  => (bool) ($pole['isRequired'] ?? false),
                    'walidacja' => $pole['validationRule']['errorMessage'] ?? null,
                ];
            }
            $wynik[] = [
                'nazwa'    => $el['name'] ?? null,
                'etykieta' => $el['label'] ?? null,
                'pola'     => $pola,
            ];
        }
        return $wynik;
    }

    /** @param array<string,mixed> $meta @return list<array<string,mixed>> */
    private function zmienne(array $meta): array
    {
        $wynik = [];
        foreach ((array) ($meta['variables'] ?? []) as $el) {
            if (!is_array($el)) { continue; }
            $wynik[] = [
                'nazwa'     => $el['name'] ?? null,
                'typ'       => $el['dataType'] ?? null,
                'kolekcja'  => (bool) ($el['isCollection'] ?? false),
                'wejsciowa' => (bool) ($el['isInput'] ?? false),
                'wyjsciowa' => (bool) ($el['isOutput'] ?? false),
                'obiekt'    => $el['objectType'] ?? null,
            ];
        }
        return $wynik;
    }

    /**
     * Warunki w czytelnej formie: pole, operator, wartosc.
     *
     * Salesforce pakuje wartosc w obiekt z kilkunastoma polami, z ktorych
     * wypelnione jest zwykle jedno. Wyciagamy to jedno.
     *
     * @return list<string>
     */
    private function warunki(mixed $filtry, ?string $logika): array
    {
        $wynik = [];
        foreach ((array) $filtry as $f) {
            if (!is_array($f)) { continue; }
            $pole     = (string) ($f['field'] ?? ($f['leftValueReference'] ?? '?'));
            $operator = (string) ($f['operator'] ?? '?');
            $wartosc  = $this->wartosc($f['value'] ?? ($f['rightValue'] ?? null));
            $wynik[]  = trim($pole . ' ' . $operator . ' ' . $wartosc);
        }
        if ($wynik !== [] && $logika !== null && strtolower($logika) !== 'and') {
            $wynik[] = '[logika: ' . $logika . ']';
        }
        return $wynik;
    }

    /** Wyciaga jedyna wypelniona wartosc z obiektu wartosci Salesforce. */
    private function wartosc(mixed $v): string
    {
        if (!is_array($v)) {
            if ($v === null) { return ''; }
            return is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        }
        foreach (['stringValue', 'numberValue', 'dateValue', 'dateTimeValue', 'elementReference', 'formulaExpression'] as $k) {
            if (isset($v[$k]) && $v[$k] !== null && $v[$k] !== '') { return (string) $v[$k]; }
        }
        if (isset($v['booleanValue']) && $v['booleanValue'] !== null) {
            return $v['booleanValue'] ? 'true' : 'false';
        }
        return '';
    }

    /** @param array<string,mixed> $el @return list<string> */
    private function pola(array $el): array
    {
        $wynik = [];
        foreach ((array) ($el['inputAssignments'] ?? []) as $p) {
            if (!is_array($p)) { continue; }
            $wynik[] = trim((string) ($p['field'] ?? '?') . ' = ' . $this->wartosc($p['value'] ?? null));
        }
        return $wynik;
    }
}
