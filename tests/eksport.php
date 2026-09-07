<?php

declare(strict_types=1);

/**
 * Sprawdzenie eksportu do .xlsx. Bez bazy, bez org.
 *
 * Uruchomienie:  php tests/eksport.php
 *
 * Test buduje skoroszyt z realnych fixture, zapisuje go i **otwiera ponownie**.
 * To jest sedno: plik, ktorego nie da sie odczytac, jest bezwartosciowy
 * niezaleznie od tego, jak dobrze wyglada kod, ktory go tworzy. Kryterium
 * Fazy 5 brzmi "pobierasz .xlsx, otwierasz i wyglada jak Twoj framework",
 * wiec test odtwarza dokladnie te droge.
 *
 * Gotowy plik zostaje w tests/out/ - mozna go otworzyc w Excelu i porownac
 * z SalesforcCloud_FTF.xlsx.
 */

$autoload = null;

for ($i = 1; $i <= 6; $i++) {
    $kandydat = dirname(__DIR__, $i) . '/app/vendor/autoload.php';

    if (is_file($kandydat)) {
        $autoload = $kandydat;
        break;
    }
}

if ($autoload === null) {
    fwrite(STDERR, 'Nie znalazlem app/vendor/autoload.php.' . PHP_EOL);
    exit(1);
}

require $autoload;

require_once __DIR__ . '/../app/src/Flow/DigestBuilder.php';
require_once __DIR__ . '/../app/src/Flow/RiskScanner.php';
require_once __DIR__ . '/../app/src/Generator/Framework.php';
require_once __DIR__ . '/../app/src/Generator/TestCaseSource.php';
require_once __DIR__ . '/../app/src/Generator/TemplateGenerator.php';
require_once __DIR__ . '/../app/src/Export/XlsxExporter.php';

use Flownatic\Export\XlsxExporter;
use Flownatic\Flow\DigestBuilder;
use Flownatic\Flow\RiskScanner;
use Flownatic\Generator\Framework;
use Flownatic\Generator\TemplateGenerator;
use PhpOffice\PhpSpreadsheet\IOFactory;

$wyjscie = __DIR__ . '/out';

if (!is_dir($wyjscie)) {
    mkdir($wyjscie, 0777, true);
}

$bledy = 0;

// ── Dane wejsciowe: realne metadane zepsutego Flow ───────────────
$meta   = json_decode((string) file_get_contents(__DIR__ . '/fixtures/bad-example.json'), true);
$digest = (new DigestBuilder())->build(is_array($meta) ? $meta : []);
$ryzyka = (new RiskScanner())->scan($digest);

$przypadki = array_map(
    static function (array $tc): array {
        $tc['source'] = 'reguly';

        return $tc;
    },
    (new TemplateGenerator())->generuj($digest, $ryzyka)
);

$flow = [
    'id'                  => 1,
    'api_name'            => 'RT_Flownatic_Bad_Example',
    'label'               => 'RT- Flownatic_Bad_Example',
    'process_type'        => 'AutoLaunchedFlow',
    'trigger_object'      => 'Account',
    'trigger_type'        => 'RecordAfterSave',
    'record_trigger_type' => 'Update',
    'version_number'      => 1,
    'is_active'           => 1,
];

$inwentarz = [
    $flow,
    ['label' => 'SF- Onboarding', 'api_name' => 'SF_Onboarding', 'process_type' => 'Flow',
     'trigger_object' => null, 'trigger_type' => null, 'record_trigger_type' => null,
     'version_number' => 3, 'is_active' => 1],
    ['label' => 'SCH- Nightly Cleanup', 'api_name' => 'SCH_Nightly', 'process_type' => 'AutoLaunchedFlow',
     'trigger_object' => 'Opportunity', 'trigger_type' => 'Scheduled', 'record_trigger_type' => null,
     'version_number' => 2, 'is_active' => 0],
];

// ── Budowa i zapis ───────────────────────────────────────────────
$eksporter = new XlsxExporter();

try {
    $skoroszyt = $eksporter->zbuduj($flow, $digest, $przypadki, $inwentarz,
        'https://resilient-narwhal-j9207g-dev-ed.trailblaze.my.salesforce.com');
    $tymczasowy = $eksporter->doPliku($skoroszyt);
} catch (\Throwable $e) {
    echo '[BLAD] budowa pliku: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

$docelowy = $wyjscie . '/' . $eksporter->nazwaPliku($flow);
copy($tymczasowy, $docelowy);
unlink($tymczasowy);

printf("%-24s %s  %d KB\n", 'zapis pliku', '[OK] ', (int) round((int) filesize($docelowy) / 1024));

// ── Otwarcie z powrotem ──────────────────────────────────────────
try {
    $odczytany = IOFactory::load($docelowy);
} catch (\Throwable $e) {
    echo '[BLAD] plik sie nie otwiera: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

$lokalne = 0;

// 1. Szesc arkuszy w kolejnosci frameworku.
$oczekiwane = ['📖 Overview', '🗂️ Flow Inventory', '✅ Universal Checklist',
               '🧪 Test Cases', '🐛 Defect Log', '📊 Progress Tracker'];
$sa = $odczytany->getSheetNames();

if ($sa !== $oczekiwane) {
    echo '[BLAD] arkusze: ' . implode(' | ', $sa) . PHP_EOL;
    $lokalne++;
}

printf("%-24s %s  arkuszy: %d\n", 'uklad arkuszy', $lokalne === 0 ? '[OK] ' : '[BLAD]', count($sa));
$bledy += $lokalne;

// 2. Inwentarz ma wszystkie Flow z org.
$lokalne = 0;
$inw = $odczytany->getSheetByName('🗂️ Flow Inventory');

foreach (['Nazwa Flow', 'Trigger / Obiekt', 'Środowisko'] as $kol) {
    if (!str_contains((string) $inw?->toArray(null, true, false, false)[4][1] . ' '
        . implode(' ', array_map('strval', $inw?->toArray(null, true, false, false)[4] ?? [])), $kol)) {
        echo '[BLAD] inwentarz - brak naglowka: ' . $kol . PHP_EOL;
        $lokalne++;
    }
}

// Kolumna C (indeks 2) to nazwa Flow - toArray zwraca tablice 0-indeksowana,
// wiec kolumna A ma indeks 0. Pierwsza wersja tego testu filtrowala po
// kolumnie z numerem porzadkowym, ktory jest liczba, nie tekstem.
$wierszeInw = array_filter(
    $inw?->toArray(null, true, false, false) ?? [],
    static fn (array $w): bool => in_array(
        (string) ($w[2] ?? ''),
        ['RT- Flownatic_Bad_Example', 'SF- Onboarding', 'SCH- Nightly Cleanup'],
        true
    )
);

if (count($wierszeInw) !== 3) {
    echo '[BLAD] inwentarz - wierszy z Flow: ' . count($wierszeInw) . ', oczekiwano 3' . PHP_EOL;
    $lokalne++;
}

// Typ Flow rozpoznany po TriggerType, nie po ProcessType.
$tekstInw = implode(' ', array_map(
    static fn (array $w): string => implode(' ', array_map('strval', $w)),
    $inw?->toArray(null, true, false, false) ?? []
));

foreach (['Record-Triggered', 'Screen Flow', 'Scheduled', 'After Save', 'Nieaktywny'] as $czego) {
    if (!str_contains($tekstInw, $czego)) {
        echo '[BLAD] inwentarz - brak: ' . $czego . PHP_EOL;
        $lokalne++;
    }
}

printf("%-24s %s\n", 'Flow Inventory', $lokalne === 0 ? '[OK] ' : '[BLAD]');
$bledy += $lokalne;

// 3. Checklista ma komplet 26 pozycji frameworku.
$lokalne = 0;
$chk = implode(' ', array_map(
    static fn (array $w): string => implode(' ', array_map('strval', $w)),
    $odczytany->getSheetByName('✅ Universal Checklist')?->toArray(null, true, false, false) ?? []
));

foreach (array_keys(Framework::CHECKLISTA) as $kod) {
    if (!str_contains($chk, $kod)) {
        echo '[BLAD] checklista - brak pozycji ' . $kod . PHP_EOL;
        $lokalne++;
    }
}

if (!str_contains($chk, 'RT- Flownatic_Bad_Example')) {
    echo '[BLAD] checklista - metryczka bez nazwy Flow' . PHP_EOL;
    $lokalne++;
}

printf("%-24s %s  pozycji: %d\n", 'Universal Checklist',
    $lokalne === 0 ? '[OK] ' : '[BLAD]', count(Framework::CHECKLISTA));
$bledy += $lokalne;

// 4. Przypadki testowe co do sztuki.
$lokalne = 0;
$tcArkusz = $odczytany->getSheetByName('🧪 Test Cases')?->toArray(null, true, false, false) ?? [];

$kodyWPliku = [];

foreach ($tcArkusz as $w) {
    $kod = (string) ($w[1] ?? '');

    if (preg_match('/^(RT|SF|SCH|AL)-\d{3}$/', $kod) === 1) {
        $kodyWPliku[] = $kod;
    }
}

if (count($kodyWPliku) !== count($przypadki)) {
    echo '[BLAD] Test Cases - w pliku ' . count($kodyWPliku)
        . ' przypadkow, w bazie ' . count($przypadki) . PHP_EOL;
    $lokalne++;
}

$tekstTc = implode(' ', array_map(
    static fn (array $w): string => implode(' ', array_map('strval', $w)),
    $tcArkusz
));

// Tresc, nie tylko kody: kroki i odwolanie do frameworku musza tam byc.
foreach (['Loop Through Contacts', 'Ref: TC-', 'Oczekiwany wynik'] as $czego) {
    if (!str_contains($tekstTc, $czego)) {
        echo '[BLAD] Test Cases - brak: ' . $czego . PHP_EOL;
        $lokalne++;
    }
}

printf("%-24s %s  przypadkow: %d\n", 'Test Cases',
    $lokalne === 0 ? '[OK] ' : '[BLAD]', count($kodyWPliku));
$bledy += $lokalne;

// 5. Defect Log pusty, ale z naglowkami.
$lokalne = 0;
$def = $odczytany->getSheetByName('🐛 Defect Log')?->toArray(null, true, false, false) ?? [];
$tekstDef = implode(' ', array_map(
    static fn (array $w): string => implode(' ', array_map('strval', $w)),
    $def
));

foreach (['Severity', 'Kroki reprodukcji', 'Expected vs Actual'] as $czego) {
    if (!str_contains($tekstDef, $czego)) {
        echo '[BLAD] Defect Log - brak naglowka: ' . $czego . PHP_EOL;
        $lokalne++;
    }
}

if (str_contains($tekstDef, 'DEF-001')) {
    echo '[BLAD] Defect Log - zawiera przykladowe dane z arkusza wzorcowego' . PHP_EOL;
    $lokalne++;
}

printf("%-24s %s\n", 'Defect Log', $lokalne === 0 ? '[OK] ' : '[BLAD]');
$bledy += $lokalne;

// 6. Progress Tracker liczy sam - formuly musza przetrwac zapis i odczyt.
$lokalne = 0;
$post = $odczytany->getSheetByName('📊 Progress Tracker');

$formuly = [
    'D13' => 'COUNTIF',
    'H13' => 'IFERROR',
    'C15' => 'SUM',
    'C19' => 'COUNTIF',
];

foreach ($formuly as $komorka => $czego) {
    $wartosc = (string) $post?->getCell($komorka)->getValue();

    if (!str_contains($wartosc, $czego)) {
        echo '[BLAD] Progress Tracker - ' . $komorka . ' bez formuly ' . $czego
            . ' (jest: ' . mb_substr($wartosc, 0, 30) . ')' . PHP_EOL;
        $lokalne++;
    }
}

// Licznik przypadkow wpisany na sztywno - to jedyna liczba, ktora znamy z bazy.
if ((int) ($post?->getCell('C13')->getValue() ?? 0) !== count($przypadki)) {
    echo '[BLAD] Progress Tracker - Lacznie TC nie zgadza sie z liczba przypadkow' . PHP_EOL;
    $lokalne++;
}

printf("%-24s %s\n", 'Progress Tracker', $lokalne === 0 ? '[OK] ' : '[BLAD]');
$bledy += $lokalne;

echo PHP_EOL . ($bledy === 0
    ? 'Wszystko sie zgadza. Plik do obejrzenia: tests/out/' . basename($docelowy) . PHP_EOL
    : $bledy . ' problemow.' . PHP_EOL);

exit($bledy === 0 ? 0 : 1);
