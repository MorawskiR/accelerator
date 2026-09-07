<?php

declare(strict_types=1);

/**
 * Sprawdzenie generatora przypadkow testowych. Bez bazy, bez org, bez API.
 *
 * Uruchomienie:  php tests/generator.php
 *
 * Pilnuje trzech rzeczy, ktore latwo zepsuc niepostrzezenie:
 *
 * 1. **Kazde odwolanie do frameworku istnieje.** Kod TC-0xx albo RT-0xx wpisany
 *    z pamieci wyglada poprawnie i prowadzi donikad. Tak wlasnie powstal blad
 *    w Fazie 3, gdzie "Get Records bez filtrow" odsylalo do TC-020, czyli do
 *    profilu uzytkownika standardowego.
 * 2. **Liczba przypadkow miesci sie w 15-30** dla realnego Flow - tyle obiecuje
 *    kryterium fazy.
 * 3. **Kryterium "Gotowe, gdy" Fazy 4:** dla Record-Triggered Flow sa przypadki
 *    na trigger, na kazda galaz Decision, na bulk 200 i na brak fault path.
 *
 * Wynik ladunkiem tekstowym trafia do tests/out/ - mozna przeczytac dokladnie
 * to, co zobaczy tester.
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

// Klasy z TEGO katalogu roboczego, nie z tego, na ktory wskazuje autoloader.
require_once __DIR__ . '/../app/src/Flow/DigestBuilder.php';
require_once __DIR__ . '/../app/src/Flow/RiskScanner.php';
require_once __DIR__ . '/../app/src/Generator/Framework.php';
require_once __DIR__ . '/../app/src/Generator/TestCaseSource.php';
require_once __DIR__ . '/../app/src/Generator/TemplateGenerator.php';

use Flownatic\Flow\DigestBuilder;
use Flownatic\Flow\RiskScanner;
use Flownatic\Generator\Framework;
use Flownatic\Generator\TemplateGenerator;

$wyjscie = __DIR__ . '/out';

if (!is_dir($wyjscie)) {
    mkdir($wyjscie, 0777, true);
}

/**
 * @return array{tc:list<array<string,mixed>>, digest:array<string,mixed>, ryzyka:list<array<string,mixed>>}
 */
function przypadkiDla(string $plik): array
{
    $meta = json_decode((string) file_get_contents($plik), true);

    if (!is_array($meta)) {
        throw new RuntimeException('Nieczytelny fixture: ' . $plik);
    }

    $digest = (new DigestBuilder())->build($meta);
    $ryzyka = (new RiskScanner())->scan($digest);

    return [
        'tc'     => (new TemplateGenerator())->generuj($digest, $ryzyka),
        'digest' => $digest,
        'ryzyka' => $ryzyka,
    ];
}

/** @param list<array<string,mixed>> $tc */
function zapiszPodglad(string $sciezka, array $tc): void
{
    $linie = [];

    foreach ($tc as $p) {
        $linie[] = str_repeat('=', 70);
        $linie[] = $p['tc_code'] . '  [' . $p['checklist_ref'] . ' — '
            . (Framework::tytul((string) $p['checklist_ref']) ?? '?') . ']';
        $linie[] = 'Priorytet: ' . $p['priority'] . '  |  Kategoria: ' . $p['category'];
        $linie[] = '';
        $linie[] = $p['title'];

        if (($p['preconditions'] ?? null) !== null) {
            $linie[] = '';
            $linie[] = 'Warunki wstepne: ' . $p['preconditions'];
        }

        $linie[] = '';
        $linie[] = (string) $p['steps'];
        $linie[] = '';
        $linie[] = 'Oczekiwany wynik: ' . $p['expected'];
        $linie[] = '';
    }

    file_put_contents($sciezka, implode(PHP_EOL, $linie));
}

$bledy = 0;

// ── Przypadek glowny: realne metadane celowo zepsutego Flow ──────
$wynik = przypadkiDla(__DIR__ . '/fixtures/bad-example.json');
$tc     = $wynik['tc'];
$digest = $wynik['digest'];

zapiszPodglad($wyjscie . '/tc-bad-example.txt', $tc);

$lokalne = 0;

// 1. Kazdy checklist_ref istnieje w frameworku.
foreach ($tc as $p) {
    if (!Framework::znany((string) $p['checklist_ref'])) {
        echo '[BLAD] ' . $p['tc_code'] . ' odsyla do nieistniejacego kodu: '
            . $p['checklist_ref'] . PHP_EOL;
        $lokalne++;
    }
}

// 2. Liczba przypadkow w obiecanym przedziale.
if (count($tc) < 15 || count($tc) > 30) {
    echo '[BLAD] wygenerowano ' . count($tc) . ' przypadkow, oczekiwano 15-30' . PHP_EOL;
    $lokalne++;
}

// 3. Kryterium "Gotowe, gdy" Fazy 4.
$tekst = json_encode($tc, JSON_UNESCAPED_UNICODE);
$tekst = is_string($tekst) ? $tekst : '';

/**
 * Sprawdzamy po kodach frameworku, a nie po dopasowaniu tekstu.
 *
 * Pierwsza wersja tego testu szukala frazy "Uruchomienie przy utworzeniu
 * rekordu" i wywalala sie na realnym Flow, ktory wyzwala sie wylacznie na
 * Update - blad byl w tescie, nie w generatorze. Odwolanie do checklisty
 * jest wlasciwa jednostka: nie zalezy od brzmienia szablonu.
 *
 * @param list<array<string,mixed>> $tc
 * @param list<string>              $kody
 */
function maOdwolanieDo(array $tc, array $kody): bool
{
    foreach ($tc as $p) {
        if (in_array((string) $p['checklist_ref'], $kody, true)) {
            return true;
        }
    }

    return false;
}

$wymagane = [
    'wyzwalacz'            => ['RT-001', 'RT-003', 'TC-001'],
    'brak fault path'      => ['TC-015'],
    'test masowy / limity' => ['RT-005', 'TC-018'],
];

foreach ($wymagane as $co => $kodyRef) {
    if (!maOdwolanieDo($tc, $kodyRef)) {
        echo '[BLAD] brak przypadku na ' . $co . ' (kody: ' . implode(', ', $kodyRef) . ')' . PHP_EOL;
        $lokalne++;
    }
}

// Test masowy musi mowic o skali, inaczej nie jest testem masowym.
$masowy = array_values(array_filter(
    $tc,
    static fn (array $p): bool => in_array((string) $p['checklist_ref'], ['RT-005', 'TC-018'], true)
));

$maSkale = false;

foreach ($masowy as $p) {
    if (str_contains((string) $p['steps'] . (string) $p['expected'], '200')) {
        $maSkale = true;
        break;
    }
}

if (!$maSkale) {
    echo '[BLAD] test masowy nie wspomina o 200 rekordach' . PHP_EOL;
    $lokalne++;
}

// Kazda galaz kazdej decyzji ma swoj przypadek.
foreach ((array) ($digest['decyzje'] ?? []) as $decyzja) {
    foreach ((array) ($decyzja['galezie'] ?? []) as $galaz) {
        $nazwa = (string) ($galaz['etykieta'] ?? $galaz['nazwa'] ?? '');

        if ($nazwa !== '' && !str_contains($tekst, $nazwa)) {
            echo '[BLAD] brak przypadku na galaz decyzji: ' . $nazwa . PHP_EOL;
            $lokalne++;
        }
    }
}

// Kody sa unikalne i ciagle.
$kody = array_column($tc, 'tc_code');

if (count($kody) !== count(array_unique($kody))) {
    echo '[BLAD] kody TC nie sa unikalne' . PHP_EOL;
    $lokalne++;
}

if (($kody[0] ?? '') !== 'RT-001') {
    echo '[BLAD] pierwszy kod to ' . ($kody[0] ?? 'brak') . ', oczekiwano RT-001' . PHP_EOL;
    $lokalne++;
}

$bledy += $lokalne;
printf("%-22s %s  przypadkow: %d\n", 'bad-example.json',
    $lokalne === 0 ? '[OK] ' : '[BLAD]', count($tc));

// ── Flow bez ryzyk: nadal ma sens go testowac ────────────────────
foreach (['po-petli.json', 'czysty.json', 'bez-filtrow.json'] as $plik) {
    $w = przypadkiDla(__DIR__ . '/fixtures/' . $plik);
    zapiszPodglad($wyjscie . '/tc-' . basename($plik, '.json') . '.txt', $w['tc']);

    $lokalne = 0;

    if ($w['tc'] === []) {
        echo '[BLAD] ' . $plik . ' - zero przypadkow, a Flow istnieje' . PHP_EOL;
        $lokalne++;
    }

    foreach ($w['tc'] as $p) {
        if (!Framework::znany((string) $p['checklist_ref'])) {
            echo '[BLAD] ' . $plik . ' - nieistniejacy kod: ' . $p['checklist_ref'] . PHP_EOL;
            $lokalne++;
        }

        foreach (['tc_code', 'title', 'steps', 'expected', 'priority'] as $pole) {
            if (($p[$pole] ?? '') === '') {
                echo '[BLAD] ' . $plik . ' - puste pole ' . $pole . ' w ' . $p['tc_code'] . PHP_EOL;
                $lokalne++;
            }
        }
    }

    $bledy += $lokalne;
    printf("%-22s %s  przypadkow: %d\n", $plik,
        $lokalne === 0 ? '[OK] ' : '[BLAD]', count($w['tc']));
}

// ── Spojnosc RiskScanner z frameworkiem ──────────────────────────
// Regula odsylajaca do nieistniejacego kodu to blad, ktory widac dopiero
// w eksporcie z Fazy 5 - czyli najpozniej, jak sie da.
$lokalne = 0;

foreach (['bad-example.json', 'bez-filtrow.json'] as $plik) {
    $w = przypadkiDla(__DIR__ . '/fixtures/' . $plik);

    foreach ($w['ryzyka'] as $r) {
        $kod = (string) ($r['checklist'] ?? '');

        if (!Framework::znany($kod)) {
            echo '[BLAD] RiskScanner: regula ' . (string) $r['regula']
                . ' odsyla do nieistniejacego kodu ' . $kod . PHP_EOL;
            $lokalne++;
        }
    }
}

$bledy += $lokalne;
printf("%-22s %s\n", 'RiskScanner/kody', $lokalne === 0 ? '[OK] ' : '[BLAD]');

// ── Nieaktualnosc przypadkow ─────────────────────────────────────
// Gdy Flow zmieni sie w org, digest przelicza sie sam, a przypadki zostaja
// i opisuja poprzednia wersje. Porownanie znacznikow jest wydzielone wlasnie
// po to, zeby dalo sie je sprawdzic bez bazy.
require_once __DIR__ . '/../app/src/Generator/TestCaseRepository.php';

use Flownatic\Generator\TestCaseRepository;

$lokalne = 0;

/** @var list<array{wygenerowano:?string, przeliczono:?string, oczekiwane:bool, opis:string}> $sytuacje */
$sytuacje = [
    ['wygenerowano' => '2026-09-07 12:00:00', 'przeliczono' => '2026-09-07 12:05:00',
     'oczekiwane' => true,  'opis' => 'digest przeliczony PO wygenerowaniu'],
    ['wygenerowano' => '2026-09-07 12:05:00', 'przeliczono' => '2026-09-07 12:00:00',
     'oczekiwane' => false, 'opis' => 'przypadki nowsze niz digest'],
    ['wygenerowano' => '2026-09-07 12:00:00', 'przeliczono' => '2026-09-07 12:00:00',
     'oczekiwane' => false, 'opis' => 'ta sama sekunda liczy sie jako aktualne'],
    ['wygenerowano' => null, 'przeliczono' => '2026-09-07 12:00:00',
     'oczekiwane' => false, 'opis' => 'brak przypadkow - nie ma czego uniewazniac'],
    ['wygenerowano' => '2026-09-07 12:00:00', 'przeliczono' => null,
     'oczekiwane' => false, 'opis' => 'brak digestu - nie ma z czym porownac'],
];

foreach ($sytuacje as $s) {
    $wynikPorownania = TestCaseRepository::czyPrzeterminowane($s['wygenerowano'], $s['przeliczono']);

    if ($wynikPorownania !== $s['oczekiwane']) {
        echo '[BLAD] nieaktualnosc: ' . $s['opis'] . ' - dostalem '
            . var_export($wynikPorownania, true) . PHP_EOL;
        $lokalne++;
    }
}

$bledy += $lokalne;
printf("%-22s %s\n", 'nieaktualnosc', $lokalne === 0 ? '[OK] ' : '[BLAD]');

echo PHP_EOL . ($bledy === 0
    ? 'Wszystko sie zgadza. Podglad: tests/out/tc-*.txt' . PHP_EOL
    : $bledy . ' problemow.' . PHP_EOL);

exit($bledy === 0 ? 0 : 1);
