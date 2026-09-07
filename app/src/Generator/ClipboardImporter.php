<?php

declare(strict_types=1);

namespace Flownatic\Generator;

use RuntimeException;

/**
 * Wczytuje przypadki testowe wklejone z Claude.ai albo Claude Code.
 *
 * Druga polowa mostu przez schowek. PromptBuilder wypuszcza dane, czlowiek
 * przenosi je do modelu oplaconego subskrypcja, a ta klasa przyjmuje wynik
 * z powrotem - sprawdzajac go, zanim cokolwiek trafi do bazy.
 *
 * Sprawdzanie nie jest przesada. Po tamtej stronie nie ma structured outputs,
 * ktore wymusilyby schemat, wiec do pola wklei sie wszystko: JSON w plotku
 * markdown, JSON z komentarzem modelu przed nim, obiekt zamiast tablicy albo
 * zwykly tekst. Kazdy z tych przypadkow ma dac **czytelny komunikat**, a nie
 * blad 500 ani cicho zapisany smiec.
 *
 * Implementuje TestCaseSource, bo z punktu widzenia reszty aplikacji jest
 * dokladnie tym samym: zrodlem przypadkow dla jednego Flow.
 */
final class ClipboardImporter implements TestCaseSource
{
    /** Maksymalna liczba przypadkow z jednego wklejenia - zapora na przypadkowy wsad. */
    private const LIMIT = 100;

    public function __construct(private readonly string $wklejone)
    {
    }

    public function zrodlo(): string
    {
        return TestCaseRepository::ZRODLO_WKLEJONE;
    }

    /**
     * @param array<string,mixed>       $digest sluzy do nadania prefiksu kodow
     * @param list<array<string,mixed>> $ryzyka nieuzywane - wynik przyszedl juz gotowy
     * @return list<array<string,mixed>>
     */
    public function generuj(array $digest, array $ryzyka): array
    {
        $dane = $this->odczytaj($this->wklejone);
        $typ  = Framework::typFlow($digest);

        $wynik = [];
        $nr    = 1;

        foreach ($dane as $i => $tc) {
            if (!is_array($tc)) {
                throw new RuntimeException(
                    'Element ' . ($i + 1) . ' nie jest obiektem. Oczekiwana jest tablica obiektów.'
                );
            }

            $wynik[] = $this->jedenPrzypadek($tc, $i, $typ, $nr);
            $nr++;
        }

        if ($wynik === []) {
            throw new RuntimeException('Wklejony tekst nie zawiera ani jednego przypadku.');
        }

        return $wynik;
    }

    /**
     * Wyluskuje tablice z tego, co czlowiek wklail.
     *
     * @return list<mixed>
     */
    private function odczytaj(string $tekst): array
    {
        $tekst = trim($tekst);

        if ($tekst === '') {
            throw new RuntimeException('Pole jest puste — wklej odpowiedź modelu.');
        }

        $tekst = $this->bezPlotka($tekst);

        // Model lubi poprzedzic JSON zdaniem wprowadzajacym. Skoro i tak
        // szukamy tablicy, wystarczy wziac fragment od pierwszego nawiasu
        // kwadratowego do ostatniego - reszta to proza, nie dane.
        $poczatek = strpos($tekst, '[');
        $koniec   = strrpos($tekst, ']');

        if ($poczatek !== false && $koniec !== false && $koniec > $poczatek) {
            $tekst = substr($tekst, $poczatek, $koniec - $poczatek + 1);
        }

        $dane = json_decode($tekst, true);

        if ($dane === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                'To nie jest poprawny JSON (' . json_last_error_msg() . '). '
                . 'Skopiuj samą tablicę z odpowiedzi modelu.'
            );
        }

        // Zdarza sie opakowanie w obiekt: {"przypadki": [...]}.
        if (is_array($dane) && !array_is_list($dane)) {
            foreach (['przypadki', 'test_cases', 'testCases', 'cases', 'dane'] as $klucz) {
                if (isset($dane[$klucz]) && is_array($dane[$klucz])) {
                    $dane = $dane[$klucz];
                    break;
                }
            }
        }

        if (!is_array($dane) || !array_is_list($dane)) {
            throw new RuntimeException(
                'Oczekiwana jest tablica przypadków, a wklejony JSON jest czymś innym.'
            );
        }

        if (count($dane) > self::LIMIT) {
            throw new RuntimeException(
                'Wklejono ' . count($dane) . ' przypadków, a limit to ' . self::LIMIT . '.'
            );
        }

        return array_values($dane);
    }

    /** Zdejmuje plotek ```json ... ``` , w ktory model czesto pakuje odpowiedz. */
    private function bezPlotka(string $tekst): string
    {
        if (!str_contains($tekst, '```')) {
            return $tekst;
        }

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $tekst, $m) === 1) {
            return trim($m[1]);
        }

        return str_replace('```', '', $tekst);
    }

    /**
     * @param array<string,mixed> $tc
     * @return array<string,mixed>
     */
    private function jedenPrzypadek(array $tc, int $indeks, string $typ, int $nr): array
    {
        foreach (['title', 'steps', 'expected'] as $wymagane) {
            if (trim((string) ($tc[$wymagane] ?? '')) === '') {
                throw new RuntimeException(
                    'Przypadek ' . ($indeks + 1) . ' nie ma wypełnionego pola „' . $wymagane . '".'
                );
            }
        }

        $ref = (string) ($tc['checklist_ref'] ?? '');

        // Nieznany kod nie unieważnia przypadku - tresc moze byc dobra.
        // Podmieniamy na kod ogolny i idziemy dalej; inaczej jedna literowka
        // modelu kasowalaby cala wklejona prace.
        if (!Framework::znany($ref)) {
            $ref = $typ . '-001';

            if (!Framework::znany($ref)) {
                $ref = 'TC-001';
            }
        }

        return [
            'tc_code'       => $typ . '-' . str_pad((string) $nr, 3, '0', STR_PAD_LEFT),
            'checklist_ref' => $ref,
            'category'      => Framework::kategoria($ref) ?? 'Przypadki per typ Flow',
            'title'         => trim((string) $tc['title']),
            'preconditions' => trim((string) ($tc['preconditions'] ?? '')) !== ''
                ? trim((string) $tc['preconditions'])
                : null,
            'steps'         => trim((string) $tc['steps']),
            'expected'      => trim((string) $tc['expected']),
            'priority'      => $this->priorytet((string) ($tc['priority'] ?? '')),
            'sort_order'    => $nr,
        ];
    }

    /** Sprowadza priorytet do jednej z trzech wartosci z arkusza. */
    private function priorytet(string $podany): string
    {
        $p = mb_strtolower(trim($podany));

        return match (true) {
            str_starts_with($p, 'kluczow'), $p === 'high', $p === 'wysoki'
                => Framework::PRIORYTET_KLUCZOWY,
            str_starts_with($p, 'wazn'), str_starts_with($p, 'ważn'), $p === 'medium', $p === 'sredni', $p === 'średni'
                => Framework::PRIORYTET_WAZNY,
            str_starts_with($p, 'standard'), $p === 'low', $p === 'niski'
                => Framework::PRIORYTET_STANDARD,
            default => Framework::PRIORYTET_WAZNY,
        };
    }
}
