<?php

declare(strict_types=1);

namespace Flownatic\Generator;

/**
 * Sklada prompt do wklejenia w Claude.ai albo Claude Code.
 *
 * To jest most przez schowek: aplikacja przygotowuje komplet danych, czlowiek
 * przenosi je do modelu oplaconego subskrypcja i wraca z wynikiem. Zaden bajt
 * nie idzie stad do platnego API - decyzja z 2026-09-07.
 *
 * Prompt ma taka sama zawartosc, jaka poszlaby w wywolaniu API: staly opis
 * frameworku, digest Flow i wykryte ryzyka. Dzieki temu jakosc odpowiedzi jest
 * ta sama, a rachunku nie ma. Roznica jest wylacznie w transporcie: zamiast
 * HTTP jest Ctrl+C i Ctrl+V.
 *
 * Format wyjscia opisujemy **bardzo dokladnie**, bo po drugiej stronie nie ma
 * structured outputs, ktore wymusilyby schemat. Cala robote musi zrobic tekst
 * promptu, a ClipboardImporter i tak sprawdzi, co wrocilo.
 */
final class PromptBuilder
{
    /**
     * @param array<string,mixed>       $digest
     * @param list<array<string,mixed>> $ryzyka
     */
    public function zbuduj(array $digest, array $ryzyka): string
    {
        $typ = Framework::typFlow($digest);

        $czesci = [
            $this->rola(),
            $this->checklista($typ),
            $this->opisFlow($digest),
            $this->ryzyka($ryzyka),
            $this->format($typ),
        ];

        return implode("\n\n", array_filter($czesci, static fn (string $c): bool => $c !== ''));
    }

    private function rola(): string
    {
        return <<<TEKST
        Jesteś doświadczonym testerem manualnym Salesforce. Dostajesz opis struktury
        jednego Flow oraz listę ryzyk wykrytych automatycznie w jego metadanych.

        Twoim zadaniem jest napisać konkretne przypadki testowe dla TEGO Flow —
        takie, które da się wykonać krok po kroku w organizacji Salesforce, bez
        zaglądania do Flow Buildera. Pisz po polsku.

        Zasady:
        - od 15 do 30 przypadków, bez wypełniaczy,
        - każdy przypadek odwołuje się do jednej pozycji frameworku (pole checklist_ref),
        - używaj prawdziwych nazw elementów, obiektów i pól z opisu poniżej,
        - każde wykryte ryzyko MUSI mieć swój przypadek,
        - kroki są rozkazujące i konkretne („Utwórz rekord Account z polem Type = Customer"),
          a nie ogólnikowe („sprawdź, czy działa").
        TEKST;
    }

    private function checklista(string $typ): string
    {
        $linie = ['## Framework — dozwolone wartości pola checklist_ref', ''];
        $linie[] = 'Uniwersalna checklista:';

        foreach (Framework::CHECKLISTA as $kod => $poz) {
            $linie[] = '- ' . $kod . ' (' . $poz['kategoria'] . ') — ' . $poz['tytul'];
        }

        $przypadki = Framework::PER_TYP[$typ] ?? [];

        if ($przypadki !== []) {
            $linie[] = '';
            $linie[] = 'Przypadki właściwe dla tego typu Flow:';

            foreach ($przypadki as $kod => $tytul) {
                $linie[] = '- ' . $kod . ' — ' . $tytul;
            }
        }

        $linie[] = '';
        $linie[] = 'Nie wymyślaj innych kodów. Jeśli żaden nie pasuje idealnie, wybierz najbliższy.';

        return implode("\n", $linie);
    }

    /** @param array<string,mixed> $digest */
    private function opisFlow(array $digest): string
    {
        $json = json_encode($digest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return "## Struktura Flow\n\n```json\n" . (string) $json . "\n```";
    }

    /** @param list<array<string,mixed>> $ryzyka */
    private function ryzyka(array $ryzyka): string
    {
        if ($ryzyka === []) {
            return "## Wykryte ryzyka\n\nParser nie wykrył żadnych ryzyk w tym Flow.";
        }

        $linie = ['## Wykryte ryzyka — każde musi mieć swój przypadek testowy', ''];

        foreach ($ryzyka as $r) {
            $linie[] = '- **' . (string) ($r['tytul'] ?? '?') . '** (waga: ' . (string) ($r['waga'] ?? '?')
                . ', element: ' . (string) ($r['etykieta'] ?? $r['element'] ?? '?')
                . ', checklist_ref: ' . (string) ($r['checklist'] ?? '?') . ')';
            $linie[] = '  Skutek: ' . (string) ($r['skutek'] ?? '?');
            $linie[] = '  Jak testować: ' . (string) ($r['jak_testowac'] ?? '?');
        }

        return implode("\n", $linie);
    }

    private function format(string $typ): string
    {
        $priorytety = implode(' | ', [
            Framework::PRIORYTET_KLUCZOWY,
            Framework::PRIORYTET_WAZNY,
            Framework::PRIORYTET_STANDARD,
        ]);

        return <<<TEKST
        ## Format odpowiedzi

        Odpowiedz **wyłącznie** tablicą JSON — bez komentarza przed nią i po niej.
        Każdy element ma dokładnie te pola:

        ```json
        [
          {
            "checklist_ref": "{$typ}-001",
            "title": "Krótki tytuł przypadku",
            "preconditions": "Stan wyjściowy albo null",
            "steps": "1. Pierwszy krok.\\n2. Drugi krok.",
            "expected": "Co ma się wydarzyć",
            "priority": "{$priorytety}"
          }
        ]
        ```

        Pole steps to jeden ciąg znaków z krokami numerowanymi i rozdzielonymi znakiem nowej linii.
        Pole priority przyjmuje wyłącznie jedną z trzech podanych wartości.
        Nie dodawaj pola tc_code — kody nadaje aplikacja.
        TEKST;
    }
}
