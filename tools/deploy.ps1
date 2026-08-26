<#
    deploy.ps1 - wgrywanie plikow na dobo.com.pl przez FTPS

    Dane logowania czytane sa z pliku POZA repozytorium:
        C:\Users\<user>\.ftp-dobo.txt
    w formacie:
        host=ftp.dobo.com.pl
        user=qekbnopwvk
        pass=...

    Haslo nie jest nigdzie wypisywane. Do curl-a trafia przez tymczasowy
    plik .netrc, ktory jest kasowany po zakonczeniu - dzieki temu nie
    widac go w liscie procesow systemu.

    Uzycie:
        .\tools\deploy.ps1 -Test
            sprawdza polaczenie i wypisuje zawartosc katalogu domowego

        .\tools\deploy.ps1 -LocalFile .\public_html\index.php -RemotePath domains/ftf.dobo.com.pl/public_html/
            wgrywa pojedynczy plik

        .\tools\deploy.ps1 -LocalDir .\public_html -RemotePath domains/ftf.dobo.com.pl/public_html/
            wgrywa caly katalog rekurencyjnie
#>
param(
    [switch]$Test,
    [string]$ListPath,      # listowanie zdalnego katalogu, np. domains/
    [string]$DeleteRemote,  # skasowanie pliku na serwerze, np. domains/x/public_html/plik.php
    [string]$LocalFile,
    [string]$LocalDir,
    [string]$RemotePath,
    [switch]$NoTls          # awaryjnie, gdyby serwer nie obslugiwal FTPS
)

$ErrorActionPreference = 'Stop'
$CredFile = Join-Path $env:USERPROFILE '.ftp-dobo.txt'

# ── wczytanie danych logowania ──────────────────────────────────
if (-not (Test-Path $CredFile)) {
    Write-Host "BLAD: nie znaleziono pliku z danymi FTP:" -ForegroundColor Red
    Write-Host "      $CredFile"
    Write-Host ""
    Write-Host "Utworz go z trzema liniami:"
    Write-Host "      host=ftp.dobo.com.pl"
    Write-Host "      user=qekbnopwvk"
    Write-Host "      pass=twoje_haslo"
    exit 1
}

$cfg = @{}
foreach ($line in Get-Content $CredFile) {
    if ($line -match '^\s*([^=#]+?)\s*=\s*(.+?)\s*$') { $cfg[$Matches[1].ToLower()] = $Matches[2] }
}
foreach ($k in @('host', 'user', 'pass')) {
    if (-not $cfg.ContainsKey($k) -or [string]::IsNullOrWhiteSpace($cfg[$k])) {
        Write-Host "BLAD: brak wartosci '$k' w $CredFile" -ForegroundColor Red
        exit 1
    }
}

# ── tymczasowy .netrc (haslo poza linia polecenia) ──────────────
$netrc = Join-Path $env:TEMP ('.netrc-dobo-' + [guid]::NewGuid().ToString('N'))
"machine $($cfg['host']) login $($cfg['user']) password $($cfg['pass'])" |
    Set-Content -Path $netrc -Encoding ascii -NoNewline

$curlBase = @('--netrc-file', $netrc, '--connect-timeout', '20', '--max-time', '300', '-sS')
if (-not $NoTls) { $curlBase += '--ssl-reqd' }

function Invoke-Curl {
    # UWAGA: nie nazywac tego parametru $Args - to zmienna zastrzezona w PowerShellu
    param([string[]]$CurlArgs)
    $out = & curl.exe @curlBase @CurlArgs 2>&1
    return @{ Ok = ($LASTEXITCODE -eq 0); Output = ($out -join "`n"); Code = $LASTEXITCODE }
}

try {
    # ── TEST POLACZENIA ─────────────────────────────────────────
    if ($Test) {
        Write-Host "Laczenie z $($cfg['host']) jako $($cfg['user'])..." -ForegroundColor Cyan
        $r = Invoke-Curl @("ftp://$($cfg['host'])/")
        if ($r.Ok) {
            Write-Host "POLACZENIE OK - zawartosc katalogu domowego:" -ForegroundColor Green
            Write-Host $r.Output
        }
        else {
            Write-Host "BLAD POLACZENIA (curl exit $($r.Code)):" -ForegroundColor Red
            Write-Host $r.Output
            if (-not $NoTls) {
                Write-Host ""
                Write-Host "Sprobuj ponownie z -NoTls, jesli serwer nie wymusza FTPS." -ForegroundColor Yellow
            }
        }
        return
    }

    # ── LISTOWANIE ZDALNEGO KATALOGU ────────────────────────────
    if ($ListPath) {
        $p = $ListPath.TrimStart('/')
        if (-not $p.EndsWith('/')) { $p += '/' }
        $r = Invoke-Curl @("ftp://$($cfg['host'])/$p")
        if ($r.Ok) {
            Write-Host "== /$p ==" -ForegroundColor Green
            Write-Host $r.Output
        }
        else { Write-Host "BLAD listowania /$p (exit $($r.Code))`n$($r.Output)" -ForegroundColor Red }
        return
    }

    # ── KASOWANIE PLIKU NA SERWERZE ─────────────────────────────
    if ($DeleteRemote) {
        $p = $DeleteRemote.TrimStart('/')
        $dir = ($p -replace '/[^/]+$', '')
        $r = Invoke-Curl @('-Q', "DELE /$p", "ftp://$($cfg['host'])/$dir/")
        if ($r.Ok) { Write-Host "USUNIETO  /$p" -ForegroundColor Green }
        else { Write-Host "BLAD usuwania /$p (exit $($r.Code))`n$($r.Output)" -ForegroundColor Red }
        return
    }

    # ── WGRANIE POJEDYNCZEGO PLIKU ──────────────────────────────
    if ($LocalFile) {
        if (-not $RemotePath) { throw "Podaj -RemotePath" }
        if (-not (Test-Path $LocalFile)) { throw "Nie ma pliku: $LocalFile" }
        $remote = "ftp://$($cfg['host'])/" + $RemotePath.TrimStart('/')
        if (-not $remote.EndsWith('/')) { $remote += '/' }

        $r = Invoke-Curl @('--ftp-create-dirs', '-T', (Resolve-Path $LocalFile).Path, $remote)
        if ($r.Ok) { Write-Host "OK  $LocalFile  ->  $RemotePath" -ForegroundColor Green }
        else { Write-Host "BLAD  $LocalFile  (exit $($r.Code))`n$($r.Output)" -ForegroundColor Red }
        return
    }

    # ── WGRANIE KATALOGU ────────────────────────────────────────
    if ($LocalDir) {
        if (-not $RemotePath) { throw "Podaj -RemotePath" }
        if (-not (Test-Path $LocalDir)) { throw "Nie ma katalogu: $LocalDir" }
        $root = (Resolve-Path $LocalDir).Path
        $files = Get-ChildItem -Path $root -Recurse -File
        Write-Host "Wgrywanie $($files.Count) plikow z $LocalDir..." -ForegroundColor Cyan

        $ok = 0; $fail = 0
        foreach ($f in $files) {
            $rel = $f.FullName.Substring($root.Length).TrimStart('\') -replace '\\', '/'
            $remote = "ftp://$($cfg['host'])/" + $RemotePath.TrimStart('/').TrimEnd('/') + '/' + $rel
            $r = Invoke-Curl @('--ftp-create-dirs', '-T', $f.FullName, $remote)
            if ($r.Ok) { $ok++; Write-Host "  OK   $rel" -ForegroundColor DarkGreen }
            else { $fail++; Write-Host "  BLAD $rel  (exit $($r.Code))" -ForegroundColor Red }
        }
        Write-Host "Zakonczono: $ok OK, $fail bledow." -ForegroundColor Cyan
        return
    }

    Write-Host "Nic nie zrobiono. Uzyj -Test, -LocalFile albo -LocalDir." -ForegroundColor Yellow
}
finally {
    Remove-Item $netrc -Force -ErrorAction SilentlyContinue
}
