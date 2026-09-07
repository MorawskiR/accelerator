<?php

declare(strict_types=1);

namespace Flownatic\Http;

use Flownatic\Flow\FlowAnalyzer;
use Flownatic\Flow\FlowImporter;
use Flownatic\Flow\MetadataFetcher;
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

        // Widok jednego Flow: struktura z DigestBuilder i ryzyka z RiskScanner.
        $app->get('/flows/{id}', function (Request $request, Response $response, array $args) use ($app): Response {
            $id   = (int) ($args['id'] ?? 0);
            $uid  = (int) $_SESSION['user_id'];
            $flow = self::flowUzytkownika($id, $uid);

            if ($flow === null) {
                return self::zKomunikatem($response, $app, 'Nie ma takiego Flow.', '/flows');
            }

            $blad = self::pobierzKomunikat();

            try {
                $analiza = (new FlowAnalyzer())->analiza($id);
            } catch (\Throwable $e) {
                // Uszkodzone metadane nie moga konczyc sie strona bledu 500 -
                // tester ma zobaczyc, ze da sie je pobrac ponownie.
                $analiza = null;
                $blad ??= 'Nie moge przeliczyc struktury: ' . $e->getMessage();
            }

            $testy   = [];
            $zrodla  = [];
            $wersjaId = (int) ($analiza['wersja']['id'] ?? 0);

            if ($wersjaId > 0) {
                $repo   = new TestCaseRepository();
                $testy  = $repo->dla($wersjaId);
                $zrodla = $repo->podsumowanie($wersjaId);
            }

            return Twig::fromRequest($request)->render($response, 'flow.twig', [
                'flow'         => $flow,
                'wersja'       => $analiza['wersja'] ?? null,
                'digest'       => $analiza['digest'] ?? null,
                'ryzyka'       => $analiza['ryzyka'] ?? [],
                'podsumowanie' => $analiza['podsumowanie'] ?? [],
                'testy'        => $testy,
                'zrodla'       => $zrodla,
                'polaczona'    => (new OAuthService())->connection($uid) !== null,
                'blad'         => $blad,
                'ok'           => self::pobierzKomunikatOk(),
                'u'            => [
                    'flows'    => self::url($app, '/flows'),
                    'metadane' => self::url($app, '/flows/' . $id . '/metadane'),
                    'testy'    => self::url($app, '/flows/' . $id . '/testy'),
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
