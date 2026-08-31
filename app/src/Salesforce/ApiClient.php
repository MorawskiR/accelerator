<?php

declare(strict_types=1);

namespace Flownatic\Salesforce;

use RuntimeException;

/**
 * Klient REST i Tooling API.
 *
 * Trzy rzeczy, ktore musi robic dobrze:
 *
 * 1. Odswiezyc token przy 401 / INVALID_SESSION_ID i powtorzyc zadanie.
 *    Sesja Salesforce wygasa w trakcie pracy, wiec bez tego import Flow
 *    urwalby sie w polowie.
 * 2. Ponowic przy bledach przejsciowych (5xx, limit zadan) z odczekaniem.
 * 3. NIE ponawiac przy bledach trwalych (400, 403, 404) - powtarzanie
 *    zlego zapytania tylko zjada limit API playgrounda.
 */
final class ApiClient
{
    private const MAX_PROB = 3;

    /** @var callable():?string */
    private $odswiez;

    private ?string $token;

    /**
     * @param callable():?string $odswiez funkcja zwracajaca nowy token albo null
     */
    public function __construct(
        private readonly string $instanceUrl,
        ?string $token,
        callable $odswiez,
        private readonly HttpTransport $transport = new CurlTransport(),
        private readonly string $apiVersion = 'v67.0',
        private readonly bool $spijMiedzyProbami = true,
    ) {
        $this->token   = $token;
        $this->odswiez = $odswiez;
    }

    /**
     * @param array<string,string|int> $query
     * @return array<mixed>
     */
    public function get(string $sciezka, array $query = []): array
    {
        $url = rtrim($this->instanceUrl, '/') . $this->rozwin($sciezka);

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $this->zadanie('GET', $url);
    }

    /** Zapytanie SOQL przez REST. @return array<mixed> */
    public function query(string $soql): array
    {
        return $this->get('/services/data/{v}/query', ['q' => $soql]);
    }

    /** Zapytanie SOQL przez Tooling API. @return array<mixed> */
    public function queryTooling(string $soql): array
    {
        return $this->get('/services/data/{v}/tooling/query', ['q' => $soql]);
    }

    /** Opis obiektu - realne nazwy i typy pol. @return array<mixed> */
    public function describe(string $obiekt): array
    {
        return $this->get('/services/data/{v}/sobjects/' . $obiekt . '/describe');
    }

    private function rozwin(string $sciezka): string
    {
        return str_replace('{v}', $this->apiVersion, $sciezka);
    }

    /** @return array<mixed> */
    private function zadanie(string $metoda, string $url): array
    {
        $odswiezono = false;

        for ($proba = 1; $proba <= self::MAX_PROB; $proba++) {
            $odp = $this->transport->send($metoda, $url, [
                'Authorization' => 'Bearer ' . (string) $this->token,
                'Accept'        => 'application/json',
            ]);

            $status = $odp['status'];

            if ($status >= 200 && $status < 300) {
                return $this->zdekoduj($odp['body']);
            }

            // Wygasla sesja - odswiezamy token raz i powtarzamy.
            // Tylko raz: jesli po odswiezeniu nadal 401, problem jest gdzie indziej
            // i kolejne proby niczego nie zmienia.
            if (($status === 401 || $this->toWygaslaSesja($odp['body'])) && !$odswiezono) {
                $nowy = ($this->odswiez)();

                if ($nowy === null) {
                    throw new RuntimeException(
                        'Sesja Salesforce wygasla i nie udalo sie jej odnowic. Polacz org ponownie.'
                    );
                }

                $this->token = $nowy;
                $odswiezono  = true;
                continue;
            }

            // Bledy trwale - nie ma sensu ponawiac.
            if ($status >= 400 && $status < 500 && $status !== 429) {
                throw new RuntimeException($this->opisBledu($status, $odp['body']));
            }

            // Przejsciowe: 5xx i 429. Odczekanie rosnace, zeby nie dobijac serwera.
            if ($proba < self::MAX_PROB) {
                if ($this->spijMiedzyProbami) {
                    sleep($proba);
                }
                continue;
            }

            throw new RuntimeException($this->opisBledu($status, $odp['body']));
        }

        throw new RuntimeException('Nie udalo sie wykonac zadania do Salesforce.');
    }

    private function toWygaslaSesja(string $body): bool
    {
        return str_contains($body, 'INVALID_SESSION_ID');
    }

    /** @return array<mixed> */
    private function zdekoduj(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        $dane = json_decode($body, true);

        if (!is_array($dane)) {
            throw new RuntimeException('Salesforce zwrocil odpowiedz, ktorej nie da sie odczytac jako JSON.');
        }

        return $dane;
    }

    /**
     * Salesforce zwraca bledy jako tablice obiektow z errorCode i message.
     * Wyciagamy je, bo surowy JSON w komunikacie niczego nie tlumaczy.
     */
    private function opisBledu(int $status, string $body): string
    {
        $dane = json_decode($body, true);

        if (is_array($dane)) {
            $czesci = [];

            foreach ($dane as $wpis) {
                if (is_array($wpis) && isset($wpis['message'])) {
                    $czesci[] = ($wpis['errorCode'] ?? '?') . ': ' . $wpis['message'];
                }
            }

            if ($czesci !== []) {
                return 'Salesforce (HTTP ' . $status . ') - ' . implode('; ', $czesci);
            }
        }

        return 'Salesforce zwrocil HTTP ' . $status . ': ' . substr($body, 0, 200);
    }
}
