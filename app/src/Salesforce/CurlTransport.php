<?php

declare(strict_types=1);

namespace Flownatic\Salesforce;

use RuntimeException;

/** Transport produkcyjny - zwykly cURL. */
final class CurlTransport implements HttpTransport
{
    public function __construct(private readonly int $timeout = 30)
    {
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($url);

        $naglowki = [];

        foreach ($headers as $nazwa => $wartosc) {
            $naglowki[] = $nazwa . ': ' . $wartosc;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $naglowki,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Salesforce potrafi przekierowac miedzy instancjami.
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $odpowiedz = curl_exec($ch);
        $status    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $blad      = curl_error($ch);
        curl_close($ch);

        if ($odpowiedz === false) {
            throw new RuntimeException('Blad polaczenia z Salesforce: ' . $blad);
        }

        return ['status' => $status, 'body' => (string) $odpowiedz];
    }
}
