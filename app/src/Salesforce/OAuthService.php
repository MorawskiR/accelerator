<?php

declare(strict_types=1);

namespace Flownatic\Salesforce;

use Flownatic\Support\Config;
use Flownatic\Support\Crypto;
use Flownatic\Support\Db;
use RuntimeException;

/**
 * OAuth 2.0 Web Server Flow z PKCE.
 *
 * Przeplyw potwierdzony spikiem 2026-08-31 na playgroundzie - to nie jest
 * kod pisany w ciemno.
 *
 * Tokeny trafiaja do bazy WYLACZNIE zaszyfrowane (AES-256-GCM, Support\Crypto).
 * Zmiana APP_KEY uniewaznia je - trzeba wtedy polaczyc org ponownie.
 *
 * Swiadomie NIE zapisujemy czasu wygasniecia: Salesforce nie zwraca expires_in
 * dla tego przeplywu, a dlugosc sesji jest ustawieniem org. Zamiast zgadywac,
 * ApiClient reaguje na 401 oraz INVALID_SESSION_ID i odswieza token w locie.
 */
final class OAuthService
{
    public function __construct(
        private readonly HttpTransport $transport = new CurlTransport(),
    ) {
    }

    /**
     * Buduje adres autoryzacji i zapisuje w sesji to, co bedzie potrzebne
     * przy powrocie: weryfikator PKCE i state chroniacy przed CSRF.
     */
    public function authorizeUrl(): string
    {
        $verifier  = self::b64url(random_bytes(64));
        $challenge = self::b64url(hash('sha256', $verifier, true));
        $state     = bin2hex(random_bytes(16));

        $_SESSION['sf_pkce_verifier'] = $verifier;
        $_SESSION['sf_oauth_state']   = $state;

        return $this->loginUrl() . '/services/oauth2/authorize?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => Config::must('SF_CLIENT_ID'),
            'redirect_uri'          => Config::must('SF_REDIRECT_URI'),
            'scope'                 => 'api refresh_token offline_access',
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /** Wymienia kod na tokeny i zapisuje polaczenie. Zwraca id polaczenia. */
    public function handleCallback(string $code, string $state, int $userId): int
    {
        $oczekiwany = (string) ($_SESSION['sf_oauth_state'] ?? '');

        if ($oczekiwany === '' || !hash_equals($oczekiwany, $state)) {
            throw new RuntimeException('Niezgodny parametr state - zacznij laczenie od nowa.');
        }

        $verifier = (string) ($_SESSION['sf_pkce_verifier'] ?? '');
        unset($_SESSION['sf_oauth_state'], $_SESSION['sf_pkce_verifier']);

        $tok = $this->zadanieTokenowe([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => Config::must('SF_CLIENT_ID'),
            'client_secret' => Config::get('SF_CLIENT_SECRET', ''),
            'redirect_uri'  => Config::must('SF_REDIRECT_URI'),
            'code_verifier' => $verifier,
        ]);

        if (empty($tok['refresh_token'])) {
            // Bez refresh tokenu sesja umrze i uzytkownik bedzie sie logowal
            // w kolko. Lepiej powiedziec to teraz niz przy imporcie.
            throw new RuntimeException(
                'Salesforce nie zwrocil refresh_token. Sprawdz, czy External Client App ma scope '
                . 'Perform requests at any time (refresh_token, offline_access).'
            );
        }

        return $this->zapiszPolaczenie($userId, $tok);
    }

    /**
     * Odswieza access token. Zwraca nowy token albo null, jesli sie nie da -
     * ApiClient rozpoznaje null jako sygnal "polacz org ponownie".
     */
    public function refresh(int $userId): ?string
    {
        $pol = $this->connection($userId);

        if ($pol === null || empty($pol['refresh_token_enc'])) {
            return null;
        }

        try {
            $refresh = Crypto::decrypt((string) $pol['refresh_token_enc']);

            $tok = $this->zadanieTokenowe([
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh,
                'client_id'     => Config::must('SF_CLIENT_ID'),
                'client_secret' => Config::get('SF_CLIENT_SECRET', ''),
            ]);
        } catch (RuntimeException) {
            // Refresh token bywa odwolany: zmiana hasla, cofniecie dostepu.
            // To nie jest blad aplikacji - trzeba polaczyc org ponownie.
            return null;
        }

        if (empty($tok['access_token'])) {
            return null;
        }

        Db::query(
            'UPDATE sf_connections SET access_token_enc = ?, issued_at = NOW(), updated_at = NOW() WHERE id = ?',
            [Crypto::encrypt((string) $tok['access_token']), (int) $pol['id']]
        );

        return (string) $tok['access_token'];
    }

    /** @return array<string,mixed>|null */
    public function connection(int $userId): ?array
    {
        return Db::one(
            'SELECT * FROM sf_connections WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$userId]
        );
    }

    public function isConnected(int $userId): bool
    {
        return $this->connection($userId) !== null;
    }

    public function disconnect(int $userId): void
    {
        Db::query('DELETE FROM sf_connections WHERE user_id = ?', [$userId]);
    }

    /** Gotowy klient API dla zalogowanego uzytkownika, z podpietym odswiezaniem. */
    public function apiClient(int $userId): ApiClient
    {
        $pol = $this->connection($userId);

        if ($pol === null) {
            throw new RuntimeException('Org nie jest podlaczona.');
        }

        return new ApiClient(
            (string) $pol['instance_url'],
            Crypto::decrypt((string) $pol['access_token_enc']),
            fn (): ?string => $this->refresh($userId),
            $this->transport,
            Config::get('SF_API_VERSION', 'v67.0'),
        );
    }

    /**
     * @param array<string,string> $pola
     * @return array<string,mixed>
     */
    private function zadanieTokenowe(array $pola): array
    {
        $odp = $this->transport->send(
            'POST',
            $this->loginUrl() . '/services/oauth2/token',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query(array_filter($pola, static fn ($v): bool => $v !== '' && $v !== null))
        );

        $dane = json_decode($odp['body'], true);

        if (!is_array($dane)) {
            throw new RuntimeException('Salesforce zwrocil odpowiedz, ktorej nie da sie odczytac.');
        }

        if ($odp['status'] !== 200 || isset($dane['error'])) {
            throw new RuntimeException(sprintf(
                'Salesforce odrzucil zadanie tokenowe (HTTP %d): %s - %s',
                $odp['status'],
                (string) ($dane['error'] ?? '?'),
                (string) ($dane['error_description'] ?? 'brak opisu')
            ));
        }

        return $dane;
    }

    /** @param array<string,mixed> $tok */
    private function zapiszPolaczenie(int $userId, array $tok): int
    {
        // Jedna org na uzytkownika (POC) - stare polaczenie zastepujemy.
        $this->disconnect($userId);

        Db::query(
            'INSERT INTO sf_connections
                (user_id, org_id, instance_url, access_token_enc, refresh_token_enc, issued_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $userId,
                self::orgIdZIdentity((string) ($tok['id'] ?? '')),
                (string) ($tok['instance_url'] ?? $this->loginUrl()),
                Crypto::encrypt((string) $tok['access_token']),
                Crypto::encrypt((string) $tok['refresh_token']),
            ]
        );

        return (int) Db::conn()->lastInsertId();
    }

    /**
     * Salesforce zwraca identity URL w postaci
     * https://login.salesforce.com/id/{orgId}/{userId} - stad wyciagamy org.
     */
    private static function orgIdZIdentity(string $identity): ?string
    {
        if (preg_match('~/id/([0-9A-Za-z]{15,18})/~', $identity, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function loginUrl(): string
    {
        return rtrim(Config::must('SF_LOGIN_URL'), '/');
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
