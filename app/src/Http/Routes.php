<?php

declare(strict_types=1);

namespace Flownatic\Http;

use Flownatic\Export\XlsxExporter;
use Flownatic\Flow\FlowAnalyzer;
use Flownatic\Flow\FlowImporter;
use Flownatic\Flow\MetadataFetcher;
use Flownatic\Generator\ClipboardImporter;
use Flownatic\Generator\PromptBuilder;
use Flownatic\Generator\TemplateGenerator;
use Flownatic\Generator\TestCaseRepository;
use Flownatic\Salesforce\OAuthService;
use Flownatic\Support\Config;
use Flownatic\Support\Db;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Views\Twig;

/**
 * Trasy aplikacji.
 *
 * Faza 1 to logowanie i pusty dashboard. Trasy Salesforce dojda w Fazie 2,
 * a widoki Flow w Fazie 3 - dlatego dashboard jawnie pokazuje, czego jeszcze nie ma,
 * zamiast udawac gotowa aplikacje.
 */
final class Routes
{
    public static function register(App $app): void
    {
        $auth = new AuthMiddleware(
            $app->getResponseFactory(),
            self::url($app, '/login')
        );

        // ── Diagnostyka ────────────────────────────────────────────
        // Celowo publiczna i celowo uboga: potwierdza, ze PHP, autoloader
        // i baza dzialaja, nie zdradzajac niczego wiecej. Sluzy do
        // sprawdzenia deployu bez logowania sie.
        $app->get('/health', function (Request $request, Response $response): Response {
            $baza = 'nie';

            try {
                Db::conn()->query('SELECT 1');
                $baza = 'tak';
            } catch (\Throwable) {
                $baza = 'nie';
            }

            $response->getBody()->write((string) json_encode([
                'app'   => 'flownatic',
                'php'   => PHP_VERSION,
                'baza'  => $baza,
                'czas'  => date('c'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        });

        // ── Logowanie ──────────────────────────────────────────────
        $app->get('/login', function (Request $request, Response $response) use ($app): Response {
            if (isset($_SESSION['user_id'])) {
                return $response->withHeader('Location', self::url($app, '/'))->withStatus(302);
            }

            return Twig::fromRequest($request)->render($response, 'login.twig', [
                'csrf'  => self::csrfToken(),
                'blad'  => self::pobierzKomunikat(),
                'akcja' => self::url($app, '/login'),
            ]);
        });

        $app->post('/login', function (Request $request, Response $response) use ($app): Response {
            $dane  = (array) $request->getParsedBody();
            $email = trim((string) ($dane['email'] ?? ''));
            $haslo = (string) ($dane['haslo'] ?? '');

            if (!self::csrfPoprawny((string) ($dane['csrf'] ?? ''))) {
                return self::zKomunikatem($response, $app, 'Sesja wygasla. Sprobuj jeszcze raz.');
            }

            $user = Db::one('SELECT id, password_hash FROM users WHERE email = ?', [$email]);

            // Ten sam komunikat dla zlego loginu i zlego hasla - inaczej
            // formularz podpowiadalby, ktore adresy istnieja w bazie.
            if ($user === null || !password_verify($haslo, (string) $user['password_hash'])) {
                return self::zKomunikatem($response, $app, 'Nieprawidlowy adres e-mail lub haslo.');
            }

            // Nowy identyfikator sesji po zalogowaniu - zabezpieczenie
            // przed przejeciem sesji ustawionej wczesniej przez atakujacego.
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['id'];
            unset($_SESSION['csrf']);

            Db::query('UPDATE users SET last_login_at = NOW() WHERE id = ?', [(int) $user['id']]);

            $cel = (string) ($_SESSION['po_zalogowaniu'] ?? '');
            unset($_SESSION['po_zalogowaniu']);

            $docelowy = ($cel !== '' && $cel !== '/login') ? $cel : self::url($app, '/');

            return $response->withHeader('Location', $docelowy)->withStatus(302);
        });

        $app->post('/logout', function (Request $request, Response $response) use ($app): Response {
            $_SESSION = [];
            session_destroy();

            return $response->withHeader('Location', self::url($app, '/login'))->withStatus(302);
        });

        // ── Salesforce ─────────────────────────────────────────────
        $app->get('/org/connect', function (Request $request, Response $response) use ($app): Response {
            try {
                $url = (new OAuthService())->authorizeUrl();
            } catch (\Throwable $e) {
                return self::zKomunikatem($response, $app, 'Nie moge zbudowac adresu logowania: ' . $e->getMessage(), '/flows');
            }

            return $response->withHeader('Location', $url)->withStatus(302);
        })->add($auth);

        $app->get('/oauth/callback', function (Request $request, Response $response) use ($app): Response {
            $q = $request->getQueryParams();

            // Salesforce zwraca blad w adresie, gdy uzytkownik odmowi dostepu.
            if (isset($q['error'])) {
                return self::zKomunikatem($response, $app,
                    'Salesforce odrzucil polaczenie: ' . (string) ($q['error_description'] ?? $q['error']), '/flows');
            }

            try {
                (new OAuthService())->handleCallback(
                    (string) ($q['code'] ?? ''),
                    (string) ($q['state'] ?? ''),
                    (int) $_SESSION['user_id']
                );
            } catch (\Throwable $e) {
                return self::zKomunikatem($response, $app, $e->getMessage(), '/flows');
            }

            $_SESSION['komunikat_ok'] = 'Org podlaczona. Kliknij "Pobierz Flow", zeby zaciagnac inwentarz.';

            return $response->withHeader('Location', self::url($app, '/flows'))->withStatus(302);
        })->add($auth);

        $app->post('/org/disconnect', function (Request $request, Response $response) use ($app): Response {
            (new OAuthService())->disconnect((int) $_SESSION['user_id']);
            $_SESSION['komunikat_ok'] = 'Org rozlaczona. Tokeny usuniete z bazy.';

            return $response->withHeader('Location', self::url($app, '/flows'))->withStatus(302);
        })->add($auth);

        $app->post('/flows/sync', function (Request $request, Response $response) use ($app): Response {
            $svc = new OAuthService();
            $uid = (int) $_SESSION['user_id'];
            $pol = $svc->connection($uid);

            if ($pol === null) {
                return self::zKomunikatem($response, $app, 'Najpierw podlacz org.', '/flows');
            }

            try {
                $stat = (new FlowImporter($svc->apiClient($uid)))->import((int) $pol['id']);
            } catch (\Throwable $e) {
                // Rozlaczona org, wygasly refresh token, blad SOQL - uzytkownik
                // ma zobaczyc komunikat, a nie strone bledu 500.
                return self::zKomunikatem($response, $app, $e->getMessage(), '/flows');
            }

            $_SESSION['komunikat_ok'] = sprintf(
                'Pobrano %d Flow: %d nowych, %d zaktualizowanych%s.',
                $stat['pobrane'], $stat['dodane'], $stat['zaktualizowane'],
                $stat['zniklo'] > 0 ? sprintf(', %d nieobecnych juz w org', $stat['zniklo']) : ''
            );

            return $response->withHeader('Location', self::url($app, '/flows'))->withStatus(302);
        })->add($auth);

        $app->get('/flows', function (Request $request, Response $response) use ($app): Response {
            $svc = new OAuthService();
            $uid = (int) $_SESSION['user_id'];
            $pol = $svc->connection($uid);

            $q    = $request->getQueryParams();
            $typ  = trim((string) ($q['typ'] ?? ''));
            $stan = trim((string) ($q['stan'] ?? ''));

            $flows  = [];
            $typy   = [];
            $ryzyka = [];
            $stan   = ['wszystkie' => 0, 'pozostalo' => 0, 'gotowe' => 0, 'procent' => 0];

            if ($pol !== null) {
                $warunki = ['connection_id = ?'];
                $param   = [(int) $pol['id']];

                if ($typ !== '')  { $warunki[] = 'process_type = ?'; $param[] = $typ; }
                if ($stan === 'aktywne')   { $warunki[] = 'is_active = 1'; }
                if ($stan === 'nieaktywne'){ $warunki[] = 'is_active = 0'; }

                $flows = Db::all(
                    'SELECT * FROM flows WHERE ' . implode(' AND ', $warunki) . ' ORDER BY label',
                    $param
                );

                $typy = array_column(Db::all(
                    'SELECT DISTINCT process_type FROM flows WHERE connection_id = ? AND process_type IS NOT NULL ORDER BY process_type',
                    [(int) $pol['id']]
                ), 'process_type');

                // Liczniki ryzyk przy kazdym Flow - bez tego lista nie mowi,
                // ktory Flow warto otworzyc jako pierwszy.
                $ryzyka = (new FlowAnalyzer())->podsumowania((int) $pol['id']);
                $stan   = MetadataFetcher::stanKolejki((int) $pol['id']);
            }

            return Twig::fromRequest($request)->render($response, 'flows.twig', [
                'polaczona'   => $pol !== null,
                'instancja'   => $pol['instance_url'] ?? null,
                'flows'       => $flows,
                'typy'        => $typy,
                'ryzyka'      => $ryzyka,
                'stan'        => $stan,
                'wybranyTyp'  => $typ,
                'wybranyStan' => $stan,
                'blad'        => self::pobierzKomunikat(),
                'ok'          => self::pobierzKomunikatOk(),
                'u'           => [
                    'connect'    => self::url($app, '/org/connect'),
                    'disconnect' => self::url($app, '/org/disconnect'),
                    'sync'       => self::url($app, '/flows/sync'),
                    'flows'      => self::url($app, '/flows'),
                    'metadane'   => self::url($app, '/flows/metadane'),
                    'partia'     => self::url($app, '/flows/metadane/partia'),
                    'stan'       => self::url($app, '/flows/metadane/stan'),
                    'wyloguj'    => self::url($app, '/logout'),
                ],
            ]);
        })->add($auth);

        // ── Metadane partiami ──────────────────────────────────────
        // Import metadanych to N+1 wywolan API, a max_execution_time wynosi
        // 180 s. Jedno zadanie bierze wiec ~5 Flow, a przegladarka wola je
        // w petli. Stan kolejki siedzi w bazie, wiec zamkniecie karty w
        // polowie niczego nie psuje - kolejne wejscie podejmuje od miejsca,
        // w ktorym import stanal.

        $app->get('/flows/metadane/stan', function (Request $request, Response $response) use ($app): Response {
            $pol = (new OAuthService())->connection((int) $_SESSION['user_id']);

            if ($pol === null) {
                return self::json($response, ['blad' => 'Org nie jest podlaczona.'], 409);
            }

            return self::json($response, MetadataFetcher::stanKolejki((int) $pol['id']));
        })->add($auth);

        $app->post('/flows/metadane/partia', function (Request $request, Response $response) use ($app): Response {
            $svc = new OAuthService();
            $uid = (int) $_SESSION['user_id'];
            $pol = $svc->connection($uid);

            if ($pol === null) {
                return self::json($response, ['blad' => 'Org nie jest podlaczona.'], 409);
            }

            try {
                $wynik = (new MetadataFetcher($svc->apiClient($uid)))->pobierzPartie((int) $pol['id']);
            } catch (\Throwable $e) {
                // Przerwana partia nie cofa wczesniejszych - to, co juz zapisane,
                // zostaje w bazie i nie bedzie pobierane drugi raz.
                return self::json($response, ['blad' => $e->getMessage()], 500);
            }

            return self::json($response, $wynik + MetadataFetcher::stanKolejki((int) $pol['id']));
        })->add($auth);

        // Ten sam import bez JavaScriptu: jedno klikniecie to jedna partia.
        // Wolniej, ale dziala i jest tak samo wznawialne.
        $app->post('/flows/metadane', function (Request $request, Response $response) use ($app): Response {
            $svc = new OAuthService();
            $uid = (int) $_SESSION['user_id'];
            $pol = $svc->connection($uid);

            if ($pol === null) {
                return self::zKomunikatem($response, $app, 'Najpierw podlacz org.', '/flows');
            }

            try {
                $wynik = (new MetadataFetcher($svc->apiClient($uid)))->pobierzPartie((int) $pol['id']);
            } catch (\Throwable $e) {
                return self::zKomunikatem($response, $app, $e->getMessage(), '/flows');
            }

            $_SESSION['komunikat_ok'] = sprintf(
                'Partia gotowa: %d pobranych, %d bez zmian. Pozostalo %d.',
                $wynik['pobrane'], $wynik['bez_zmian'], $wynik['pozostalo']
            );

            if ($wynik['bledy'] !== []) {
                $_SESSION['komunikat'] = 'Pominiete: ' . implode(' · ', $wynik['bledy']);
            }

            return $response->withHeader('Location', self::url($app, '/flows'))->withStatus(302);
        })->add($auth);

        // Pobranie metadanych jednego Flow. Osobno od partii, bo tester
        // otwiera konkretny Flow i nie ma powodu czekac na cala kolejke.
        $app->post('/flows/{id}/metadane', function (Request $request, Response $response, array $args) use ($app): Response {
            $id  = (int) ($args['id'] ?? 0);
            $uid = (int) $_SESSION['user_id'];

            if (self::flowUzytkownika($id, $uid) === null) {
                return self::zKomunikatem($response, $app, 'Nie ma takiego Flow.', '/flows');
            }

            $svc = new OAuthService();
            $pol = $svc->connection($uid);

            if ($pol === null) {
                return self::zKomunikatem($response, $app, 'Najpierw podlacz org.', '/flows/' . $id);
            }

            try {
                $wynik = (new MetadataFetcher($svc->apiClient($uid)))->pobierzJeden($id);
            } catch (\Throwable $e) {
                return self::zKomunikatem($response, $app, $e->getMessage(), '/flows/' . $id);
            }

            if ($wynik['stan'] === 'blad') {
                return self::zKomunikatem($response, $app, (string) $wynik['blad'], '/flows/' . $id);
            }

            $_SESSION['komunikat_ok'] = $wynik['stan'] === 'bez_zmian'
                ? 'Metadane bez zmian - analiza jest aktualna.'
                : 'Metadane pobrane. Struktura i ryzyka przeliczone.';

            return $response->withHeader('Location', self::url($app, '/flows/' . $id))->withStatus(302);
        })->add($auth);

        // Generowanie przypadkow testowych. Bez wywolan platnego API -
        // TemplateGenerator instancjonuje checkliste frameworku danymi z digestu.
        $app->post('/flows/{id}/testy', function (Request $request, Response $response, array $args) use ($app): Response {
            $id  = (int) ($args['id'] ?? 0);
            $uid = (int) $_SESSION['user_id'];

            if (self::flowUzytkownika($id, $uid) === null) {
                return self::zKomunikatem($response, $app, 'Nie ma takiego Flow.', '/flows');
            }

            try {
                $analiza = (new FlowAnalyzer())->analiza($id);
            } catch (\Throwable $e) {
                return self::zKomunikatem($response, $app, $e->getMessage(), '/flows/' . $id);
            }

            if ($analiza === null) {
                return self::zKomunikatem($response, $app,
                    'Najpierw pobierz metadane tego Flow - bez nich nie ma z czego generowac.', '/flows/' . $id);
            }

            $przypadki = (new TemplateGenerator())->generuj($analiza['digest'], $analiza['ryzyka']);

            try {
                $ile = (new TestCaseRepository())->zapisz(
                    (int) $analiza['wersja']['id'],
                    $przypadki,
                    TestCaseRepository::ZRODLO_REGULY
                );
            } catch (\Throwable $e) {
                return self::zKomunikatem($response, $app, 'Nie moge zapisac przypadkow: ' . $e->getMessage(), '/flows/' . $id);
            }

            $_SESSION['komunikat_ok'] = sprintf(
                'Wygenerowano %d przypadkow testowych. Dopiski wlasne pozostaly nietkniete.',
                $ile
            );

            return $response->withHeader('Location', self::url($app, '/flows/' . $id))->withStatus(302);
        })->add($auth);

        // Druga polowa mostu przez schowek: wynik wklejony z Claude.ai wraca tutaj.
        $app->post('/flows/{id}/testy/wklej', function (Request $request, Response $response, array $args) use ($app): Response {
            $id  = (int) ($args['id'] ?? 0);
            $uid = (int) $_SESSION['user_id'];

            if (self::flowUzytkownika($id, $uid) === null) {
                return self::zKomunikatem($response, $app, 'Nie ma takiego Flow.', '/flows');
            }

            $dane     = (array) $request->getParsedBody();
            $wklejone = (string) ($dane['wynik'] ?? '');

            try {
                $analiza = (new FlowAnalyzer())->analiza($id);
            } catch (\Throwable $e) {
                return self::zKomunikatem($response, $app, $e->getMessage(), '/flows/' . $id);
            }

            if ($analiza === null) {
                return self::zKomunikatem($response, $app,
                    'Najpierw pobierz metadane tego Flow.', '/flows/' . $id);
            }

            try {
                $przypadki = (new ClipboardImporter($wklejone))->generuj($analiza['digest'], $analiza['ryzyka']);

                $ile = (new TestCaseRepository())->zapisz(
                    (int) $analiza['wersja']['id'],
                    $przypadki,
                    TestCaseRepository::ZRODLO_WKLEJONE
                );
            } catch (\Throwable $e) {
                // Zly format to najczestszy przypadek uzycia tej sciezki -
                // ma konczyc sie zdaniem po polsku, a nie strona bledu.
                return self::zKomunikatem($response, $app, $e->getMessage(), '/flows/' . $id);
            }

            $_SESSION['komunikat_ok'] = sprintf('Wczytano %d przypadkow z wklejonego wyniku.', $ile);

            return $response->withHeader('Location', self::url($app, '/flows/' . $id))->withStatus(302);
        })->add($auth);

        // ── Edycja przypadkow przed eksportem ──────────────────────
        // Wygenerowane testy musza przejsc przez czlowieka - inaczej narzedzie
        // nie jest wiarygodne jako konsultanckie. Stad akceptacja, poprawka
        // i mozliwosc dopisania wlasnego przypadku.

        $app->post('/flows/{id}/testy/dodaj', function (Request $request, Response $response, array $args) use ($app): Response {
            $id  = (int) ($args['id'] ?? 0);
            $uid = (int) $_SESSION['user_id'];

            if (self::flowUzytkownika($id, $uid) === null) {
                return self::zKomunikatem($response, $app, 'Nie ma takiego Flow.', '/flows');
            }

            $wersjaId = self::wersjaFlow($id);

            if ($wersjaId === null) {
                return self::zKomunikatem($response, $app, 'Najpierw pobierz metadane tego Flow.', '/flows/' . $id);
            }

            $dane = (array) $request->getParsedBody();

            foreach (['title', 'steps', 'expected'] as $wymagane) {
                if (trim((string) ($dane[$wymagane] ?? '')) === '') {
                    return self::zKomunikatem($response, $app,
                        'Wlasny przypadek musi miec tytul, kroki i oczekiwany wynik.', '/flows/' . $id);
                }
            }

            try {
                (new TestCaseRepository())->dodajReczny($wersjaId, $dane, self::prefiksFlow($id));
            } catch (\Throwable $e) {
                return self::zKomunikatem($response, $app, $e->getMessage(), '/flows/' . $id);
            }

            $_SESSION['komunikat_ok'] = 'Dopisano wlasny przypadek testowy.';

            return $response->withHeader('Location', self::url($app, '/flows/' . $id))->withStatus(302);
        })->add($auth);

        $app->post('/flows/{id}/testy/{tc}/zapisz', function (Request $request, Response $response, array $args) use ($app): Response {
            $id  = (int) ($args['id'] ?? 0);
            $tc  = (int) ($args['tc'] ?? 0);
            $uid = (int) $_SESSION['user_id'];

            $repo = self::przypadekUzytkownika($id, $tc, $uid);

            if ($repo === null) {
                return self::zKomunikatem($response, $app, 'Nie ma takiego przypadku.', '/flows/' . $id);
            }

            $dane = (array) $request->getParsedBody();

            foreach (['title', 'steps', 'expected'] as $wymagane) {
                if (trim((string) ($dane[$wymagane] ?? '')) === '') {
                    return self::zKomunikatem($response, $app,
                        'Tytul, kroki i oczekiwany wynik nie moga byc puste.', '/flows/' . $id . '?edytuj=' . $tc);
                }
            }

            (new TestCaseRepository())->zapiszJeden($tc, $dane);
            $_SESSION['komunikat_ok'] = 'Zapisano zmiany w przypadku.';

            return $response->withHeader('Location', self::url($app, '/flows/' . $id))->withStatus(302);
        })->add($auth);

        $app->post('/flows/{id}/testy/{tc}/status', function (Request $request, Response $response, array $args) use ($app): Response {
            $id  = (int) ($args['id'] ?? 0);
            $tc  = (int) ($args['tc'] ?? 0);
            $uid = (int) $_SESSION['user_id'];

            if (self::przypadekUzytkownika($id, $tc, $uid) === null) {
                return self::zKomunikatem($response, $app, 'Nie ma takiego przypadku.', '/flows/' . $id);
            }

            $dane   = (array) $request->getParsedBody();
            $status = (string) ($dane['status'] ?? '');

            try {
                (new TestCaseRepository())->zmienStatus($tc, $status);
            } catch (\Throwable $e) {
                return self::zKomunikatem($response, $app, $e->getMessage(), '/flows/' . $id);
            }

            $_SESSION['komunikat_ok'] = $status === TestCaseRepository::STATUS_ODRZUCONY
                ? 'Przypadek odrzucony - nie trafi do eksportu.'
                : 'Przypadek zaakceptowany.';

            return $response->withHeader('Location', self::url($app, '/flows/' . $id))->withStatus(302);
        })->add($auth);

        $app->post('/flows/{id}/testy/{tc}/usun', function (Request $request, Response $response, array $args) use ($app): Response {
            $id  = (int) ($args['id'] ?? 0);
            $tc  = (int) ($args['tc'] ?? 0);
            $uid = (int) $_SESSION['user_id'];

            $przypadek = self::przypadekUzytkownika($id, $tc, $uid);

            if ($przypadek === null) {
                return self::zKomunikatem($response, $app, 'Nie ma takiego przypadku.', '/flows/' . $id);
            }

            (new TestCaseRepository())->usun($tc);

            // Wygenerowany przypadek wroci przy nastepnym generowaniu - warto
            // to powiedziec od razu, zeby usuniecie nie wygladalo na nieskuteczne.
            $_SESSION['komunikat_ok'] = (string) ($przypadek['source'] ?? '') === TestCaseRepository::ZRODLO_RECZNE
                ? 'Wlasny przypadek usuniety.'
                : 'Przypadek usuniety. Uwaga: wroci przy ponownym generowaniu - jesli ma zniknac na stale, odrzuc go zamiast kasowac.';

            return $response->withHeader('Location', self::url($app, '/flows/' . $id))->withStatus(302);
        })->add($auth);

        // Eksport do .xlsx w ukladzie frameworku - domkniecie petli narzedzia.
        $app->get('/flows/{id}/eksport', function (Request $request, Response $response, array $args) use ($app): Response {
            $id   = (int) ($args['id'] ?? 0);
            $uid  = (int) $_SESSION['user_id'];
            $flow = self::flowUzytkownika($id, $uid);

            if ($flow === null) {
                return self::zKomunikatem($response, $app, 'Nie ma takiego Flow.', '/flows');
            }

            try {
                $analiza = (new FlowAnalyzer())->analiza($id);
            } catch (\Throwable) {
                $analiza = null;
            }

            $przypadki = [];
            $wersjaId  = (int) ($analiza['wersja']['id'] ?? 0);

            if ($wersjaId > 0) {
                // Odrzucone nie trafiaja do pliku oddawanego klientowi.
                $przypadki = (new TestCaseRepository())->doEksportu($wersjaId);
            }

            $pol = (new OAuthService())->connection($uid);

            // Arkusz "Flow Inventory" opisuje cala org, nie tylko ten jeden Flow.
            $inwentarz = $pol === null
                ? [$flow]
                : Db::all('SELECT * FROM flows WHERE connection_id = ? ORDER BY label', [(int) $pol['id']]);

            $eksporter = new XlsxExporter();

            try {
                $skoroszyt = $eksporter->zbuduj(
                    $flow,
                    $analiza['digest'] ?? null,
                    $przypadki,
                    $inwentarz,
                    isset($pol['instance_url']) ? (string) $pol['instance_url'] : null
                );

                $sciezka = $eksporter->doPliku($skoroszyt);
            } catch (\Throwable $e) {
                return self::zKomunikatem($response, $app, 'Nie moge zbudowac pliku: ' . $e->getMessage(), '/flows/' . $id);
            }

            // Plik ma kilkadziesiat kilobajtow, wiec czytamy go w calosci
            // i kasujemy od razu - nie zostawiamy smieci w katalogu tymczasowym.
            $tresc = (string) file_get_contents($sciezka);
            @unlink($sciezka);

            $response->getBody()->write($tresc);

            return $response
                ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $eksporter->nazwaPliku($flow) . '"')
                ->withHeader('Content-Length', (string) strlen($tresc))
                ->withHeader('Cache-Control', 'no-store');
        })->add($auth);

        // Widok jednego Flow: struktura z DigestBuilder i ryzyka z RiskScanner.
        $app->get('/flows/{id}', function (Request $request, Response $response, array $args) use ($app): Response {
            $id   = (int) ($args['id'] ?? 0);
            $uid  = (int) $_SESSION['user_id'];
            $flow = self::flowUzytkownika($id, $uid);

            if ($flow === null) {
                return self::zKomunikatem($response, $app, 'Nie ma takiego Flow.', '/flows');
            }

            $blad    = self::pobierzKomunikat();
            $edytuj  = (int) ($request->getQueryParams()['edytuj'] ?? 0);

            try {
                $analiza = (new FlowAnalyzer())->analiza($id);
            } catch (\Throwable $e) {
                // Uszkodzone metadane nie moga konczyc sie strona bledu 500 -
                // tester ma zobaczyc, ze da sie je pobrac ponownie.
                $analiza = null;
                $blad ??= 'Nie moge przeliczyc struktury: ' . $e->getMessage();
            }

            $testy    = [];
            $zrodla   = [];
            $stanTest = ['nieaktualne' => false, 'wygenerowano' => null];
            $wersjaId = (int) ($analiza['wersja']['id'] ?? 0);

            if ($wersjaId > 0) {
                $repo     = new TestCaseRepository();
                $testy    = $repo->dla($wersjaId);
                $zrodla   = $repo->podsumowanie($wersjaId);
                $stanTest = $repo->stanAktualnosci(
                    $wersjaId,
                    isset($analiza['wersja']['digested_at']) ? (string) $analiza['wersja']['digested_at'] : null
                );
            }

            // Prompt skladamy przy kazdym wyswietleniu - to czysty string,
            // tanszy niz trzymanie go w bazie i pilnowanie aktualnosci.
            $prompt = $analiza === null
                ? null
                : (new PromptBuilder())->zbuduj($analiza['digest'], $analiza['ryzyka']);

            return Twig::fromRequest($request)->render($response, 'flow.twig', [
                'flow'         => $flow,
                'wersja'       => $analiza['wersja'] ?? null,
                'digest'       => $analiza['digest'] ?? null,
                'ryzyka'       => $analiza['ryzyka'] ?? [],
                'podsumowanie' => $analiza['podsumowanie'] ?? [],
                'testy'        => $testy,
                'zrodla'       => $zrodla,
                'stanTestow'   => $stanTest,
                'edytuj'       => $edytuj,
                'statusy'      => TestCaseRepository::STATUSY,
                'prompt'       => $prompt,
                'polaczona'    => (new OAuthService())->connection($uid) !== null,
                'blad'         => $blad,
                'ok'           => self::pobierzKomunikatOk(),
                'u'            => [
                    'flows'    => self::url($app, '/flows'),
                    'metadane' => self::url($app, '/flows/' . $id . '/metadane'),
                    'testy'    => self::url($app, '/flows/' . $id . '/testy'),
                    'eksport'  => self::url($app, '/flows/' . $id . '/eksport'),
                    'dodaj'    => self::url($app, '/flows/' . $id . '/testy/dodaj'),
                    'przypadek' => self::url($app, '/flows/' . $id . '/testy'),
                    'wklej'    => self::url($app, '/flows/' . $id . '/testy/wklej'),
                    'connect'  => self::url($app, '/org/connect'),
                    'wyloguj'  => self::url($app, '/logout'),
                ],
            ]);
        })->add($auth);

        // ── Dashboard ──────────────────────────────────────────────
        $app->get('/', function (Request $request, Response $response) use ($app): Response {
            $user = Db::one('SELECT email, last_login_at FROM users WHERE id = ?', [$_SESSION['user_id']]);

            return Twig::fromRequest($request)->render($response, 'dashboard.twig', [
                'email'      => $user['email'] ?? '?',
                'wylogujUrl' => self::url($app, '/logout'),
                'flowsUrl'   => self::url($app, '/flows'),
                'srodowisko' => Config::get('APP_ENV', '?'),
            ]);
        })->add($auth);
    }

    /** Sciezka z uwzglednieniem podkatalogu, w ktorym stoi aplikacja. */
    private static function url(App $app, string $sciezka): string
    {
        $base = rtrim($app->getBasePath(), '/');

        return $base . $sciezka;
    }

    /**
     * Identyfikator najnowszej wersji Flow z pobranymi metadanymi.
     *
     * Ten sam wybor, co w FlowAnalyzer - ale bez liczenia digestu, bo przy
     * edycji przypadku struktura nie jest do niczego potrzebna.
     */
    private static function wersjaFlow(int $flowId): ?int
    {
        $w = Db::one(
            'SELECT id FROM flow_versions
              WHERE flow_id = ? AND metadata_json IS NOT NULL
              ORDER BY version_number DESC
              LIMIT 1',
            [$flowId]
        );

        return $w === null ? null : (int) $w['id'];
    }

    /**
     * Przypadek testowy wraz z pelnym sprawdzeniem wlascicielstwa.
     *
     * Sprawdzamy oba ogniwa: czy Flow nalezy do uzytkownika i czy przypadek
     * nalezy do wersji tego Flow. Same id przychodza z adresu URL.
     *
     * @return array<string,mixed>|null
     */
    private static function przypadekUzytkownika(int $flowId, int $tcId, int $userId): ?array
    {
        if (self::flowUzytkownika($flowId, $userId) === null) {
            return null;
        }

        $wersjaId = self::wersjaFlow($flowId);

        if ($wersjaId === null) {
            return null;
        }

        return (new TestCaseRepository())->jeden($tcId, $wersjaId);
    }

    /** Prefiks kodow przypadkow dla tego Flow - wg typu, jak w arkuszu. */
    private static function prefiksFlow(int $flowId): string
    {
        try {
            $analiza = (new FlowAnalyzer())->analiza($flowId);
        } catch (\Throwable) {
            return 'TC';
        }

        return $analiza === null ? 'TC' : \Flownatic\Generator\Framework::typFlow($analiza['digest']);
    }

    /**
     * Odpowiedz JSON dla wywolan z przegladarki.
     *
     * @param array<string,mixed> $dane
     */
    private static function json(Response $response, array $dane, int $status = 200): Response
    {
        $response->getBody()->write(
            (string) json_encode($dane, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }

    /**
     * Flow razem ze sprawdzeniem, czy nalezy do org tego uzytkownika.
     *
     * POC ma jedno konto i jedna org, ale identyfikator Flow wchodzi tu
     * z adresu URL - bez tego zlaczenia wystarczyloby podmienic numer,
     * zeby zobaczyc cudze dane, gdy kont bedzie wiecej.
     *
     * @return array<string,mixed>|null
     */
    private static function flowUzytkownika(int $flowId, int $userId): ?array
    {
        if ($flowId <= 0) {
            return null;
        }

        return Db::one(
            'SELECT f.* FROM flows f
             JOIN sf_connections c ON c.id = f.connection_id
             WHERE f.id = ? AND c.user_id = ?',
            [$flowId, $userId]
        );
    }

    private static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf'];
    }

    private static function csrfPoprawny(string $podany): bool
    {
        $oczekiwany = (string) ($_SESSION['csrf'] ?? '');

        return $oczekiwany !== '' && hash_equals($oczekiwany, $podany);
    }

    private static function zKomunikatem(Response $response, App $app, string $tresc, string $cel = '/login'): Response
    {
        $_SESSION['komunikat'] = $tresc;

        return $response->withHeader('Location', self::url($app, $cel))->withStatus(302);
    }

    private static function pobierzKomunikatOk(): ?string
    {
        $tresc = $_SESSION['komunikat_ok'] ?? null;
        unset($_SESSION['komunikat_ok']);

        return $tresc === null ? null : (string) $tresc;
    }

    private static function pobierzKomunikat(): ?string
    {
        $tresc = $_SESSION['komunikat'] ?? null;
        unset($_SESSION['komunikat']);

        return $tresc === null ? null : (string) $tresc;
    }
}
