<?php
/**
 * Flownatic - spike OAuth do Salesforce (przygotowanie Fazy 2).
 *
 * Samodzielny skrypt: BEZ Composera i vendor/, wiec dziala mimo braku Laragona.
 * Sprawdza end-to-end:
 *   1. OAuth 2.0 Web Server Flow z PKCE (S256)
 *   2. wymiane kodu na tokeny (w tym refresh_token)
 *   3. describe na FlowDefinitionView - realne nazwy pol, zeby nie zgadywac w Fazie 2
 *   4. zliczenie Flow w org
 *
 * Sekrety NIE leza w tym pliku - konfiguracja jest poza katalogiem publicznym.
 * Skrypt jest tymczasowy: skasowac zaraz po odczytaniu wyniku.
 */
declare(strict_types=1);
session_start();
header('Content-Type: text/html; charset=utf-8');

const API_VERSION = 'v67.0';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function b64url(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function render(string $title, string $html, bool $isError = false): void {
    $c = $isError ? '#b23c0a' : '#0b6fc4';
    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Flownatic - ' . h($title) . '</title><style>'
       . 'body{font:16px/1.6 system-ui,sans-serif;max-width:820px;margin:40px auto;padding:0 20px;color:#101828;background:#f7f9fc}'
       . 'h1{font-size:1.4rem;color:' . $c . ';margin:0 0 6px}'
       . 'h2{font-size:1.05rem;margin:26px 0 8px}'
       . 'code,pre{font-family:ui-monospace,monospace;font-size:.85em;background:#eef3fa;border:1px solid #dde5f0;border-radius:5px}'
       . 'code{padding:.1em .35em}pre{padding:12px;overflow-x:auto}'
       . 'table{border-collapse:collapse;width:100%;font-size:.9rem;background:#fff;border:1px solid #dde5f0;border-radius:6px}'
       . 'th,td{text-align:left;padding:8px 11px;border-bottom:1px solid #e9eff7}'
       . 'th{background:#eef3fa;font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;white-space:nowrap}'
       . 'tr:last-child td{border-bottom:none}'
       . '.ok{color:#0b7d6e;font-weight:600}.bad{color:#b23c0a;font-weight:600}'
       . '.hint{font-size:.9rem;color:#5a6782}'
       . 'a.btn{display:inline-block;background:#0b6fc4;color:#fff;text-decoration:none;padding:11px 20px;border-radius:7px;font-weight:600}'
       . '</style></head><body><h1>' . h($title) . '</h1>' . $html . '</body></html>';
}

/** Konfiguracja: najpierw uklad lokalny, potem serwerowy (ten sam schemat co index.php Fazy 1). */
function loadConfig(): array {
    $candidates = [
        __DIR__ . '/../app/sf-oauth.php',
        dirname(__DIR__, 4) . '/flownatic-app/sf-oauth.php',
    ];
    foreach ($candidates as $p) {
        if (is_file($p)) { return require $p; }
    }
    render('Brak konfiguracji',
        '<p>Nie znalazlem pliku konfiguracyjnego. Szukalem w:</p><ul><li>'
        . implode('</li><li>', array_map('h', $candidates)) . '</li></ul>', true);
    exit;
}

/** Zwraca [kod HTTP, zdekodowane cialo]. */
function http_call(string $method, string $url, array $opts = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $opts['headers'] ?? [],
    ]);
    if (isset($opts['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) { return [0, ['error' => 'curl', 'error_description' => $err]]; }
    return [$code, json_decode((string) $body, true)];
}

$cfg = loadConfig();
foreach (['login_url', 'client_id', 'redirect_uri'] as $k) {
    if (empty($cfg[$k])) {
        render('Blad konfiguracji', '<p>Brakuje klucza <code>' . h($k) . '</code>.</p>', true);
        exit;
    }
}
$loginUrl = rtrim((string) $cfg['login_url'], '/');

// ── KROK 1: start przeplywu ──────────────────────────────────────
if (!isset($_GET['code']) && !isset($_GET['error'])) {
    $verifier  = b64url(random_bytes(64));
    $challenge = b64url(hash('sha256', $verifier, true));
    $state     = bin2hex(random_bytes(16));
    $_SESSION['pkce_verifier'] = $verifier;
    $_SESSION['oauth_state']   = $state;

    $url = $loginUrl . '/services/oauth2/authorize?' . http_build_query([
        'response_type'         => 'code',
        'client_id'             => $cfg['client_id'],
        'redirect_uri'          => $cfg['redirect_uri'],
        'scope'                 => 'api refresh_token offline_access',
        'state'                 => $state,
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    ]);

    render('Spike OAuth - Salesforce',
        '<p>Kliknij, zeby uruchomic <strong>OAuth 2.0 Web Server Flow z PKCE</strong>. Zalogujesz sie do org, '
      . 'zatwierdzisz dostep i wrocisz tutaj.</p>'
      . '<p><a class="btn" href="' . h($url) . '">Polacz z Salesforce</a></p>'
      . '<h2>Parametry zadania</h2><table>'
      . '<tr><th>login_url</th><td><code>' . h($loginUrl) . '</code></td></tr>'
      . '<tr><th>redirect_uri</th><td><code>' . h((string) $cfg['redirect_uri']) . '</code></td></tr>'
      . '<tr><th>scope</th><td><code>api refresh_token offline_access</code></td></tr>'
      . '<tr><th>PKCE</th><td><code>S256</code></td></tr>'
      . '</table>'
      . '<p class="hint">redirect_uri musi zgadzac sie <em>co do znaku</em> z Callback URL '
      . 'w External Client App. Roznica w koncowym ukosniku wystarczy, zeby przeplyw sie wywalil.</p>');
    exit;
}

// ── Salesforce odrzucil zadanie ──────────────────────────────────
if (isset($_GET['error'])) {
    render('Salesforce odrzucil zadanie',
        '<table><tr><th>error</th><td><code>' . h((string) $_GET['error']) . '</code></td></tr>'
      . '<tr><th>opis</th><td>' . h((string) ($_GET['error_description'] ?? '-')) . '</td></tr></table>', true);
    exit;
}

// ── KROK 2: callback - wymiana kodu na tokeny ────────────────────
if (($_GET['state'] ?? '') !== ($_SESSION['oauth_state'] ?? '_brak_')) {
    render('Niezgodny state',
        '<p>Parametr <code>state</code> nie zgadza sie z zapisanym w sesji - to zabezpieczenie przed CSRF. '
      . 'Zacznij od nowa.</p>', true);
    exit;
}

[$code, $tok] = http_call('POST', $loginUrl . '/services/oauth2/token', [
    'headers' => ['Content-Type: application/x-www-form-urlencoded'],
    'form'    => array_filter([
        'grant_type'    => 'authorization_code',
        'code'          => (string) $_GET['code'],
        'client_id'     => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'] ?? null,
        'redirect_uri'  => $cfg['redirect_uri'],
        'code_verifier' => $_SESSION['pkce_verifier'] ?? '',
    ]),
]);

if ($code !== 200 || empty($tok['access_token'])) {
    render('Wymiana kodu na token nie powiodla sie',
        '<table><tr><th>HTTP</th><td>' . $code . '</td></tr>'
      . '<tr><th>error</th><td><code>' . h((string) ($tok['error'] ?? '-')) . '</code></td></tr>'
      . '<tr><th>opis</th><td>' . h((string) ($tok['error_description'] ?? '-')) . '</td></tr></table>'
      . '<h2>Najczestsze przyczyny</h2><ul>'
      . '<li>redirect_uri rozni sie od Callback URL w External Client App</li>'
      . '<li>brak client_secret, a aplikacja jest klientem poufnym</li>'
      . '<li>aplikacja jeszcze sie nie rozpropagowala - Salesforce potrafi potrzebowac do 30 minut</li>'
      . '<li>PKCE wymagany w ECA, ale code_verifier nie dotarl (wygasla sesja PHP)</li>'
      . '</ul>', true);
    exit;
}

unset($_SESSION['pkce_verifier'], $_SESSION['oauth_state']);
$access   = (string) $tok['access_token'];
$instance = rtrim((string) ($tok['instance_url'] ?? $loginUrl), '/');
$auth     = ['Authorization: Bearer ' . $access];

// ── KROK 3: describe na FlowDefinitionView ───────────────────────
[$dc, $desc] = http_call('GET',
    $instance . '/services/data/' . API_VERSION . '/sobjects/FlowDefinitionView/describe',
    ['headers' => $auth]);

$fields = [];
if ($dc === 200 && !empty($desc['fields'])) {
    foreach ($desc['fields'] as $f) {
        $fields[] = str_pad((string) $f['name'], 34) . (string) $f['type'];
    }
}

// ── KROK 4: ile Flow jest w org ──────────────────────────────────
[$qc, $q] = http_call('GET',
    $instance . '/services/data/' . API_VERSION . '/query?'
    . http_build_query(['q' => 'SELECT COUNT() FROM FlowDefinitionView']),
    ['headers' => $auth]);
$flowCount = ($qc === 200 && isset($q['totalSize'])) ? (string) $q['totalSize'] : 'nie udalo sie policzyc';

$hasRefresh = !empty($tok['refresh_token']);

$html =
    '<p class="ok">OAuth przeszedl. Ponizej wynik czterech krokow.</p>'
  . '<h2>Tokeny</h2><table>'
  . '<tr><th>access_token</th><td>otrzymany, ' . strlen($access) . ' znakow <em>(celowo nie wypisuje)</em></td></tr>'
  . '<tr><th>refresh_token</th><td>' . ($hasRefresh
        ? '<span class="ok">jest</span> - automatyczne odnawianie sesji w Fazie 2 zadziala'
        : '<span class="bad">BRAK</span> - sprawdz scope offline_access i polityke refresh w ECA') . '</td></tr>'
  . '<tr><th>instance_url</th><td><code>' . h($instance) . '</code></td></tr>'
  . '<tr><th>token_type</th><td>' . h((string) ($tok['token_type'] ?? '-')) . '</td></tr>'
  . '<tr><th>scope</th><td><code>' . h((string) ($tok['scope'] ?? '-')) . '</code></td></tr>'
  . '</table>'
  . '<h2>Flow w org</h2>'
  . '<p><strong>' . h($flowCount) . '</strong> rekordow w <code>FlowDefinitionView</code>.'
  . ' Porownaj z Setup &rarr; Process Automation &rarr; Flows - to kryterium "Gotowe, gdy" Fazy 2.</p>'
  . '<h2>Pola FlowDefinitionView (' . count($fields) . ')</h2>'
  . '<p class="hint">To wlasciwy powod, dla ktorego warto bylo uruchomic ten spike przed Faza 2: '
  . 'dokumentacja tego obiektu bywa niekompletna, a tutaj widac realne nazwy i typy pol. '
  . 'Na tej liscie oprzemy zapytania SOQL zamiast zgadywac.</p>'
  . ($fields
        ? '<pre>' . h(implode(PHP_EOL, $fields)) . '</pre>'
        : '<p class="bad">describe nie zwrocil pol (HTTP ' . $dc . ').</p>')
  . '<h2>Posprzataj</h2>'
  . '<p><strong>Skasuj ten skrypt z serwera</strong> zaraz po odczytaniu wyniku - trzyma sesje OAuth '
  . 'i jest publicznie dostepny.</p>';

// Zapis wyniku do pliku poza katalogiem publicznym.
// Powod: liczba pol FlowDefinitionView jest spora, a przepisywanie jej
// z ekranu (czesto telefonu, bo firmowa siec blokuje domene) byloby zmudne
// i podatne na bledy. Plik kasujemy razem ze spikiem.
$wynik = [
    'czas'          => date('c'),
    'instance_url'  => $instance,
    'ma_refresh'    => $hasRefresh,
    'scope'         => $tok['scope'] ?? null,
    'token_type'    => $tok['token_type'] ?? null,
    'flow_count'    => $flowCount,
    'describe_http' => $dc,
    'pola'          => $fields,
];

@file_put_contents(
    dirname(__DIR__, 4) . '/flownatic-app/spike-wynik.json',
    json_encode($wynik, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

$html .= '<p class="hint">Wynik zapisany takze do pliku na serwerze, poza katalogiem publicznym.</p>';

render('Spike OAuth - wynik', $html);
