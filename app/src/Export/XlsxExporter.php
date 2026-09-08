<?php

declare(strict_types=1);

namespace Flownatic\Export;

use Flownatic\Generator\Framework;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Buduje plik .xlsx w ukladzie SalesforcCloud_FTF.xlsx.
 *
 * To jest domkniecie petli calego narzedzia: z org wychodzi plik, ktory
 * tester zna, bo to jego wlasny framework - tyle ze wypelniony konkretami
 * zamiast przykladami.
 *
 * Uklad NIE jest wymyslony. Kolory, szerokosci kolumn, naglowki i kolejnosc
 * arkuszy zostaly odczytane z oryginalnego pliku (2026-09-07) i sa tu
 * odtworzone: pasek tytulu 1F3864, naglowki tabel 2E75B6, wiersze
 * naprzemiennie DEEAF1 i biale, pola do wypelnienia przez testera FFF2CC.
 * Tresc zaczyna sie od kolumny B - kolumna A jest waskim marginesem.
 *
 * Co wypelniamy, a co zostawiamy puste:
 * - Flow Inventory i Test Cases - z bazy, bo to one sa efektem pracy narzedzia,
 * - Universal Checklist - pozycje frameworku z kolumna Status do odhaczenia,
 * - Defect Log i Progress Tracker - puste, ale z formulami i listami wyboru,
 *   bo to miejsce pracy testera, a nie nasze.
 */
final class XlsxExporter
{
    private const GRANAT   = 'FF1F3864';   // pasek tytulu
    private const NIEBIESKI = 'FF2E75B6';  // naglowek tabeli
    private const JASNY    = 'FFBDD7EE';   // naglowek kolumn w sekcji
    private const ZEBRA    = 'FFDEEAF1';   // co drugi wiersz
    private const BIALY    = 'FFFFFFFF';
    private const DOPISZ   = 'FFFFF2CC';   // pole do wypelnienia przez testera
    private const SZARY    = 'FF595959';   // podtytul

    /** Statusy wykonania przypadku - lista wyboru w arkuszach. */
    private const STATUSY = 'Pass,Fail,Blocked,N/A';

    /**
     * @param array<string,mixed>       $flow      wybrany Flow (wiersz z flows)
     * @param array<string,mixed>|null  $digest
     * @param list<array<string,mixed>> $przypadki wiersze z test_cases
     * @param list<array<string,mixed>> $inwentarz wszystkie Flow z org
     */
    public function zbuduj(
        array $flow,
        ?array $digest,
        array $przypadki,
        array $inwentarz,
        ?string $instancja = null
    ): Spreadsheet {
        $plik = new Spreadsheet();
        $plik->removeSheetByIndex(0);

        $this->overview($plik);
        $this->inwentarz($plik, $inwentarz, $instancja);
        $this->checklista($plik, $flow, $instancja);
        $this->przypadki($plik, $flow, $digest, $przypadki);
        $this->defekty($plik);
        $this->postep($plik, $flow, count($przypadki));

        $plik->setActiveSheetIndex(3);   // otwiera sie na Test Cases - to jest produkt

        return $plik;
    }

    /**
     * Zapisuje skoroszyt do pliku tymczasowego i zwraca sciezke.
     *
     * Zapis idzie do pliku, a nie do pamieci, bo memory_limit na produkcji
     * to 128 MB i nie ma powodu trzymac tam calego .xlsx.
     */
    public function doPliku(Spreadsheet $plik): string
    {
        $sciezka = tempnam(sys_get_temp_dir(), 'flownatic_') ?: '';

        if ($sciezka === '') {
            throw new \RuntimeException('Nie moge utworzyc pliku tymczasowego na eksport.');
        }

        (new Xlsx($plik))->save($sciezka);

        // Zwolnienie pamieci od razu - przy kilkudziesieciu Flow to ma znaczenie.
        $plik->disconnectWorksheets();

        return $sciezka;
    }

    /** Nazwa pliku, ktora zobaczy uzytkownik. */
    public function nazwaPliku(array $flow): string
    {
        $api = (string) ($flow['api_name'] ?? 'Flow');
        $api = preg_replace('/[^A-Za-z0-9_-]/', '_', $api) ?? 'Flow';

        return 'Flownatic_' . $api . '_' . date('Y-m-d') . '.xlsx';
    }

    // ── Arkusze ──────────────────────────────────────────────────

    private function overview(Spreadsheet $plik): void
    {
        $a = $this->nowyArkusz($plik, '📖 Overview');

        $this->tytul($a, '🚀 Sales Cloud Flow Testing Framework', 'B2:D2');
        $a->setCellValue('B3', 'Akcelerator do manualnego testowania Salesforce Flow');
        $a->setCellValue('C3', 'v1.0');
        $a->setCellValue('D3', date('Y-m-d'));
        $this->podtytul($a, 'B3:D3');

        $this->naglowekSekcji($a, 'B5', '📋 ZAWARTOŚĆ FRAMEWORKU', 'B5:D5');
        $this->naglowekKolumn($a, 6, ['Arkusz', 'Opis', 'Kolejność']);

        $zawartosc = [
            ['📖 Overview', 'Ten arkusz — opis i instrukcja użycia', ''],
            ['🗂️ Flow Inventory', 'Inwentarz wszystkich Flow w org przed testami', 'Krok 1'],
            ['✅ Universal Checklist', 'Checklist dla każdego typu Flow', 'Krok 2'],
            ['🧪 Test Cases', 'Szczegółowe przypadki testowe per typ Flow', 'Krok 3'],
            ['🐛 Defect Log', 'Rejestr błędów ze specyfiką Salesforce Flow', 'Krok 4'],
            ['📊 Progress Tracker', 'Śledzenie postępu testów i statystyki', 'Krok 5'],
        ];

        $this->wiersze($a, 7, $zawartosc, 3);

        $this->naglowekSekcji($a, 'B14', '💡 JAK UŻYWAĆ TEGO FRAMEWORKU', 'B14:D14');

        $jak = [
            ['1️⃣ Wypełnij Flow Inventory', 'Inwentarz jest już wypełniony przez Flownatic — sprawdź i uzupełnij środowisko.', ''],
            ['2️⃣ Użyj Universal Checklist', 'Dla testowanego Flow przejdź przez checklistę i oznacz status.', ''],
            ['3️⃣ Uruchom Test Cases', 'Przypadki są wygenerowane z metadanych tego Flow — wykonaj je krok po kroku.', ''],
            ['4️⃣ Loguj błędy w Defect Log', 'Każdy znaleziony błąd zapisz z krokami reprodukcji.', ''],
            ['5️⃣ Śledź postęp', 'Progress Tracker zlicza statystyki na podstawie Twoich wpisów.', ''],
        ];

        $this->wiersze($a, 15, $jak, 3);

        $a->setCellValue('B21', 'Wygenerowane przez Flownatic ' . date('Y-m-d H:i')
            . ' — przypadki testowe pochodzą z metadanych Flow, nie z przykładów.');
        $this->podtytul($a, 'B21:D21');

        $this->szerokosci($a, ['A' => 4, 'B' => 30, 'C' => 62, 'D' => 12]);
    }

    /** @param list<array<string,mixed>> $flows */
    private function inwentarz(Spreadsheet $plik, array $flows, ?string $instancja): void
    {
        $a = $this->nowyArkusz($plik, '🗂️ Flow Inventory');

        $this->tytul($a, '🗂️ FLOW INVENTORY — Inwentarz Flow w Org', 'B2:H2');
        $a->setCellValue('B3', 'Wypełnione automatycznie z org. Źródło: FlowDefinitionView przez Tooling API.');
        $this->podtytul($a, 'B3:H3');

        $this->naglowekKolumn($a, 5, ['#', 'Nazwa Flow', 'Typ Flow', 'Trigger / Obiekt', 'Wersja', 'Status', 'Środowisko']);

        $srodowisko = $instancja !== null ? $this->host($instancja) : '';
        $wiersze = [];
        $nr = 1;

        foreach ($flows as $f) {
            $wiersze[] = [
                $nr++,
                (string) ($f['label'] ?? $f['api_name'] ?? '?'),
                $this->typCzytelny($f),
                $this->wyzwalaczCzytelny($f),
                ($f['version_number'] ?? null) !== null ? 'v' . (string) $f['version_number'] : '',
                !empty($f['is_active']) ? 'Aktywny' : 'Nieaktywny',
                $srodowisko,
            ];
        }

        $this->wiersze($a, 6, $wiersze, 7);
        $a->freezePane('A6');

        $this->szerokosci($a, ['A' => 4, 'B' => 4, 'C' => 34, 'D' => 20, 'E' => 30, 'F' => 9, 'G' => 12, 'H' => 24]);
    }

    /** @param array<string,mixed> $flow */
    private function checklista(Spreadsheet $plik, array $flow, ?string $instancja): void
    {
        $a = $this->nowyArkusz($plik, '✅ Universal Checklist');

        $this->tytul($a, '✅ UNIVERSAL FLOW TEST CHECKLIST', 'B2:F2');
        $a->setCellValue('B3', 'Stosuj dla każdego testowanego Flow. Jeden arkusz = jeden Flow.');
        $this->podtytul($a, 'B3:F3');

        // Metryczka: co wiemy - wypelniamy, czego nie wiemy - zostawiamy do wpisania.
        $metryczka = [
            5 => ['Nazwa Flow:', (string) ($flow['label'] ?? $flow['api_name'] ?? ''), 'Tester:', ''],
            6 => ['Typ Flow:', $this->typCzytelny($flow), 'Data testu:', ''],
            7 => ['Wersja:', ($flow['version_number'] ?? null) !== null ? 'v' . (string) $flow['version_number'] : '',
                  'Środowisko:', $instancja !== null ? $this->host($instancja) : ''],
        ];

        foreach ($metryczka as $w => [$etykieta1, $wartosc1, $etykieta2, $wartosc2]) {
            $a->setCellValue('B' . $w, $etykieta1);
            $a->setCellValue('C' . $w, $wartosc1);
            $a->setCellValue('D' . $w, $etykieta2);
            $a->setCellValue('E' . $w, $wartosc2);

            $this->etykietaPola($a, 'B' . $w);
            $this->etykietaPola($a, 'D' . $w);
            $this->poleDoWypelnienia($a, 'C' . $w);
            $this->poleDoWypelnienia($a, 'E' . $w);
        }

        // Sekcje checklisty w kolejnosci kategorii z arkusza wzorcowego.
        $ikony = [
            'Warunki uruchomienia'         => '🔵',
            'Logika i decyzje'             => '🟠',
            'Operacje na danych'           => '🟢',
            'Obsługa błędów'               => '🔴',
            'Uprawnienia i bezpieczeństwo' => '🟣',
            'Screen Flow'                  => '⚙️',
        ];

        $wKategoriach = [];

        foreach (Framework::CHECKLISTA as $kod => $poz) {
            $wKategoriach[$poz['kategoria']][$kod] = $poz;
        }

        $w = 9;

        foreach ($ikony as $kategoria => $ikona) {
            if (!isset($wKategoriach[$kategoria])) {
                continue;
            }

            $this->naglowekSekcji($a, 'B' . $w, $ikona . ' ' . mb_strtoupper($kategoria), 'B' . $w . ':F' . $w);
            $w++;

            $this->naglowekKolumn($a, $w, ['ID', 'Przypadek testowy', 'Priorytet', 'Status', 'Uwagi'], self::JASNY, 'FF000000');
            $w++;

            $pierwszy = $w;

            foreach ($wKategoriach[$kategoria] as $kod => $poz) {
                $a->setCellValue('B' . $w, $kod);
                $a->setCellValue('C' . $w, $poz['tytul']);
                $a->setCellValue('D' . $w, $poz['priorytet']);
                $this->zebra($a, 'B' . $w . ':F' . $w, $w - $pierwszy);
                $this->poleDoWypelnienia($a, 'E' . $w);
                $this->listaStatusow($a, 'E' . $w);
                $w++;
            }

            $w++;   // pusty wiersz miedzy sekcjami, jak w oryginale
        }

        $this->szerokosci($a, ['A' => 4, 'B' => 9, 'C' => 62, 'D' => 14, 'E' => 14, 'F' => 30]);
    }

    /**
     * @param array<string,mixed>       $flow
     * @param array<string,mixed>|null  $digest
     * @param list<array<string,mixed>> $przypadki
     */
    private function przypadki(Spreadsheet $plik, array $flow, ?array $digest, array $przypadki): void
    {
        $a = $this->nowyArkusz($plik, '🧪 Test Cases');

        $this->tytul($a, '🧪 SZCZEGÓŁOWE PRZYPADKI TESTOWE', 'B2:H2');
        $a->setCellValue('B3', 'Wygenerowane z metadanych Flow przez Flownatic. Kolumna Uwagi wskazuje pozycję frameworku.');
        $this->podtytul($a, 'B3:H3');

        $nazwa = (string) ($flow['label'] ?? $flow['api_name'] ?? '?');
        $this->naglowekSekcji($a, 'B5', '📌 ' . mb_strtoupper($nazwa) . ' — ' . $this->typCzytelny($flow), 'B5:H5');

        $this->naglowekKolumn($a, 6, ['ID', 'Nazwa testu', 'Kroki', 'Oczekiwany wynik', 'Status', 'Defekt #', 'Uwagi']);

        // Odrzucone nie trafiaja do pliku. Zapytanie w repozytorium juz je
        // odsiewa, ale ten sam warunek stoi tutaj drugi raz celowo: plik idzie
        // do klienta i nie moze zalezec od tego, czy wolajacy pamietal o filtrze.
        $przypadki = array_values(array_filter(
            $przypadki,
            static fn (array $tc): bool => (string) ($tc['status'] ?? '') !== 'odrzucony'
        ));

        $w = 7;
        $pierwszy = $w;

        foreach ($przypadki as $tc) {
            $uwagi = 'Ref: ' . (string) ($tc['checklist_ref'] ?? '?');

            if (($tc['preconditions'] ?? null) !== null && trim((string) $tc['preconditions']) !== '') {
                $uwagi .= "\nWarunki wstępne: " . (string) $tc['preconditions'];
            }

            if ((string) ($tc['source'] ?? '') === 'wklejone') {
                $uwagi .= "\n(przypadek z modelu)";
            }

            $a->setCellValue('B' . $w, (string) ($tc['tc_code'] ?? ''));
            $a->setCellValue('C' . $w, (string) ($tc['title'] ?? ''));
            $a->setCellValue('D' . $w, (string) ($tc['steps'] ?? ''));
            $a->setCellValue('E' . $w, (string) ($tc['expected'] ?? ''));
            $a->setCellValue('H' . $w, $uwagi);

            $this->zebra($a, 'B' . $w . ':H' . $w, $w - $pierwszy);
            $this->poleDoWypelnienia($a, 'F' . $w);
            $this->poleDoWypelnienia($a, 'G' . $w);
            $this->listaStatusow($a, 'F' . $w);

            $a->getStyle('D' . $w . ':E' . $w)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $a->getStyle('H' . $w)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $a->getRowDimension($w)->setRowHeight(-1);

            $w++;
        }

        if ($przypadki === []) {
            $a->setCellValue('C7', 'Brak wygenerowanych przypadków — kliknij „Generuj testy” w Flownatic.');
        }

        $a->freezePane('A7');
        $this->szerokosci($a, ['A' => 4, 'B' => 10, 'C' => 44, 'D' => 52, 'E' => 46, 'F' => 11, 'G' => 11, 'H' => 34]);
    }

    private function defekty(Spreadsheet $plik): void
    {
        $a = $this->nowyArkusz($plik, '🐛 Defect Log');

        $this->tytul($a, '🐛 DEFECT LOG — Rejestr Błędów Flow', 'B2:H2');

        $this->naglowekKolumn($a, 4, ['ID', 'Nazwa Flow', 'Element Flow', 'Severity', 'Priority', 'Kroki reprodukcji', 'Expected vs Actual']);

        // Pustych wierszy nie wypelniamy trescia, ale przygotowujemy do pracy:
        // zebra, listy wyboru i zawijanie tekstu w kolumnach opisowych.
        for ($i = 0; $i < 20; $i++) {
            $w = 5 + $i;
            $this->zebra($a, 'B' . $w . ':H' . $w, $i);
            $this->listaWyboru($a, 'E' . $w, 'Critical,High,Medium,Low');
            $this->listaWyboru($a, 'F' . $w, 'P1,P2,P3,P4');
            $a->getStyle('G' . $w . ':H' . $w)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        }

        $a->freezePane('A5');
        $this->szerokosci($a, ['A' => 4, 'B' => 11, 'C' => 30, 'D' => 28, 'E' => 12, 'F' => 10, 'G' => 46, 'H' => 46]);
    }

    /** @param array<string,mixed> $flow */
    private function postep(Spreadsheet $plik, array $flow, int $ilePrzypadkow): void
    {
        $a = $this->nowyArkusz($plik, '📊 Progress Tracker');

        $this->tytul($a, '📊 PROGRESS TRACKER — Postęp Testów Flow', 'B2:H2');

        $this->naglowekSekcji($a, 'B4', 'PODSUMOWANIE PROJEKTU', 'B4:H4');

        foreach ([5 => 'Nazwa projektu:', 6 => 'Sprint / Release:', 7 => 'Data startu testów:',
                  8 => 'Data końca testów:', 9 => 'Główny tester:'] as $w => $etykieta) {
            $a->setCellValue('B' . $w, $etykieta);
            $this->etykietaPola($a, 'B' . $w);
            $this->poleDoWypelnienia($a, 'C' . $w);
        }

        $this->naglowekSekcji($a, 'B11', 'STATUS PER FLOW', 'B11:H11');
        $this->naglowekKolumn($a, 12, ['Nazwa Flow', 'Łącznie TC', 'Pass ✅', 'Fail ❌', 'Blocked ⛔', 'N/A', '% Pass']);

        $w = 13;
        $a->setCellValue('B' . $w, (string) ($flow['label'] ?? $flow['api_name'] ?? '?'));
        $a->setCellValue('C' . $w, $ilePrzypadkow);

        // Liczniki licza sie same z arkusza Test Cases - tester wpisuje status
        // tylko w jednym miejscu, a nie w dwoch.
        $kolumnaStatus = "'🧪 Test Cases'!\$F:\$F";
        $a->setCellValue('D' . $w, '=COUNTIF(' . $kolumnaStatus . ',"Pass")');
        $a->setCellValue('E' . $w, '=COUNTIF(' . $kolumnaStatus . ',"Fail")');
        $a->setCellValue('F' . $w, '=COUNTIF(' . $kolumnaStatus . ',"Blocked")');
        $a->setCellValue('G' . $w, '=COUNTIF(' . $kolumnaStatus . ',"N/A")');
        $a->setCellValue('H' . $w, '=IFERROR(D13/C13,0)');
        $a->getStyle('H' . $w)->getNumberFormat()->setFormatCode('0%');
        $this->zebra($a, 'B' . $w . ':H' . $w, 0);

        $w = 15;
        $a->setCellValue('B' . $w, 'ŁĄCZNIE');
        $a->setCellValue('C' . $w, '=SUM(C13:C13)');

        foreach (['D', 'E', 'F', 'G'] as $kol) {
            $a->setCellValue($kol . $w, '=SUM(' . $kol . '13:' . $kol . '13)');
        }

        $a->setCellValue('H' . $w, '=IFERROR(D15/C15,0)');
        $a->getStyle('H' . $w)->getNumberFormat()->setFormatCode('0%');
        $a->getStyle('B' . $w . ':H' . $w)->getFont()->setBold(true);
        $this->tlo($a, 'B' . $w . ':H' . $w, self::ZEBRA);

        $this->naglowekSekcji($a, 'B17', 'PODSUMOWANIE DEFEKTÓW', 'B17:H17');
        $this->naglowekKolumn($a, 18, ['Severity', 'Łącznie', 'Open', 'In Progress', 'Closed', '% Closed']);

        $w = 19;

        foreach (['Critical', 'High', 'Medium', 'Low'] as $i => $severity) {
            $a->setCellValue('B' . $w, $severity);
            $a->setCellValue('C' . $w, '=COUNTIF(\'🐛 Defect Log\'!$E:$E,"' . $severity . '")');
            $a->setCellValue('D' . $w, 0);
            $a->setCellValue('E' . $w, 0);
            $a->setCellValue('F' . $w, 0);
            $a->setCellValue('G' . $w, '=IFERROR(F' . $w . '/C' . $w . ',0)');
            $a->getStyle('G' . $w)->getNumberFormat()->setFormatCode('0%');
            $this->zebra($a, 'B' . $w . ':H' . $w, $i);
            $w++;
        }

        $this->szerokosci($a, ['A' => 4, 'B' => 34, 'C' => 13, 'D' => 12, 'E' => 12, 'F' => 13, 'G' => 12, 'H' => 12]);
    }

    // ── Pomocnicze: styl ─────────────────────────────────────────

    private function nowyArkusz(Spreadsheet $plik, string $tytul): Worksheet
    {
        $a = $plik->createSheet();
        $a->setTitle($tytul);
        $a->setShowGridlines(false);

        return $a;
    }

    private function tytul(Worksheet $a, string $tekst, string $zakres): void
    {
        $a->setCellValue(explode(':', $zakres)[0], $tekst);
        $a->mergeCells($zakres);
        $this->tlo($a, $zakres, self::GRANAT);
        $a->getStyle($zakres)->getFont()->setBold(true)->setSize(14)->getColor()->setARGB(self::BIALY);
        $a->getStyle($zakres)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        // Numer wiersza z pierwszej komorki zakresu. Uwaga: filter_var na
        // "B2:D2" zwraca "22", a nie 2 - stad jawne wyrazenie regularne.
        preg_match('/(\d+)/', explode(':', $zakres)[0], $m);
        $a->getRowDimension((int) ($m[1] ?? 1))->setRowHeight(26);
    }

    private function podtytul(Worksheet $a, string $zakres): void
    {
        $a->getStyle($zakres)->getFont()->setSize(9)->getColor()->setARGB(self::SZARY);
    }

    private function naglowekSekcji(Worksheet $a, string $komorka, string $tekst, string $zakres): void
    {
        $a->setCellValue($komorka, $tekst);
        $a->mergeCells($zakres);
        $this->tlo($a, $zakres, self::NIEBIESKI);
        $a->getStyle($zakres)->getFont()->setBold(true)->setSize(10)->getColor()->setARGB(self::BIALY);
    }

    /** @param list<string> $naglowki */
    private function naglowekKolumn(
        Worksheet $a,
        int $wiersz,
        array $naglowki,
        string $tlo = self::NIEBIESKI,
        string $font = self::BIALY
    ): void {
        $kol = 'B';

        foreach ($naglowki as $tekst) {
            $a->setCellValue($kol . $wiersz, $tekst);
            $kol++;
        }

        $ostatnia = chr(ord('B') + count($naglowki) - 1);
        $zakres   = 'B' . $wiersz . ':' . $ostatnia . $wiersz;

        $this->tlo($a, $zakres, $tlo);
        $a->getStyle($zakres)->getFont()->setBold(true)->setSize(10)->getColor()->setARGB($font);
        $a->getStyle($zakres)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $this->ramka($a, $zakres);
    }

    /**
     * @param list<list<string|int>> $dane
     */
    private function wiersze(Worksheet $a, int $od, array $dane, int $kolumn): void
    {
        foreach ($dane as $i => $wiersz) {
            $w   = $od + $i;
            $kol = 'B';

            foreach ($wiersz as $wartosc) {
                $a->setCellValue($kol . $w, $wartosc);
                $kol++;
            }

            $ostatnia = chr(ord('B') + $kolumn - 1);
            $this->zebra($a, 'B' . $w . ':' . $ostatnia . $w, $i);
            $a->getStyle('B' . $w . ':' . $ostatnia . $w)->getAlignment()->setWrapText(true)
                ->setVertical(Alignment::VERTICAL_TOP);
        }
    }

    private function zebra(Worksheet $a, string $zakres, int $indeks): void
    {
        $this->tlo($a, $zakres, $indeks % 2 === 0 ? self::ZEBRA : self::BIALY);
        $a->getStyle($zakres)->getFont()->setSize(9);
        $this->ramka($a, $zakres);
    }

    private function tlo(Worksheet $a, string $zakres, string $kolor): void
    {
        $a->getStyle($zakres)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($kolor);
    }

    private function ramka(Worksheet $a, string $zakres): void
    {
        $a->getStyle($zakres)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFD9D9D9');
    }

    private function etykietaPola(Worksheet $a, string $komorka): void
    {
        $this->tlo($a, $komorka, self::ZEBRA);
        $a->getStyle($komorka)->getFont()->setBold(true)->setSize(9)->getColor()->setARGB(self::GRANAT);
    }

    private function poleDoWypelnienia(Worksheet $a, string $komorka): void
    {
        $this->tlo($a, $komorka, self::DOPISZ);
        $this->ramka($a, $komorka);
    }

    private function listaStatusow(Worksheet $a, string $komorka): void
    {
        $this->listaWyboru($a, $komorka, self::STATUSY);
    }

    private function listaWyboru(Worksheet $a, string $komorka, string $wartosci): void
    {
        $w = $a->getCell($komorka)->getDataValidation();
        $w->setType(DataValidation::TYPE_LIST);
        $w->setErrorStyle(DataValidation::STYLE_INFORMATION);
        $w->setAllowBlank(true);
        $w->setShowDropDown(true);
        $w->setShowErrorMessage(true);
        $w->setErrorTitle('Nieprawidłowa wartość');
        $w->setError('Wybierz jedną z wartości z listy.');
        $w->setFormula1('"' . $wartosci . '"');
    }

    /** @param array<string,int> $szerokosci */
    private function szerokosci(Worksheet $a, array $szerokosci): void
    {
        foreach ($szerokosci as $kol => $szer) {
            $a->getColumnDimension($kol)->setWidth($szer);
        }
    }

    // ── Pomocnicze: tresc ────────────────────────────────────────

    /** @param array<string,mixed> $flow */
    private function typCzytelny(array $flow): string
    {
        $proces  = (string) ($flow['process_type'] ?? '');
        $trigger = (string) ($flow['trigger_type'] ?? '');

        return match (true) {
            in_array($trigger, ['RecordBeforeSave', 'RecordAfterSave', 'RecordBeforeDelete'], true) => 'Record-Triggered',
            $trigger === 'Scheduled'  => 'Scheduled',
            $proces === 'Flow'        => 'Screen Flow',
            $proces === ''            => '',
            default                   => 'Auto-launched',
        };
    }

    /** @param array<string,mixed> $flow */
    private function wyzwalaczCzytelny(array $flow): string
    {
        $obiekt = (string) ($flow['trigger_object'] ?? '');
        $kiedy  = (string) ($flow['trigger_type'] ?? '');
        $co     = (string) ($flow['record_trigger_type'] ?? '');

        $opisKiedy = match ($kiedy) {
            'RecordBeforeSave'   => 'Before Save',
            'RecordAfterSave'    => 'After Save',
            'RecordBeforeDelete' => 'Before Delete',
            'Scheduled'          => 'Zaplanowany',
            default              => $kiedy,
        };

        if ($obiekt === '' && $opisKiedy === '') {
            return 'Uruchamiany ręcznie';
        }

        $czesci = array_filter([$obiekt, $opisKiedy]);
        $opis   = implode(' — ', $czesci);

        return $co !== '' ? $opis . ' (' . $co . ')' : $opis;
    }

    private function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : $url;
    }
}
