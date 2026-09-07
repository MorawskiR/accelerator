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
    [string]$UploadZip,     # katalog lokalny -> zip -> wgranie -> rozpakowanie na serwerze
    [string]$ExtractorPath = 'domains/dobo.com.pl/public_html/ftf',  # gdzie ladowac rozpakowywacz
    [string]$ExtractorUrl  = 'https://dobo.com.pl/ftf',              # ten sam katalog po HTTPS
    [string]$ResolveIp     = '185.208.164.165',                      # firmowy DNS klamie, wymuszamy IP
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

function Invoke-CurlPlain {
    # Osobno od Invoke-Curl: BEZ --netrc-file, zeby dane logowania FTP nie trafily
    # w zadanie HTTP do serwera WWW. curl nie dopasowalby ich po nazwie hosta,
    # ale nie wysylamy tego, czego nie musimy.
    param([string[]]$CurlArgs)
    $out = & curl.exe @('--connect-timeout', '20', '--max-time', '300', '-sS') @CurlArgs 2>&1
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

    # ── ZIP + WGRANIE + ROZPAKOWANIE NA SERWERZE ────────────────
    # Powod istnienia: FTPS wgrywa plik po pliku, a vendor/ to ~3700 plikow.
    # Jeden transfer zamiast tysiecy skraca deploy z godziny do minuty.
    # Serwer nie ma powloki, ale ma PHP z rozszerzeniem zip - wiec rozpakowuje
    # jednorazowy skrypt, ktory kasuje sam siebie po zakonczeniu.
    if ($UploadZip) {
        if (-not $RemotePath) { throw 'Podaj -RemotePath, np. "flownatic-app/vendor"' }
        if (-not (Test-Path $UploadZip)) { throw 'Nie ma katalogu: $UploadZip' }

        $srcDir     = (Resolve-Path $UploadZip).Path
        $remoteDir  = $RemotePath.Trim('/')
        $parentDir  = ($remoteDir -replace '/[^/]+$', '')
        if ($parentDir -eq $remoteDir) { $parentDir = '' }
        $zipName    = (Split-Path $remoteDir -Leaf) + '.zip'
        $remoteZip  = if ($parentDir) { "$parentDir/$zipName" } else { $zipName }
        $depth      = ($ExtractorPath.Trim('/') -split '/').Count

        # ── 1. spakowanie lokalnie ──
        $localZip = Join-Path $env:TEMP ((Split-Path $remoteDir -Leaf) + '-' + [guid]::NewGuid().ToString('N').Substring(0,8) + '.zip')
        Write-Host "Pakowanie $srcDir ..." -ForegroundColor Cyan
        # UWAGA: NIE uzywac ZipFile::CreateFromDirectory. W .NET Framework (PowerShell 5.1)
        # zapisuje separatory jako \, a specyfikacja ZIP wymaga /. PHP potraktowal je wtedy
        # jako czesc nazwy pliku i zamiast drzewa katalogow powstalo 3738 plaskich plikow.
        Add-Type -AssemblyName System.IO.Compression
        Add-Type -AssemblyName System.IO.Compression.FileSystem
        $zs = [System.IO.File]::Open($localZip, [System.IO.FileMode]::Create)
        $ar = New-Object System.IO.Compression.ZipArchive($zs, [System.IO.Compression.ZipArchiveMode]::Create)
        foreach ($f in (Get-ChildItem -Path $srcDir -Recurse -File)) {
            $rel = $f.FullName.Substring($srcDir.Length).TrimStart([char]92) -replace [regex]::Escape([string][char]92), '/'
            $en = $ar.CreateEntry($rel, [System.IO.Compression.CompressionLevel]::Optimal)
            $es = $en.Open(); $fs = [System.IO.File]::OpenRead($f.FullName)
            $fs.CopyTo($es); $fs.Dispose(); $es.Dispose()
        }
        $ar.Dispose(); $zs.Dispose()
        $mb    = [math]::Round((Get-Item $localZip).Length / 1MB, 1)
        $files = (Get-ChildItem -Path $srcDir -Recurse -File).Count
        Write-Host "  $files plikow -> archiwum $mb MB" -ForegroundColor DarkGreen

        try {
            # ── 2. wgranie archiwum (jeden transfer) ──
            Write-Host "Wgrywanie archiwum do /$remoteZip ..." -ForegroundColor Cyan
            $r = Invoke-Curl @('--ftp-create-dirs', '-T', $localZip, "ftp://$($cfg['host'])/$remoteZip")
            if (-not $r.Ok) { throw "Nie udalo sie wgrac archiwum (exit $($r.Code))`n$($r.Output)" }

            # ── 3. jednorazowy rozpakowywacz ──
            $php = @"
<?php
header('Content-Type: application/json');
`$t0   = microtime(true);
`$home = dirname(__DIR__, $depth);
`$zip  = `$home . '/$remoteZip';
`$dest = `$home . '/$remoteDir';
`$out  = [];
try {
    if (!class_exists('ZipArchive')) { throw new RuntimeException('brak rozszerzenia zip na serwerze'); }
    if (!is_file(`$zip))             { throw new RuntimeException('nie znalazlem archiwum: ' . `$zip); }
    if (!is_dir(`$dest) && !mkdir(`$dest, 0755, true) && !is_dir(`$dest)) {
        throw new RuntimeException('nie moge utworzyc katalogu: ' . `$dest);
    }
    `$z = new ZipArchive();
    `$rc = `$z->open(`$zip);
    if (`$rc !== true) { throw new RuntimeException('ZipArchive::open zwrocilo ' . `$rc); }
    `$out['wpisow'] = `$z->numFiles;
    // Rozpakowujemy wpis po wpisie zamiast extractTo: normalizujemy separatory
    // (archiwa z Windowsa potrafia miec \) i odrzucamy wyjscie poza katalog docelowy.
    `$pominiete = 0;
    for (`$i = 0; `$i < `$z->numFiles; `$i++) {
        `$nazwa = str_replace('\\', '/', `$z->getNameIndex(`$i));
        if (`$nazwa === '' || strpos(`$nazwa, '../') !== false || `$nazwa[0] === '/') { `$pominiete++; continue; }
        `$cel = `$dest . '/' . `$nazwa;
        if (substr(`$nazwa, -1) === '/') { if (!is_dir(`$cel)) { @mkdir(`$cel, 0755, true); } continue; }
        `$kat = dirname(`$cel);
        if (!is_dir(`$kat) && !@mkdir(`$kat, 0755, true) && !is_dir(`$kat)) { `$pominiete++; continue; }
        `$we = `$z->getStream(`$z->getNameIndex(`$i));
        if (`$we === false) { `$pominiete++; continue; }
        `$wy = fopen(`$cel, 'wb');
        stream_copy_to_stream(`$we, `$wy);
        fclose(`$wy); fclose(`$we);
    }
    `$out['pominiete'] = `$pominiete;
    `$z->close();
    `$out['ok']       = true;
    `$out['cel']      = `$dest;
    `$out['sekundy']  = round(microtime(true) - `$t0, 1);
    `$out['limit_php']= (int) ini_get('max_execution_time');
    `$out['zip_usuniety'] = @unlink(`$zip);
} catch (Throwable `$e) {
    `$out['ok'] = false;
    `$out['blad'] = `$e->getMessage();
}
echo json_encode(`$out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@unlink(__FILE__);
"@
            $localPhp = Join-Path $env:TEMP '_unzip.php'
            Set-Content -Path $localPhp -Value $php -Encoding utf8 -NoNewline

            Write-Host "Wgrywanie rozpakowywacza ..." -ForegroundColor Cyan
            $r = Invoke-Curl @('--ftp-create-dirs', '-T', $localPhp,
                               "ftp://$($cfg['host'])/$($ExtractorPath.Trim('/'))/_unzip.php")
            if (-not $r.Ok) { throw "Nie udalo sie wgrac rozpakowywacza (exit $($r.Code))`n$($r.Output)" }
            Remove-Item $localPhp -Force -ErrorAction SilentlyContinue

            # ── 4. uruchomienie przez HTTPS (z pominieciem firmowego DNS) ──
            Write-Host "Rozpakowywanie na serwerze ..." -ForegroundColor Cyan
            $urlHost = ([uri]$ExtractorUrl).Host
            $r = Invoke-CurlPlain @('--resolve', "${urlHost}:443:$ResolveIp", "$ExtractorUrl/_unzip.php")
            Write-Host $r.Output

            if ($r.Output -match '"ok":true') {
                Write-Host "GOTOWE - rozpakowane do /$remoteDir" -ForegroundColor Green
                Write-Host "Rozpakowywacz skasowal sam siebie." -ForegroundColor DarkGreen
            }
            else {
                Write-Host "ROZPAKOWANIE NIE POWIODLO SIE." -ForegroundColor Red
                Write-Host "Sprzatanie recznie:" -ForegroundColor Yellow
                Write-Host "  .\tools\deploy.ps1 -DeleteRemote `"$($ExtractorPath.Trim('/'))/_unzip.php`""
                Write-Host "  .\tools\deploy.ps1 -DeleteRemote `"$remoteZip`""
            }
        }
        finally {
            Remove-Item $localZip -Force -ErrorAction SilentlyContinue
        }
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
