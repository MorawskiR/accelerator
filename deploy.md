# Deploy

Procedura wgrywania Flownatic na produkcję. Spisana po pierwszym realnym wdrożeniu
(2026-08-31), nie z planu — kolejność i pułapki są takie, jakie okazały się w praktyce.

## Co gdzie ląduje

```
/home/qekbnopwvk/
├── domains/dobo.com.pl/public_html/
│   ├── index.html              ← wizytówka, NIE RUSZAĆ
│   └── ftf/                    ← DOCUMENT ROOT aplikacji
│       ├── index.php           ← front controller
│       └── .htaccess
└── flownatic-app/              ← poza domains/, niedostępne z sieci
    ├── .env                    ← sekrety, NIGDY z repo
    ├── vendor/
    ├── src/  templates/  db/  bin/  storage/
    └── .htaccess               ← Require all denied
```

Adres produkcyjny: **https://dobo.com.pl/ftf/**

## Zanim zaczniesz

- `composer install --working-dir=app` — `vendor/` budujemy lokalnie, bo na serwerze
  nie ma powłoki
- Sprawdź, że lokalne testy przechodzą — po wdrożeniu diagnostyka jest dużo droższa

## 1. Kod aplikacji

**Nigdy nie pakuj `app/` w całości** — poleciałby lokalny `.env` z danymi bazy
deweloperskiej i nadpisał produkcyjny. Buduj paczkę z tego, co ma pojechać:

```powershell
$S = "$env:TEMP\flownatic-staging"
Remove-Item $S -Recurse -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory $S | Out-Null
Copy-Item .\app\src, .\app\templates, .\app\db, .\app\bin -Destination $S -Recurse
Copy-Item .\app\.htaccess -Destination $S

# kontrola przed wyslaniem
Get-ChildItem $S -Recurse -Force -Include ".env" | ForEach-Object { throw "W paczce jest .env!" }

.\tools\deploy.ps1 -UploadZip $S -RemotePath "flownatic-app"
```

`-UploadZip` pakuje katalog, wgrywa **jednym transferem** i rozpakowuje na serwerze
skryptem, który kasuje sam siebie. Powód: FTPS wgrywa plik po pliku, a `vendor/`
to 3738 plików — plik po pliku trwałoby 16–60 minut, archiwum trwa sekundy.

## 2. Zależności

Tylko gdy zmienił się `composer.lock`:

```powershell
.\tools\deploy.ps1 -UploadZip .\app\vendor -RemotePath "flownatic-app/vendor"
```

## 3. Katalog publiczny

```powershell
.\tools\deploy.ps1 -LocalFile .\public_html\index.php -RemotePath "domains/dobo.com.pl/public_html/ftf/"
.\tools\deploy.ps1 -LocalFile .\public_html\.htaccess -RemotePath "domains/dobo.com.pl/public_html/ftf/"
```

> **Pułapka przy pierwszym wdrożeniu:** DirectAdmin zostawia w katalogu subdomeny
> plik `index.html` (placeholder). Apache serwuje go **przed** `index.php`, więc
> aplikacja się nie pokaże, dopóki go nie skasujesz:
> `-DeleteRemote "domains/dobo.com.pl/public_html/ftf/index.html"`

## 4. Plik .env

Nie ma go w repo i **nigdy tam nie trafi**. Na produkcji tworzy się go raz:

- `APP_ENV=production`, `APP_DEBUG=false`
- `APP_KEY` — **inny niż lokalny**, wygenerowany przez `php app/bin/genkey.php`
- `DB_*` — z `%USERPROFILE%\.flownatic-db.txt`

Wgranie: `-LocalFile <plik> -RemotePath "flownatic-app/"`, a plik źródłowy skasować
zaraz po wysłaniu.

> Zmiana `APP_KEY` po zapisaniu tokenów Salesforce sprawi, że przestaną się
> odszyfrowywać — trzeba będzie połączyć org od nowa.

## 5. Migracje

Na serwerze nie ma powłoki, więc `php app/bin/migrate.php` tam nie zadziała.
Logika siedzi w `Support\Migrator`, którą uruchamia się jednorazowym skryptem
wgranym do katalogu publicznego. Skrypt **kasuje sam siebie** po wykonaniu:

```php
<?php
header('Content-Type: application/json');
$home = dirname(__DIR__, 4);
require $home . '/flownatic-app/vendor/autoload.php';
Flownatic\Support\Config::load($home . '/flownatic-app/.env');
$m = new Flownatic\Support\Migrator($home . '/flownatic-app/db/migrations');
echo json_encode(['wykonane' => $m->uruchom(), 'po' => $m->zastosowane()]);
@unlink(__FILE__);
```

## 6. Konto

Hasło generuj i **hashuj lokalnie**, na serwer wysyłaj wyłącznie hash — jawne hasło
nie musi opuszczać twojej maszyny. Zapisz je w pliku poza repozytorium
(`%USERPROFILE%\.flownatic-login.txt`), nigdy w rozmowie ani w commicie.

## 7. Weryfikacja

Firmowa sieć przechwytuje DNS i blokuje `dobo.com.pl`, więc **wymuszaj adres IP**:

```bash
R="--resolve dobo.com.pl:443:185.208.164.165"
curl -s $R https://dobo.com.pl/ftf/health
curl -s $R -o /dev/null -w "%{http_code}" https://dobo.com.pl/ftf/
```

Lista kontrolna po wdrożeniu:

- [ ] `/health` zwraca `"baza":"tak"` i właściwą wersję PHP
- [ ] `/` przekierowuje na `/login`
- [ ] logowanie działa, dashboard pokazuje `production`
- [ ] wszystkie skrypty jednorazowe zwracają **404**
- [ ] `.env` zwraca **403**, listowanie katalogów **403**
- [ ] nagłówki `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` obecne
- [ ] wizytówka na `dobo.com.pl` nadal działa

## Czego nie robić

- **Nie wgrywać `app/` w całości** — poleciałby lokalny `.env`
- **Nie zostawiać skryptów jednorazowych** — mają dostęp do bazy i sekretów;
  wszystkie kasują się same, ale to trzeba sprawdzić
- **Nie ustawiać `APP_DEBUG=true` na produkcji** — komunikaty błędów ujawniają ścieżki
  i fragmenty konfiguracji
