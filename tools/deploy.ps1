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

        .\tools\deploy.ps1 -LocalFile .\public_html\index.php -RemotePath domains/dobo.com.pl/public_html/ftf/
            wgrywa pojedynczy plik

        .\tools\deploy.ps1 -LocalDir .\public_html -RemotePath domains/dobo.com.pl/public_html/ftf/
            wgrywa caly katalog rekurencyjnie

        .\tools\deploy.ps1 -RemoveDir domains/x/public_html/stare
            pokazuje, co zostaloby usuniete - NIC nie kasuje

        .\tools\deploy.ps1 -RemoveDir domains/x/public_html/stare -Force
            usuwa rekurencyjnie: najpierw pliki (DELE), potem katalogi (RMD)
#>
param(
    [switch]$Test,
    [string]$ListPath,      # listowanie zdalnego katalogu, np. domains/
    [string]$DeleteRemote,  # skasowanie pliku na serwerze, np. domains/x/public_html/plik.php
    [string]$RemoveDir,     # rekurencyjne usuniecie katalogu; bez -Force tylko pokazuje plan
    [switch]$Force,         # potwierdzenie dla -RemoveDir
    [string]$RenameFrom,    # zmiana nazwy na serwerze (razem z -RenameTo), sciezki od katalogu domowego
    [string]$RenameTo,
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

    # ── ZMIANA NAZWY NA SERWERZE ────────────────────────────────
    if ($RenameFrom) {
        if (-not $RenameTo) { throw "Podaj -RenameTo" }
        $from = '/' + $RenameFrom.TrimStart('/')
        $to = '/' + $RenameTo.TrimStart('/')
        $dir = ($from -replace '/[^/]+$', '')
        $r = Invoke-Curl @('-Q', "RNFR $from", '-Q', "RNTO $to", "ftp://$($cfg['host'])$dir/")
        if ($r.Ok) { Write-Host "ZMIENIONO  $from  ->  $to" -ForegroundColor Green }
        else { Write-Host "BLAD zmiany nazwy (exit $($r.Code))`n$($r.Output)" -ForegroundColor Red }
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

    # ── REKURENCYJNE USUNIECIE KATALOGU ─────────────────────────
    if ($RemoveDir) {
        $target = $RemoveDir.Trim('/')
        if ([string]::IsNullOrWhiteSpace($target)) { throw "Pusta sciezka -RemoveDir" }
        if (($target -split '/').Count -lt 2) {
            throw "Odmawiam usuwania '$target' - podaj sciezke o co najmniej dwoch segmentach"
        }

        function Get-RemoteEntries {
            param([string]$Dir)
            $r = Invoke-Curl @("ftp://$($cfg['host'])/$Dir/")
            if (-not $r.Ok) { throw "Nie moge wylistowac /$Dir (exit $($r.Code))`n$($r.Output)" }
            $items = @()
            foreach ($line in ($r.Output -split "`n")) {
                if ($line -match '^([dl-])[rwxsStT-]{9}\s+\d+\s+\S+\s+\S+\s+\d+\s+\S+\s+\S+\s+\S+\s+(.+?)\s*$') {
                    $name = $Matches[2]
                    if ($name -eq '.' -or $name -eq '..') { continue }
                    $items += [pscustomobject]@{ Name = $name; IsDir = ($Matches[1] -eq 'd') }
                }
            }
            return , $items
        }

        function Add-RemovalPlan {
            param([string]$Dir)
            foreach ($e in (Get-RemoteEntries -Dir $Dir)) {
                $child = "$Dir/$($e.Name)"
                if ($e.IsDir) { Add-RemovalPlan -Dir $child }
                else { $script:plan += [pscustomobject]@{ Path = $child; IsDir = $false } }
            }
            $script:plan += [pscustomobject]@{ Path = $Dir; IsDir = $true }
        }

        $script:plan = @()
        Add-RemovalPlan -Dir $target

        Write-Host "Plan usuwania /$target (od srodka na zewnatrz):" -ForegroundColor Cyan
        foreach ($i in $script:plan) {
            $kind = if ($i.IsDir) { 'KAT ' } else { 'PLIK' }
            Write-Host "  $kind  /$($i.Path)"
        }

        if (-not $Force) {
            Write-Host ""
            Write-Host "NIC NIE USUNIETO. Powtorz to samo polecenie z -Force, zeby wykonac." -ForegroundColor Yellow
            return
        }

        $ok = 0; $fail = 0
        foreach ($i in $script:plan) {
            $parent = ($i.Path -replace '/[^/]+$', '')
            $cmd = if ($i.IsDir) { "RMD /$($i.Path)" } else { "DELE /$($i.Path)" }
            $r = Invoke-Curl @('-Q', $cmd, "ftp://$($cfg['host'])/$parent/")
            if ($r.Ok) { $ok++; Write-Host "  USUNIETO  /$($i.Path)" -ForegroundColor DarkGreen }
            else { $fail++; Write-Host "  BLAD      /$($i.Path)  (exit $($r.Code))`n$($r.Output)" -ForegroundColor Red }
        }
        Write-Host "Zakonczono: $ok usunietych, $fail bledow." -ForegroundColor Cyan
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

    Write-Host "Nic nie zrobiono. Uzyj -Test, -ListPath, -LocalFile, -LocalDir albo -RemoveDir." -ForegroundColor Yellow
}
finally {
    Remove-Item $netrc -Force -ErrorAction SilentlyContinue
}
