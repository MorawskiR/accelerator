<?php

declare(strict_types=1);

namespace Flownatic\Http;

use Flownatic\Flow\FlowImporter;
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

            $flows = [];
            $typy  = [];

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
            }

            return Twig::fromRequest($request)->render($response, 'flows.twig', [
                'polaczona'   => $pol !== null,
                'instancja'   => $pol['instance_url'] ?? null,
                'flows'       => $flows,
                'typy'        => $typy,
                'wybranyTyp'  => $typ,
                'wybranyStan' => $stan,
                'blad'        => self::pobierzKomunikat(),
                'ok'          => self::pobierzKomunikatOk(),
                'u'           => [
                    'connect'    => self::url($app, '/org/connect'),
                    'disconnect' => self::url($app, '/org/disconnect'),
                    'sync'       => self::url($app, '/flows/sync'),
                    'flows'      => self::url($app, '/flows'),
                    'wyloguj'    => self::url($app, '/logout'),
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
