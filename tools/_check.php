<?php
/**
 * Diagnostyka srodowiska PHP na hostingu - Faza 0, punkt 1.
 * Celowo NIE uzywa phpinfo() (wystawia zbyt duzo informacji publicznie).
 * Plik jest tymczasowy - kasowany zaraz po odczycie.
 */
header('Content-Type: text/plain; charset=utf-8');

echo "=== PHP ===\n";
echo 'wersja: ' . PHP_VERSION . "\n";
echo 'SAPI:   ' . PHP_SAPI . "\n";
echo 'OS:     ' . PHP_OS . "\n\n";

echo "=== ROZSZERZENIA ===\n";
$wymagane = [
    'curl'      => 'Salesforce API + Claude API',
    'openssl'   => 'HTTPS + szyfrowanie tokenow',
    'pdo_mysql' => 'baza danych',
    'mbstring'  => 'polskie znaki',
    'zip'       => 'generowanie .xlsx  <-- KRYTYCZNE',
    'xml'       => 'PhpSpreadsheet',
    'dom'       => 'PhpSpreadsheet',
    'simplexml' => 'PhpSpreadsheet',
    'fileinfo'  => 'PhpSpreadsheet',
    'iconv'     => 'PhpSpreadsheet',
    'gd'        => 'opcjonalne (obrazy w xlsx)',
    'json'      => 'wbudowane',
];
foreach ($wymagane as $ext => $po_co) {
    printf("%-11s %-5s %s\n", $ext, extension_loaded($ext) ? 'OK' : 'BRAK', $po_co);
}

echo "\n=== USTAWIENIA ===\n";
foreach ([
    'memory_limit', 'max_execution_time', 'upload_max_filesize',
    'post_max_size', 'allow_url_fopen', 'date.timezone', 'disable_functions',
] as $k) {
    printf("%-20s %s\n", $k, var_export(ini_get($k), true));
}

echo "\n=== SCIEZKI ===\n";
echo '__DIR__:      ' . __DIR__ . "\n";
echo 'DOCUMENT_ROOT ' . ($_SERVER['DOCUMENT_ROOT'] ?? '?') . "\n";
echo 'katalog dom.: ' . dirname(dirname(dirname(__DIR__))) . "\n";

echo "\n=== NARZEDZIA CLI (czy da sie uruchomic Composer bez SSH) ===\n";
if (function_exists('shell_exec')) {
    $composer = @shell_exec('composer --version 2>&1');
    $php_cli  = @shell_exec('php -v 2>&1');
    echo 'shell_exec:   dostepne' . "\n";
    echo 'composer:     ' . (trim((string) $composer) !== '' ? trim(explode("\n", (string) $composer)[0]) : 'brak odpowiedzi') . "\n";
    echo 'php cli:      ' . (trim((string) $php_cli) !== '' ? trim(explode("\n", (string) $php_cli)[0]) : 'brak odpowiedzi') . "\n";
} else {
    echo "shell_exec:   WYLACZONE (Composer trzeba uruchamiac lokalnie)\n";
}
