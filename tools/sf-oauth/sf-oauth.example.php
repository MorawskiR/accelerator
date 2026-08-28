<?php
/**
 * Flownatic - przyklad konfiguracji spike'a OAuth.
 *
 * SKOPIUJ ten plik jako sf-oauth.php i wypelnij. Kopia NIE trafia do repozytorium.
 * Na serwerze ma lezec w ~/flownatic-app/sf-oauth.php - czyli POZA domains/,
 * niedostepnie z przegladarki. Lokalnie: app/sf-oauth.php
 */
return [
    // Adres org (My Domain), bez koncowego ukosnika
    'login_url'     => 'https://resilient-narwhal-j9207g-dev-ed.trailblaze.my.salesforce.com',

    // Z External Client App: Settings -> Consumer Key and Secret
    'client_id'     => 'TUTAJ_CONSUMER_KEY',
    'client_secret' => 'TUTAJ_CONSUMER_SECRET',

    // MUSI zgadzac sie co do znaku z Callback URL w External Client App
    'redirect_uri'  => 'https://dobo.com.pl/ftf/sfoauth.php',
];
