<?php

declare(strict_types=1);

namespace Flownatic\Salesforce;

/**
 * Warstwa transportu HTTP.
 *
 * Istnieje po to, zeby ApiClient dal sie przetestowac bez prawdziwej org:
 * w testach podstawiamy atrape zwracajaca ustalone odpowiedzi, w produkcji
 * idzie CurlTransport. Bez tego logike ponawiania i odswiezania tokenu
 * dalo by sie sprawdzic dopiero na zywym Salesforce.
 */
interface HttpTransport
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string}
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null): array;
}
