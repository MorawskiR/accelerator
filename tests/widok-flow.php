<?php

declare(strict_types=1);

/**
 * Sprawdzenie widoku Flow bez bazy i bez org.
 *
 * Uruchomienie:  php tests/widok-flow.php
 *
 * Renderuje flow.twig na zapisanych metadanych z tests/fixtures i sprawdza,
 * ze na celowo zepsutym Flow widac oba ryzyka z kryterium "Gotowe, gdy"
 * Fazy 3: DML w petli i brak fault path.
 *
 * Nie dotyka bazy - DigestBuilder i RiskScanner sa czystymi funkcjami, a
 * szablon dostaje dane wprost. Dzieki temu regresje w widoku wychodza tutaj,
 * a nie dopiero na produkcji.
 *
 * Wynik HTML laduje do tests/out/ - mozna go otworzyc w przegladarce
 * i obejrzec dokladnie to, co zobaczy tester.
 */

// ── Autoloader ───────────────────────────────────────────────────
// Szukamy w gore, bo vendor/ nie jest w repozytorium: przy pracy w worktree
// lezy w glownym katalogu roboczym, kilka poziomow wyzej.
$autoload = null;

for ($i = 1; $i <= 6; $i++) {
    $kandydat = dirname(__DIR__, $i) . '/app/vendor/autoload.php';

    if (is_file($kandydat)) {
        $autoload = $kandydat;
        break;
    }
}

if ($autoload === null) {
    fwrite(STDERR, 'Nie znalazlem app/vendor/autoload.php. Uruchom composer install.' . PHP_EOL);
    exit(1);
}

require $autoload;

// Klasy aplikacji bierzemy z TEGO katalogu roboczego, nie z tego, na ktory
// wskazuje autoloader - inaczej w worktree testowalibysmy cudzy kod.
require_once __DIR__ . '/../app/src/Flow/DigestBuilder.php';
require_once __DIR__ . '/../app/src/Flow/RiskScanner.php';

use Flownatic\Flow\DigestBuilder;
use Flownatic\Flow\RiskScanner;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

$twig = new Environment(new FilesystemLoader(__DIR__ . '/../app/templates'), ['debug' => true]);

$wyjscie = __DIR__ . '/out';

if (!is_dir($wyjscie)) {
    mkdir($wyjscie, 0777, true);
}

/**
 * Renderuje widok dla jednego pliku z metadanymi.
 *
 * @return array{html:string, ryzyka:list<array<string,mixed>>}
 */
function renderuj(Environment $twig, string $plik): array
{
    $meta = json_decode((string) file_get_contents($plik), true);

    if (!is_array($meta)) {
        throw new RuntimeException('Nieczytelny fixture: ' . $plik);
    }

    $digest = (new DigestBuilder())->build($meta);
    $ryzyka = (new RiskScanner())->scan($digest);

    // Ta sama kolejnosc, ktora ustawia FlowAnalyzer: najgrozniejsze na gorze.
    $wagi = [RiskScanner::WAGA_WYSOKA => 0, RiskScanner::WAGA_SREDNIA => 1, RiskScanner::WAGA_NISKA => 2];
    usort($ryzyka, static fn (array $a, array $b): int
        => ($wagi[$a['waga']] ?? 9) <=> ($wagi[$b['waga']] ?? 9));

    $html = $twig->render('flow.twig', [
        'flow' => [
            'id'                  => 1,
            'label'               => $digest['etykieta'] ?? basename($plik, '.json'),
            'api_name'            => basename($plik, '.json'),
            'process_type'        => $digest['typ'] ?? null,
            'trigger_object'      => $digest['wyzwalacz']['obiekt'] ?? null,
            'record_trigger_type' => $digest['wyzwalacz']['operacje'] ?? null,
            'version_number'      => 1,
            'is_active'           => 1,
            'description'         => $digest['opis'] ?? null,
        ],
        'wersja'       => ['version_number' => 1, 'status' => $digest['status'] ?? null,
                           'fetched_at' => '2026-09-07 10:00:00', 'digested_at' => '2026-09-07 10:00:01'],
        'digest'       => $digest,
        'ryzyka'       => $ryzyka,
        'podsumowanie' => RiskScanner::podsumuj($ryzyka),
        'testy'        => $GLOBALS['testy'] ?? [],
        'zrodla'       => $GLOBALS['zrodla'] ?? [],
        'stanTestow'   => $GLOBALS['stanTestow'] ?? ['nieaktualne' => false, 'wygenerowano' => null],
        'edytuj'       => $GLOBALS['edytuj'] ?? 0,
        'prompt'       => $GLOBALS['prompt'] ?? null,
        'polaczona'    => true,
        'blad'         => null,
        'ok'           => null,
        'u'            => ['flows' => '/flows', 'metadane' => '/flows/1/metadane',
                           'testy' => '/flows/1/testy', 'wklej' => '/flows/1/testy/wklej',
                           'eksport' => '/flows/1/eksport', 'dodaj' => '/flows/1/testy/dodaj',
                           'przypadek' => '/flows/1/testy', 'druk' => '/flows/1/druk',
                           'connect' => '/org/connect', 'wyloguj' => '/logout'],
    ]);

    return ['html' => $html, 'ryzyka' => $ryzyka];
}

/** @var list<array{plik:string, zawiera:list<string>, niezawiera:list<string>, ryzyk:?int}> $przypadki */
$przypadki = [
    [
        'plik'       => 'bad-example.json',
        'zawiera'    => ['DML wewnątrz pętli', 'Brak fault path przy zapisie', 'TC-018', 'TC-015'],
        'niezawiera' => ['Struktura nie jest jeszcze pobrana'],
        'ryzyk'      => null,   // liczba nie jest tu istotna, wazne ze sa oba
    ],
    [
        // Ten sam Flow, ale DML jest PO petli, ma fault path i kryteria wejscia.
        // Zaden falszywy alarm nie ma prawa sie tu zapalic.
        'plik'       => 'po-petli.json',
        'zawiera'    => ['Żadna z reguł nie zapaliła się'],
        'niezawiera' => ['DML wewnątrz pętli'],
        'ryzyk'      => 0,
    ],
    [
        'plik'       => 'czysty.json',
        'zawiera'    => ['Żadna z reguł nie zapaliła się'],
        'niezawiera' => ['waga wysokie'],
        'ryzyk'      => 0,
    ],
    [
        'plik'       => 'bez-filtrow.json',
        'zawiera'    => ['Pobranie rekordów bez filtrów', 'TC-010'],  // od 2026-09-07: TC-020 to uprawnienia
        'niezawiera' => [],
        'ryzyk'      => null,
    ],
];

$bledy = 0;

foreach ($przypadki as $p) {
    $sciezka = __DIR__ . '/fixtures/' . $p['plik'];

    try {
        $wynik = renderuj($twig, $sciezka);
    } catch (\Throwable $e) {
        echo '[BLAD] ' . $p['plik'] . ' - ' . $e->getMessage() . PHP_EOL;
        $bledy++;
        continue;
    }

    file_put_contents($wyjscie . '/' . basename($p['plik'], '.json') . '.html', $wynik['html']);

    $lokalne = 0;

    foreach ($p['zawiera'] as $tekst) {
        if (!str_contains($wynik['html'], $tekst)) {
            echo '[BLAD] ' . $p['plik'] . ' - brak w widoku: ' . $tekst . PHP_EOL;
            $lokalne++;
        }
    }

    foreach ($p['niezawiera'] as $tekst) {
        if (str_contains($wynik['html'], $tekst)) {
            echo '[BLAD] ' . $p['plik'] . ' - falszywy alarm, widok zawiera: ' . $tekst . PHP_EOL;
            $lokalne++;
        }
    }

    if ($p['ryzyk'] !== null && count($wynik['ryzyka']) !== $p['ryzyk']) {
        echo '[BLAD] ' . $p['plik'] . ' - ryzyk: ' . count($wynik['ryzyka'])
            . ', oczekiwano ' . $p['ryzyk'] . PHP_EOL;
        $lokalne++;
    }

    $bledy += $lokalne;

    printf(
        "%-20s %s  ryzyk: %d%s" . PHP_EOL,
        $p['plik'],
        $lokalne === 0 ? '[OK] ' : '[BLAD]',
        count($wynik['ryzyka']),
        $lokalne === 0 ? '' : '  (' . $lokalne . ' problemow)'
    );
}

// ── Widok z przypadkami testowymi ────────────────────────────────
// Renderujemy ten sam Flow raz jeszcze, tym razem z gotowymi przypadkami,
// zeby sprawdzic sekcje dodana w Fazie 4. Przypadki bierzemy z generatora,
// a nie wymyslamy - dzieki temu test lapie tez rozjazd miedzy szablonem
// a ksztaltem danych, ktore generator naprawde produkuje.
require_once __DIR__ . '/../app/src/Generator/Framework.php';
require_once __DIR__ . '/../app/src/Generator/TestCaseSource.php';
require_once __DIR__ . '/../app/src/Generator/TemplateGenerator.php';

$metaBad = json_decode((string) file_get_contents(__DIR__ . '/fixtures/bad-example.json'), true);
$digestBad = (new DigestBuilder())->build(is_array($metaBad) ? $metaBad : []);
$ryzykaBad = (new RiskScanner())->scan($digestBad);

$GLOBALS['testy'] = array_map(
    static function (array $t): array {
        // Wiersz z bazy ma jeszcze source i status - dokladamy je jak repozytorium.
        static $nr = 0;
        $t['id']     = ++$nr;
        $t['source'] = 'reguly';
        $t['status'] = 'draft';

        return $t;
    },
    (new \Flownatic\Generator\TemplateGenerator())->generuj($digestBad, $ryzykaBad)
);

$GLOBALS['zrodla'] = ['reguly' => count($GLOBALS['testy'])];

$zTestami = renderuj($twig, __DIR__ . '/fixtures/bad-example.json');
file_put_contents($wyjscie . '/bad-example-z-testami.html', $zTestami['html']);

$lokalne = 0;

foreach ([
    'Przypadki testowe',
    'Generuj ponownie',              // sa juz przypadki, wiec przycisk zmienia napis
    'RT-001',                        // kod pierwszego przypadku
    'href="/flows/1/eksport"',       // pobranie .xlsx pojawia sie razem z przypadkami
    'Akceptuj',                      // akceptacja przed eksportem
    'Odrzuć',
    '?edytuj=1',                     // wejscie w edycje bez JavaScriptu
    'Dopisz własny przypadek',
    'Widok do druku',
    'Oczekiwany wynik',
    'dopiski własne zostają nietknięte',
] as $tekst) {
    if (!str_contains($zTestami['html'], $tekst)) {
        echo '[BLAD] widok z testami - brak: ' . $tekst . PHP_EOL;
        $lokalne++;
    }
}

$bledy += $lokalne;
printf('%-20s %s  przypadkow: %d' . PHP_EOL, 'flow.twig/testy',
    $lokalne === 0 ? '[OK] ' : '[BLAD]', count($GLOBALS['testy']));

// Ten sam widok, ale metadane zmienily sie po wygenerowaniu przypadkow.
// Tester musi to zobaczyc - inaczej wykona testy opisujace poprzednia wersje.
$GLOBALS['stanTestow'] = [
    'nieaktualne'  => true,
    'wygenerowano' => '2026-09-07 12:00:00',
];

$przeterminowane = renderuj($twig, __DIR__ . '/fixtures/bad-example.json');
file_put_contents($wyjscie . '/bad-example-nieaktualne.html', $przeterminowane['html']);

$lokalne = 0;

foreach (['Te przypadki opisują poprzednią wersję Flow', '2026-09-07 12:00:00'] as $tekst) {
    if (!str_contains($przeterminowane['html'], $tekst)) {
        echo '[BLAD] widok nieaktualnych - brak: ' . $tekst . PHP_EOL;
        $lokalne++;
    }
}

// Bez ostrzezenia, gdy wszystko jest swieze - falszywy alarm byloby gorszy
// niz brak alarmu, bo nauczylby testera ignorowac ten baner.
$GLOBALS['stanTestow'] = ['nieaktualne' => false, 'wygenerowano' => '2026-09-07 12:00:00'];
$swieze = renderuj($twig, __DIR__ . '/fixtures/bad-example.json');

if (str_contains($swieze['html'], 'opisują poprzednią wersję Flow')) {
    echo '[BLAD] falszywy alarm o nieaktualnosci na swiezych przypadkach' . PHP_EOL;
    $lokalne++;
}

$bledy += $lokalne;
printf('%-20s %s' . PHP_EOL, 'flow.twig/nieakt.', $lokalne === 0 ? '[OK] ' : '[BLAD]');

// ── Edycja przypadku ─────────────────────────────────────────────
// Formularz otwiera sie adresem (?edytuj=ID), bez JavaScriptu - wiec da sie
// go sprawdzic tym samym testem, co reszte widoku.
$GLOBALS['edytuj'] = 1;
$wEdycji = renderuj($twig, __DIR__ . '/fixtures/bad-example.json');
file_put_contents($wyjscie . '/bad-example-edycja.html', $wEdycji['html']);

$lokalne = 0;

foreach ([
    'action="/flows/1/testy/1/zapisz"',
    'name="steps"',
    'Zapisz zmiany',
    'Anuluj',
] as $tekst) {
    if (!str_contains($wEdycji['html'], $tekst)) {
        echo '[BLAD] edycja - brak: ' . $tekst . PHP_EOL;
        $lokalne++;
    }
}

// Formularz ma ZASTAPIC tresc przypadku, a nie pojawic sie obok niej.
// Porownujemy z widokiem bez edycji: jedna etykieta "Oczekiwany wynik"
// mniej, bo dokladnie jedna karta pokazuje teraz formularz.
$bezEdycji = substr_count($zTestami['html'], '<dt>Oczekiwany wynik</dt>');
$zEdycja   = substr_count($wEdycji['html'], '<dt>Oczekiwany wynik</dt>');

if ($zEdycja !== $bezEdycji - 1) {
    echo '[BLAD] edycja - kart z trescia: ' . $zEdycja . ', oczekiwano ' . ($bezEdycji - 1) . PHP_EOL;
    $lokalne++;
}

$GLOBALS['edytuj'] = 0;

$bledy += $lokalne;
printf('%-20s %s' . PHP_EOL, 'flow.twig/edycja', $lokalne === 0 ? '[OK] ' : '[BLAD]');

// ── Most przez schowek w widoku ──────────────────────────────────
require_once __DIR__ . '/../app/src/Generator/PromptBuilder.php';

$GLOBALS['prompt'] = (new \Flownatic\Generator\PromptBuilder())->zbuduj($digestBad, $ryzykaBad);

$zMostem = renderuj($twig, __DIR__ . '/fixtures/bad-example.json');
file_put_contents($wyjscie . '/bad-example-most.html', $zMostem['html']);

$lokalne = 0;

foreach ([
    'id="kopiuj-prompt"',
    'Kopiuj prompt',
    'action="/flows/1/testy/wklej"',
    'name="wynik"',
    'Wczytaj wynik',
] as $tekst) {
    if (!str_contains($zMostem['html'], $tekst)) {
        echo '[BLAD] most - brak: ' . $tekst . PHP_EOL;
        $lokalne++;
    }
}

// Prompt trafia do pola tekstowego, wiec musi byc zescapowany - inaczej
// cudzyslowy z przykladu JSON rozwalilyby atrybuty HTML.
if (str_contains($zMostem['html'], '<textarea id="prompt-tresc" readonly rows="6"') === false) {
    echo '[BLAD] most - brak pola z promptem' . PHP_EOL;
    $lokalne++;
}

// Bez promptu (brak metadanych) sekcja mostu ma sie nie pokazac.
$GLOBALS['prompt'] = null;
$bezMostu = renderuj($twig, __DIR__ . '/fixtures/czysty.json');

// Szukamy znacznika sekcji, a nie frazy: ta sama fraza jest komentarzem
// w arkuszu stylow i pierwsza wersja tego testu lapala wlasnie ja.
if (str_contains($bezMostu['html'], 'id="kopiuj-prompt"')) {
    echo '[BLAD] most pokazuje sie mimo braku promptu' . PHP_EOL;
    $lokalne++;
}

$bledy += $lokalne;
printf('%-20s %s' . PHP_EOL, 'flow.twig/most', $lokalne === 0 ? '[OK] ' : '[BLAD]');

$GLOBALS['testy']      = [];
$GLOBALS['zrodla']     = [];
$GLOBALS['stanTestow'] = ['nieaktualne' => false, 'wygenerowano' => null];
$GLOBALS['prompt']     = null;

// ── Widok do druku ───────────────────────────────────────────────
// Osobny szablon, celowo nie dziedziczacy z layout.twig: tam liczy sie
// ekran i ciemny motyw, tutaj kartka papieru.
// Przypadki budujemy tutaj od nowa, a nie z $GLOBALS - wczesniejsza sekcja
// zeruje ten klucz po sobie, wiec poleganie na nim dawalo pusta liste
// i test przechodzil na niczym.
$wszystkieTesty = (new \Flownatic\Generator\TemplateGenerator())->generuj($digestBad, $ryzykaBad);

foreach ($wszystkieTesty as $i => $t) {
    $wszystkieTesty[$i]['id']     = $i + 1;
    $wszystkieTesty[$i]['source'] = 'reguly';
    $wszystkieTesty[$i]['status'] = 'draft';
}

// Jeden przypadek odrzucony - nie ma prawa sie wydrukowac.
$wszystkieTesty[0]['status'] = 'odrzucony';
$kodOdrzucony = (string) $wszystkieTesty[0]['tc_code'];

$doDruku = array_values(array_filter(
    $wszystkieTesty,
    static fn (array $t): bool => (string) ($t['status'] ?? '') !== 'odrzucony'
));

$drukHtml = $twig->render('druk.twig', [
    'flow'      => ['label' => 'RT- Flownatic_Bad_Example', 'api_name' => 'RT_Bad',
                    'process_type' => 'AutoLaunchedFlow', 'trigger_object' => 'Account',
                    'trigger_type' => 'RecordAfterSave', 'record_trigger_type' => 'Update',
                    'version_number' => 1],
    'testy'     => $doDruku,
    'ryzyka'    => $ryzykaBad,
    'refTytuly' => ['TC-018' => 'Flow nie przekracza governor limits (DML, SOQL, CPU)'],
    'instancja' => 'https://przyklad.my.salesforce.com',
    'data'      => '2026-09-08',
    'u'         => ['flow' => '/flows/1'],
]);

file_put_contents($wyjscie . '/druk.html', $drukHtml);

$lokalne = 0;

foreach ([
    '@media print',                  // bez tego wydruk bierze style ekranowe
    'page-break-inside:avoid',       // przypadek nie lamie sie w poprzek stron
    'class="kratka"',                // kratki na wynik - to wydruk roboczy
    'onclick="window.print()"',
    'Tester',                        // metryczka do podpisania
] as $tekst) {
    if (!str_contains($drukHtml, $tekst)) {
        echo '[BLAD] druk - brak: ' . $tekst . PHP_EOL;
        $lokalne++;
    }
}

if (str_contains($drukHtml, $kodOdrzucony)) {
    echo '[BLAD] druk - odrzucony przypadek ' . $kodOdrzucony . ' trafil na wydruk' . PHP_EOL;
    $lokalne++;
}

if (count($doDruku) !== count($wszystkieTesty) - 1) {
    echo '[BLAD] druk - filtr odrzuconych wycial zla liczbe przypadkow' . PHP_EOL;
    $lokalne++;
}

$bledy += $lokalne;
printf('%-20s %s  przypadkow: %d' . PHP_EOL, 'druk.twig',
    $lokalne === 0 ? '[OK] ' : '[BLAD]', count($doDruku));

// ── Dashboard ────────────────────────────────────────────────────
// Do 2026-09-08 dashboard wymienial Fazy 3, 4 i 5 jako "czego jeszcze nie ma",
// mimo ze dzialaly na produkcji. Test pilnuje, zeby placeholder nie wrocil
// i zeby widok pokazywal liczby, a nie obietnice.
$dashPolaczony = $twig->render('dashboard.twig', [
    'email'      => 'tester@przyklad.pl',
    'polaczona'  => true,
    'instancja'  => 'https://przyklad.my.salesforce.com',
    'stat'       => ['flow' => 9, 'zMetadanymi' => 7, 'przypadki' => 42,
                     'ryzykaRazem' => 5, 'ryzykaWysokie' => 3],
    'wylogujUrl' => '/logout',
    'flowsUrl'   => '/flows',
    'srodowisko' => 'produkcja',
]);

file_put_contents($wyjscie . '/dashboard.html', $dashPolaczony);

$lokalne = 0;

foreach (['9', '42', '3 wysokich', 'Flow w inwentarzu', 'org podłączona'] as $tekst) {
    if (!str_contains($dashPolaczony, $tekst)) {
        echo '[BLAD] dashboard - brak: ' . $tekst . PHP_EOL;
        $lokalne++;
    }
}

foreach (['Czego jeszcze nie ma', 'powstanie w kolejnych fazach', 'Faza 3', 'Faza 4', 'Faza 5'] as $tekst) {
    if (str_contains($dashPolaczony, $tekst)) {
        echo '[BLAD] dashboard - wrocil placeholder: ' . $tekst . PHP_EOL;
        $lokalne++;
    }
}

// Zaleglosc w pobieraniu metadanych ma byc widoczna, a nie schowana.
if (!str_contains($dashPolaczony, '2 Flow czeka na pobranie metadanych')) {
    echo '[BLAD] dashboard - brak informacji o zaleglych metadanych' . PHP_EOL;
    $lokalne++;
}

// Bez org nie pokazujemy pustych kafelkow, tylko jedna akcje.
$dashBezOrg = $twig->render('dashboard.twig', [
    'email'      => 'tester@przyklad.pl',
    'polaczona'  => false,
    'instancja'  => null,
    'stat'       => ['flow' => 0, 'zMetadanymi' => 0, 'przypadki' => 0,
                     'ryzykaRazem' => 0, 'ryzykaWysokie' => 0],
    'wylogujUrl' => '/logout',
    'flowsUrl'   => '/flows',
    'srodowisko' => 'produkcja',
]);

// Szukamy znacznika, nie nazwy klasy: regula .stat-kafel jest w arkuszu
// stylow zawsze, wiec samo "stat-kafel" trafialoby w CSS, nie w tresc.
if (str_contains($dashBezOrg, '<div class="stat-kafel">')) {
    echo '[BLAD] dashboard bez org - pokazuje puste kafelki' . PHP_EOL;
    $lokalne++;
}

if (!str_contains($dashBezOrg, 'Zacznij od podłączenia org')) {
    echo '[BLAD] dashboard bez org - brak wezwania do podlaczenia' . PHP_EOL;
    $lokalne++;
}

$bledy += $lokalne;
printf('%-20s %s' . PHP_EOL, 'dashboard.twig', $lokalne === 0 ? '[OK] ' : '[BLAD]');

// ── Lista Flow ───────────────────────────────────────────────────
// Osobno, bo liczniki ryzyk na liscie biora sie z innego miejsca niz widok
// szczegolu: z FlowAnalyzer::podsumowania(), a nie z pelnej analizy.
$daneListy = [
    'polaczona'   => true,
    'instancja'   => 'https://przyklad.my.salesforce.com',
    'stan'        => ['wszystkie' => 3, 'pozostalo' => 1, 'gotowe' => 2, 'procent' => 67],
    'flows'       => [
        ['id' => 1, 'label' => 'RT- Flownatic_Bad_Example', 'api_name' => 'RT_Bad',
         'process_type' => 'AutoLaunchedFlow', 'trigger_object' => 'Account',
         'trigger_type' => 'RecordAfterSave', 'record_trigger_type' => 'CreateAndUpdate',
         'version_number' => 1, 'is_active' => 1],
        ['id' => 2, 'label' => 'RT- Czysty', 'api_name' => 'RT_Czysty',
         'process_type' => 'AutoLaunchedFlow', 'trigger_object' => 'Account',
         'trigger_type' => 'RecordBeforeSave', 'record_trigger_type' => 'Update',
         'version_number' => 3, 'is_active' => 1],
        ['id' => 3, 'label' => 'SF- Bez metadanych', 'api_name' => 'SF_Bez',
         'process_type' => 'Flow', 'trigger_object' => null,
         'trigger_type' => null, 'record_trigger_type' => null,
         'version_number' => 1, 'is_active' => 0],
    ],
    'typy'        => ['AutoLaunchedFlow', 'Flow'],
    'ryzyka'      => [
        1 => ['ryzyka' => ['wysokie' => 3, 'srednie' => 2, 'niskie' => 0], 'razem' => 5],
        2 => ['ryzyka' => ['wysokie' => 0, 'srednie' => 0, 'niskie' => 0], 'razem' => 0],
        // Flow nr 3 celowo nie ma wpisu - metadane niepobrane.
    ],
    'wybranyTyp'  => '',
    'wybranyStan' => '',
    'blad'        => null,
    'ok'          => null,
    'u'           => ['connect' => '/org/connect', 'disconnect' => '/org/disconnect',
                      'sync' => '/flows/sync', 'flows' => '/flows', 'metadane' => '/flows/metadane',
                      'partia' => '/flows/metadane/partia', 'stan' => '/flows/metadane/stan',
                      'wyloguj' => '/logout'],
];

$listaHtml = $twig->render('flows.twig', $daneListy);

file_put_contents($wyjscie . '/lista.html', $listaHtml);

$oczekiwaneNaLiscie = [
    'href="/flows/1"',                      // nazwa jest linkiem do szczegolu
    'metadane niepobrane',                  // Flow bez wersji nie udaje przeanalizowanego
    'czysto',                               // zero ryzyk to informacja, nie pusta komorka
    'action="/flows/metadane"',             // import dziala takze bez JavaScriptu
    'data-partia="/flows/metadane/partia"', // adres partii dla skryptu
    'width:67%',                            // pasek odwzorowuje stan kolejki
    'zostało 1',                            // licznik mowi wprost, ile brakuje
    '3 wysokie',                            // kolumna ryzyk czytelna bez koloru
    '2 średnie',
    '<noscript>',                           // i co robic, gdy nie ma JavaScriptu
];

$lokalne = 0;

foreach ($oczekiwaneNaLiscie as $tekst) {
    if (!str_contains($listaHtml, $tekst)) {
        echo '[BLAD] flows.twig - brak na liscie: ' . $tekst . PHP_EOL;
        $lokalne++;
    }
}

$bledy += $lokalne;

printf('%-20s %s' . PHP_EOL, 'flows.twig', $lokalne === 0 ? '[OK] ' : '[BLAD]');

// Kolejka pusta - przycisk nie ma czego pobierac i musi to powiedziec.
$daneListy['stan'] = ['wszystkie' => 3, 'pozostalo' => 0, 'gotowe' => 3, 'procent' => 100];
$pustaHtml = $twig->render('flows.twig', $daneListy);

file_put_contents($wyjscie . '/lista-kolejka-pusta.html', $pustaHtml);

$lokalne = 0;

foreach (['Metadane pobrane', 'disabled', 'width:100%'] as $tekst) {
    if (!str_contains($pustaHtml, $tekst)) {
        echo '[BLAD] flows.twig (kolejka pusta) - brak: ' . $tekst . PHP_EOL;
        $lokalne++;
    }
}

$bledy += $lokalne;

printf('%-20s %s' . PHP_EOL, 'flows.twig/pusta', $lokalne === 0 ? '[OK] ' : '[BLAD]');

// ── Trasy ────────────────────────────────────────────────────────
// Rejestracja tras nie dotyka bazy ani API, wiec da sie ja sprawdzic tutaj.
// Rzecz, o ktora naprawde chodzi: czy /flows/metadane/partia nie wpada
// przypadkiem w /flows/{id}/metadane - obie sa POST i obie maja trzy segmenty.
require_once __DIR__ . '/../app/src/Http/Routes.php';

$slim = \Slim\Factory\AppFactory::create();
\Flownatic\Http\Routes::register($slim);

$kolektor = $slim->getRouteCollector();
$lokalne  = 0;

/** @var array<string,string> $rozstrzygniecia adres i metoda => oczekiwany wzorzec */
$rozstrzygniecia = [
    'GET /flows'                    => '/flows',
    'GET /flows/7'                  => '/flows/{id}',
    'GET /flows/metadane/stan'      => '/flows/metadane/stan',
    'POST /flows/metadane'          => '/flows/metadane',
    'POST /flows/metadane/partia'   => '/flows/metadane/partia',
    'POST /flows/7/metadane'        => '/flows/{id}/metadane',
    'POST /flows/7/testy'           => '/flows/{id}/testy',
    'POST /flows/7/testy/wklej'     => '/flows/{id}/testy/wklej',
    'GET /flows/7/eksport'          => '/flows/{id}/eksport',
    'GET /flows/7/druk'             => '/flows/{id}/druk',
    'POST /flows/7/testy/dodaj'     => '/flows/{id}/testy/dodaj',
    'POST /flows/7/testy/12/zapisz' => '/flows/{id}/testy/{tc}/zapisz',
    'POST /flows/7/testy/12/status' => '/flows/{id}/testy/{tc}/status',
    'POST /flows/7/testy/12/usun'   => '/flows/{id}/testy/{tc}/usun',
    'POST /flows/sync'              => '/flows/sync',
];

foreach ($rozstrzygniecia as $zadanie => $wzorzec) {
    [$metoda, $sciezka] = explode(' ', $zadanie, 2);

    try {
        $wynik = $slim->getRouteResolver()->computeRoutingResults($sciezka, $metoda);
        $trafiony = $kolektor->lookupRoute((string) $wynik->getRouteIdentifier())->getPattern();
    } catch (\Throwable $e) {
        echo '[BLAD] trasa ' . $zadanie . ' - ' . $e->getMessage() . PHP_EOL;
        $lokalne++;
        continue;
    }

    if ($trafiony !== $wzorzec) {
        echo '[BLAD] trasa ' . $zadanie . ' trafia w ' . $trafiony
            . ', oczekiwano ' . $wzorzec . PHP_EOL;
        $lokalne++;
    }
}

$bledy += $lokalne;

printf('%-20s %s' . PHP_EOL, 'trasy', $lokalne === 0 ? '[OK] ' : '[BLAD]');

echo PHP_EOL . ($bledy === 0
    ? 'Wszystko sie zgadza. Podglad HTML: tests/out/' . PHP_EOL
    : $bledy . ' problemow.' . PHP_EOL);

exit($bledy === 0 ? 0 : 1);
