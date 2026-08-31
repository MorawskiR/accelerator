<?php

declare(strict_types=1);

/**
 * Flownatic - front controller.
 *
 * Jedyny plik PHP dostepny z sieci. Wszystko inne - kod, szablony, .env,
 * vendor/ - lezy poza katalogiem publicznym.
 */

// ── Znalezienie kodu aplikacji ───────────────────────────────────
// Dwa ukazy katalogow, bo DirectAdmin zaklada subdomene wewnatrz public_html
// domeny glownej: lokalnie app/ jest obok, na serwerze lezy w ~/flownatic-app/.
$kandydaci = [
    __DIR__ . '/../app',                    // uklad lokalny (repozytorium)
    dirname(__DIR__, 4) . '/flownatic-app', // uklad serwera, poza domains/
];

$appDir = null;

foreach ($kandydaci as $sciezka) {
    if (is_file($sciezka . '/vendor/autoload.php')) {
        $appDir = $sciezka;
        break;
    }
}

if ($appDir === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit(
        'Nie znalazlem katalogu aplikacji. Szukalem w:' . PHP_EOL
        . '  ' . implode(PHP_EOL . '  ', $kandydaci) . PHP_EOL
        . 'Czy vendor/ zostal wgrany? Patrz deploy.md.' . PHP_EOL
    );
}

require $appDir . '/vendor/autoload.php';

use Flownatic\Http\Routes;
use Flownatic\Support\Config;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

Config::load($appDir . '/.env');

$debug = Config::bool('APP_DEBUG', false);

// ── Sesja ────────────────────────────────────────────────────────
// Ustawienia ciasteczka przed startem sesji, inaczej nie zadzialaja.
$https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_set_cookie_params([
    'httponly' => true,          // niedostepne dla JavaScriptu
    'samesite' => 'Lax',         // ogranicza wysylanie ciasteczka z obcych stron
    'secure'   => $https,        // po HTTPS tylko szyfrowanym polaczeniem
    'path'     => '/',
]);
session_start();

// ── Aplikacja ────────────────────────────────────────────────────
$app = AppFactory::create();

// Aplikacja stoi w podkatalogu (/ftf), wiec Slim musi wiedziec,
// ktora czesc sciezki nie nalezy do trasy. Wyliczamy z SCRIPT_NAME,
// zeby ten sam kod dzialal lokalnie w korzeniu i na produkcji w /ftf.
$sciezkaSkryptu = dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
// DIRECTORY_SEPARATOR zamiast literalu: na Windowsie dirname() zwraca separator systemowy.
$base = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $sciezkaSkryptu), '/');

if ($base !== '' && $base !== '.') {
    $app->setBasePath($base);
}

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

$twig = Twig::create($appDir . '/templates', [
    'cache' => $debug ? false : $appDir . '/storage/cache/twig',
    'debug' => $debug,
    // auto_reload MUSI byc wlaczone, mimo ze cache zostaje.
    // Domyslnie Twig wiaze te opcje z 'debug', wiec na produkcji przestawalby
    // sprawdzac date modyfikacji szablonu i serwowal skompilowana stara wersje
    // nawet po wgraniu nowej. Deploy przez FTP nie ma kroku czyszczenia cache,
    // wiec bez tego kazda zmiana szablonu bylaby niewidoczna.
    'auto_reload' => true,
]);
$app->add(TwigMiddleware::create($app, $twig));

Routes::register($app);

// Szczegoly bledow tylko przy APP_DEBUG. Na produkcji uzytkownik widzi
// strone bledu, a przyczyna trafia do logu serwera.
$app->addErrorMiddleware($debug, true, true);

$app->run();
