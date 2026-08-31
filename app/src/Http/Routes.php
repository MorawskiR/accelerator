<?php

declare(strict_types=1);

namespace Flownatic\Http;

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

        // ── Dashboard ──────────────────────────────────────────────
        $app->get('/', function (Request $request, Response $response) use ($app): Response {
            $user = Db::one('SELECT email, last_login_at FROM users WHERE id = ?', [$_SESSION['user_id']]);

            return Twig::fromRequest($request)->render($response, 'dashboard.twig', [
                'email'      => $user['email'] ?? '?',
                'wylogujUrl' => self::url($app, '/logout'),
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

    private static function zKomunikatem(Response $response, App $app, string $tresc): Response
    {
        $_SESSION['komunikat'] = $tresc;

        return $response->withHeader('Location', self::url($app, '/login'))->withStatus(302);
    }

    private static function pobierzKomunikat(): ?string
    {
        $tresc = $_SESSION['komunikat'] ?? null;
        unset($_SESSION['komunikat']);

        return $tresc === null ? null : (string) $tresc;
    }
}
