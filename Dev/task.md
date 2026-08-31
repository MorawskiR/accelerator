# Flownatic — lista zadań

> **Ten plik jest operacyjnym trackerem.** Tutaj odznaczamy postęp.
> `plan.md` obok wyjaśnia **dlaczego** każdy krok wygląda tak, a nie inaczej — zaglądaj tam,
> gdy zadanie nie jest oczywiste. Zasada pracy: **jedna faza naraz**, nie zaczynamy kolejnej,
> dopóki poprzednia nie przejdzie swojego kryterium „Gotowe, gdy".

**Legenda:** `[ ]` do zrobienia · `[x]` zrobione · 🔵 po stronie Rafała · 🟢 po stronie Claude

---

## FAZA 0 — Fundament i weryfikacja hostingu

- [x] 🔵 Zweryfikuj pakiet cyberfolks — PHP 8.4.21, DirectAdmin, cyber_SPRINT
- [x] 🟢 Sprawdź rozszerzenia PHP — wszystkie OK, w tym krytyczny `zip`
- [x] 🟢 Ustal drogę wgrywania plików — SSH nieużywalny, **FTPS działa**, `tools/deploy.ps1`
- [x] 🔵 Utwórz `%USERPROFILE%\.ftp-dobo.txt` z danymi FTP (poza repo)
- [x] 🔵 **Subdomena `ftf.dobo.com.pl`** — ✅ 2026-08-27, vhost odpowiada HTTP 200.
      ⚠️ DocumentRoot wyszedł jako `domains/dobo.com.pl/public_html/ftf/`, **nie** `domains/ftf.dobo.com.pl/`
      — czyli wewnątrz `public_html` domeny głównej. Konsekwencja: `app/` **nie może** stanąć obok,
      musi trafić poza drzewo domen (`~/flownatic-app/`). Szczegóły w `kontekst.md`, sekcja 4 punkt 7.
- [x] 🔵 **Usuń zbędną subdomenę `ftp.dobo.com.pl`** — ✅ 2026-08-27, vhost zniknął (HTTP 403).
      ⚠️ Zostały pliki: `domains/dobo.com.pl/public_html/ftp/` nadal istnieje i odpowiada 200
      pod `dobo.com.pl/ftp/`. DirectAdmin kasuje pliki jako osobną opcję — patrz punkt niżej
- [x] 🟢 **Skasuj osierocony katalog `public_html/ftp/`** — ✅ 2026-08-27, `dobo.com.pl/ftp/` zwraca 404.
      Zamiast Menedżera plików: `deploy.ps1` dostał `-RemoveDir` (rekurencyjnie, `DELE` + `RMD`).
      Bez `-Force` pokazuje wyłącznie plan; odmawia ścieżek krótszych niż dwa segmenty
- [ ] 🔵 **Certyfikat SSL dla `ftf.dobo.com.pl`** — ⚪ **już nie blokuje, opcjonalne.**
      Adresem produkcyjnym jest `https://dobo.com.pl/ftf/`, który ma ważny certyfikat.
      Ten punkt daje wyłącznie ładniejszy adres; zrobić, jeśli certyfikat się pojawi.
      Kontekst historyczny — stan z 2026-08-27: Wymuszanie HTTPS jest włączone (HTTP → 301),
      ale jedyny certyfikat na serwerze to `CN=dobo.com.pl` (SAN: `dobo.com.pl`, `www.dobo.com.pl`,
      wystawca cyber_Folks). `ftf.dobo.com.pl` nie jest nim objęte → w przeglądarce ostrzeżenie
      o certyfikacie przed jakąkolwiek treścią. Do czasu wydania certyfikatu subdomena nie działa.
      ✅ **Droga dla ACME sprawdzona 2026-08-27 — nic nie blokuje.** Plik testowy w katalogu
      subdomeny serwuje się (`/probe.txt` → 200), więc `.htaccess` domeny głównej nie przeszkadza.
      `/.well-known/` zwraca 403 (katalog istnieje), a `/.well-known/acme-challenge/` zwraca 404
      nawet dla pliku fizycznie tam leżącego — czyli serwer **przechwytuje tę ścieżkę** i obsługuje
      wyzwanie sam. To normalne zachowanie DirectAdmin i oznacza, że walidacja powinna przejść
- [x] 🔵 **Baza MySQL** — ✅ 2026-08-27, połączenie zweryfikowane z produkcji.
      MariaDB 10.6.27, baza pusta (0 tabel), użytkownik ma `ALL PRIVILEGES` na swojej bazie.
      ⚠️ **Panel założył bazę w `utf8mb3`, nie `utf8mb4`** — mimo że o to prosiliśmy.
      Poprawione `ALTER DATABASE` przy pustej bazie; jest `utf8mb4` / `utf8mb4_unicode_ci`.
      Gdyby kiedyś zakładać kolejną bazę — panel prawdopodobnie znów da `utf8mb3`, sprawdzić.
      Dane w `%USERPROFILE%\.flownatic-db.txt`, poza repo.
      Oryginalne parametry (do wglądu): DirectAdmin → Zarządzanie kontem → Bazy danych MySQL
      | nazwa bazy | `flownatic` → panel utworzy `qekbnopwvk_flownatic` |
      | użytkownik | `flownatic` → `qekbnopwvk_flownatic`, pełne uprawnienia do tej bazy |
      | hasło | wygenerowane przez panel, silne |
      | host | `localhost` — aplikacja stoi na tym samym serwerze |
      | **kodowanie** | **`utf8mb4` / `utf8mb4_unicode_ci`** — nie `utf8`! |
      ⚠️ `utf8` w MySQL to trzybajtowy wariant, który **gubi emoji i część znaków** —
      metadane Flow z Salesforce potrafią je zawierać. Zmiana kodowania po zapisaniu
      danych jest bolesna, więc trzeba ustawić to od razu.
      🔑 Dane zapisz w `%USERPROFILE%\.flownatic-db.txt` — **poza repozytorium**,
      w formacie `host=` / `dbname=` / `user=` / `pass=`, tak jak `.ftp-dobo.txt`.
      Hasło nie trafia do rozmowy ani do commita
- [x] 🔵 **Playground org** — ✅ 2026-08-27, org odpowiada, certyfikat ważny.
      `resilient-narwhal-j9207g-dev-ed.trailblaze.my.salesforce.com`
      API do **v67.0 (Summer '26)** — zgodne z tym, co zakłada `plan.md`.
      ✅ Profil **System Administrator** potwierdzony 2026-08-27 — Connected App w Fazie 2
      jest wykonalna
- [x] 🔵 **Klucz Anthropic** — ✅ 2026-08-28, klucz utworzony i **ważny**.
      `GET /v1/models` zwraca 200, `claude-opus-5` jest na liście dostępnych modeli.
      Przechowywany w `%USERPROFILE%\.flownatic-anthropic.txt`, poza repo.
- [x] 🔵 **Środowisko lokalne** — ✅ 2026-08-28, Laragon w `C:\laragon`.
      Jest Composer, MySQL, Apache, Node, HeidiSQL. Laragon dodany do PATH.
      ⚠️ **Zainstalowany PHP to 8.3.33, nie 8.4** — `bin/php/` zawiera tylko tę wersję,
      więc przełącznik w menu nie miał czego zaoferować. Produkcja stoi na **8.4.21**.
      Nie blokuje: `composer.json` ma `config.platform.php = 8.4.21`, więc Composer
      rozwiązuje zależności pod PHP produkcji niezależnie od wersji lokalnej.
      Różnica dotyczy wyłącznie lokalnego uruchamiania kodu.
- [ ] ⏸️ **Opcjonalnie: PHP 8.4 lokalnie — ODLOZONE, decyzja 2026-08-28.**
      Pierwotny cel (zgodnosc z produkcja przy budowaniu `vendor/`) **odpadl**: Composer
      rozwiazuje zaleznosci pod 8.4.21 dzieki `config.platform.php`, a autoloader zostal
      zweryfikowany **na produkcji**, pod prawdziwym PHP 8.4.21.
      Zostaje jedna realna roznica: **PHP 8.4 uznaje za przestarzale niejawnie nullowalne
      parametry** (`f(Foo $x = null)`). Na 8.3 przechodzi cicho, na 8.4 sypie ostrzezeniami.
      Tansze lekarstwo niz drugi PHP: **pisac jawne `?Typ` od poczatku** — i tak lepszy styl,
      a `composer.json` deklaruje `php ^8.2`, wiec kod ma byc zgodny z szerszym zakresem.
      Lokalne 8.3 dziala przy okazji jak straznik: skladnia dostepna tylko w 8.4 wywali sie
      od razu, zamiast przejsc lokalnie i zaskoczyc na produkcji.
      **Wrocic, gdy:** trafimy na zachowanie rozniace sie miedzy wersjami, albo w Fazie 6,
      gdzie mierzymy realne czasy i srodowisko powinno odpowiadac produkcji co do wersji.
- [x] 🔵 **Flow w playgroundzie** — ✅ 2026-08-29, wszystkie cztery typy pokryte.
      **Wzorzec do porównania w Fazie 2** — inwentarz w apce ma zwrócić dokładnie te pozycje:
      | # | Nazwa | Typ |
      |---|---|---|
      | 1 | `SF-Create Case for Contact` | Screen Flow |
      | 2 | `SF-Add Contact` | Screen Flow |
      | 3 | `AL-Closed Won Opportunities` | Autolaunched |
      | 4 | `SCH- Task on not closed opp` | Scheduled |
      | 5 | `RT-Currency change` | Record-Triggered |
      ⚠️ Nazwy przepisane od Rafała, **niezweryfikowane przez API** — spike OAuth nie był
      jeszcze uruchomiony. Przy pierwszym imporcie sprawdzić pisownię, zwłaszcza spację
      w `SCH- Task` i wielkość liter — API zwraca `DeveloperName` bez spacji i myślników.
- [x] 🔵 **Jeden Flow celowo wadliwy** — ✅ 2026-08-31, utworzony przez Rafała.
      Nazwa **do potwierdzenia przy pierwszym imporcie** — nie została podana, a bez OAuth
      nie da się jej odczytać z API.
      To on jest dowodem, że `RiskScanner` z Fazy 3 cokolwiek wykrywa. Kryterium
      „Gotowe, gdy” Fazy 3 brzmi: **na tym Flow zapalają się „DML w pętli”
      i „brak fault path”**. Reguły, których szuka skaner:
      1. DML wewnątrz pętli → `Too many DML statements: 151`
      2. DML bez fault path → TC-015
      3. After Save bez entry criteria → ryzyko rekursji, RT-004
      4. `Get Records` bez filtrów → nadmiar rekordów
      ⚠️ Jeśli któraś z wad nie znalazła się w Flow, przetestujemy tylko część skanera.
      Sprawdzić to przy pierwszym uruchomieniu Fazy 3 i w razie potrzeby dorobić.
---

## FAZA 1 — Szkielet aplikacji i deploy

- [x] 🟢 `tools/deploy.ps1` — wgrywanie przez FTPS (`-Test`, `-ListPath`, `-LocalFile`, `-LocalDir`, `-DeleteRemote`, `-RenameFrom`/`-RenameTo`)
- [x] 🟢 Strona-wizytówka `site/index.html` na `dobo.com.pl` — dowód, że cała ścieżka deployu działa
- [x] 🟢 `composer.json` + instalacja zależności — ✅ 2026-08-28.
      Slim 4.15.2, slim/psr7 1.8.0, slim/twig-view 3.4.1, twig 3.28.0,
      PhpSpreadsheet 5.9.0, anthropic-ai/sdk 0.44.0. Autoloader sprawdzony **na produkcji**:
      wszystkie klasy się ładują, PHP 8.4.21, `zip` obecny.
- [x] 🟢 **`deploy.ps1 -UploadZip`** — deploy `vendor/` przez archiwum zamiast plik po pliku.
      3738 plików → 4,3 MB w jednym transferze, rozpakowanie na serwerze **1,4 s** przy limicie 180 s.
      Bez tego ten sam deploy trwałby 16–60 minut. `vendor/` leży w `~/flownatic-app/`,
      poza `domains/` — sprawdzone, że nie da się go otworzyć z przeglądarki.
- [x] 🟢 `public_html/index.php` — ✅ 2026-08-31. Szuka katalogu aplikacji w dwóch miejscach
      (`app/` obok lokalnie, `~/flownatic-app/` na serwerze), base path wyliczany ze `SCRIPT_NAME`,
      więc ten sam plik działa w korzeniu i w podkatalogu `/ftf`.
- [x] 🟢 `public_html/.htaccess` — ✅ 2026-08-31. Rewrite do front controllera, **kanoniczne 301**
      z `ftf.dobo.com.pl` na `dobo.com.pl/ftf/`, `Options -Indexes`, blokada serwowania
      `.env`/`.sql`/`.log`, nagłówki `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`.
- [x] 🟢 `app/.htaccess` — ✅ 2026-08-31, `Require all denied` jako druga linia obrony.
- [x] 🟢 `app/src/Support/Config.php` — ✅ 2026-08-31. Bez `phpdotenv`, ta sama ścieżka lokalnie
      i na serwerze. **13 testów**, w tym `=` w wartości, `#` w cudzysłowie, wcięcia, wyjątki.
- [x] 🟢 `app/src/Support/Db.php` — ✅ 2026-08-31. PDO, wyjątki, prawdziwe prepared statements,
      `utf8mb4`. **9 testów na prawdziwej bazie**, w tym odporność na wstrzyknięcie SQL i emoji.
- [x] 🟢 `app/src/Support/Crypto.php` — ✅ 2026-08-31. AES-256-GCM. **12 testów**, w tym wykrywanie
      podmiany szyfrogramu, podmiany taga, obcego klucza i złego `APP_KEY`.
- [x] 🟢 `app/src/Http/Routes.php` + `AuthMiddleware.php` — ✅ 2026-08-31. Logowanie z CSRF,
      `session_regenerate_id`, jednakowy komunikat przy złym loginie i haśle, trasa `/health`.
- [x] 🟢 `app/db/migrations/001_init.sql` + `app/bin/migrate.php` — ✅ 2026-08-31. Sześć tabel
      plus rejestr migracji, przenośny SQL (MySQL lokalnie, MariaDB na produkcji), tryby
      `--status` i `--dry-run`. **10 testów** na łańcuchu user → połączenie → flow → wersja → TC.
- [x] 🟢 `app/templates/` — ✅ 2026-08-31, `layout.twig`, `login.twig`, `dashboard.twig`.
      Dashboard jawnie wypisuje, czego jeszcze nie ma, zamiast udawać gotową aplikację.
- [x] 🟢 `.env.example` do repo — ✅ 2026-08-31, komplet kluczy aż do Fazy 4.
      🔵 prawdziwy `.env` tylko lokalnie i na serwerze, nigdy w commicie.
- [x] 🟢 **Nieplanowane, ale potrzebne:** `app/bin/genkey.php` (generuje `APP_KEY`)
      oraz `app/bin/adduser.php` (zakłada konto z CLI — rejestracji przez formularz nie ma,
      bo aplikacja stoi pod publicznym adresem).
- [x] 🟢 `deploy.md` — ✅ 2026-08-31, spisany **po** pierwszym realnym wdrożeniu,
      nie z planu. Zawiera pułapki, które faktycznie wystąpiły: placeholder `index.html`
      serwowany przed `index.php`, konieczność budowania paczki bez lokalnego `.env`
      oraz uruchamianie migracji bez powłoki. Lista kontrolna po wdrożeniu.
- [x] 🟢 `app/src/Support/Migrator.php` — logika migracji wyciągnięta z CLI,
      bo na serwerze nie ma powłoki i te same migracje trzeba uruchomić przez HTTP.
      Przetestowana na pustej bazie: 7 tabel, idempotencja działa.
- [x] **Gotowe, gdy:** ✅ **2026-08-31 — FAZA 1 ZAMKNIĘTA.**
      `https://dobo.com.pl/ftf/` → logowanie → dashboard, `APP_ENV=production`.
      Zweryfikowane na produkcji: `/health` zwraca PHP 8.4.21 i `"baza":"tak"`,
      niezalogowany dostaje 302 na `/login`, złe hasło odrzucone, poprawne wpuszcza
      na dashboard z e-mailem użytkownika. Bezpieczeństwo: wszystkie skrypty jednorazowe
      zwracają 404, `.env` i listowanie katalogów 403, trzy nagłówki bezpieczeństwa
      obecne, wizytówka na `dobo.com.pl` nietknięta.

---

## FAZA 2 — OAuth do Salesforce i inwentarz Flow

> **⏸️ Faza nie rozpoczęta.** Zaczynamy dopiero po zamknięciu kryterium „Gotowe, gdy" Fazy 1.
> Wyjątkiem jest spike poniżej: kod powstał 2026-08-28, żeby zawczasu zdjąć ryzyko z OAuth,
> ale **nie został uruchomiony** i świadomie czeka na tę fazę.

- [x] 🟢 **Spike OAuth — kod napisany** (2026-08-28), `tools/sf-oauth/sfoauth.php`.
      Samodzielny skrypt bez Composera, więc uruchomi się mimo braku Laragona.
      Sprawdza PKCE, obecność `refresh_token`, **`describe` na `FlowDefinitionView`**
      (realne nazwy pól zamiast zgadywania) i liczbę Flow w org.
      ⚠️ **Nieuruchomiony i niezweryfikowany** — brak lokalnego PHP, więc nawet składnia
      nie została sprawdzona maszynowo, tylko strukturalnie.
- [x] 🔵 **External Client App** — ✅ 2026-08-31, `Flownatic POC`. Działa, potwierdzone
      przejściem spike'a: PKCE, dwa Callback URL, scopes `api` i `refresh_token`.
      Consumer Key i Secret w `%USERPROFILE%\.flownatic-sf.txt`, poza repo.
      Oryginalna instrukcja (na wypadek zakładania od nowa) — OAuth 2.0 Web Server Flow **z PKCE**.
      Setup → wyszukaj `external client` → **External Client App Manager** → New.
      Dwa Callback URL: `https://dobo.com.pl/ftf/sfoauth.php` (spike) oraz
      `https://dobo.com.pl/ftf/oauth/callback` (aplikacja).
      Scopes: `api`, `refresh_token`, `offline_access`. Instrukcja: `tools/sf-oauth/README.md`.
      ⚠️ Salesforce propaguje nową aplikację **do 30 minut**.
- [x] 🟢 **Uruchom spike** — ✅ 2026-08-31, **OAuth przeszedł**.
      PKCE (S256) działa, wrócił **`refresh_token`** — automatyczne odnawianie sesji
      w tej fazie zadziała. `scope: refresh_token api`, token_type Bearer.
      `describe` zwrócił **34 pola** `FlowDefinitionView` → `Dev/reference/flowdefinitionview.md`.
      ⚠️ Do zrobienia: skasować `sfoauth.php` i `sf-oauth.php` z serwera.
- [x] 🟢 `app/src/Salesforce/OAuthService.php` — ✅ 2026-08-31. Web Server Flow z PKCE,
      napisany **na podstawie przepływu, który faktycznie przeszedł** w spike'u, nie w ciemno.
      Tokeny trafiają do bazy wyłącznie zaszyfrowane. **20 testów** na prawdziwej bazie:
      `code_challenge` to faktyczny SHA256 z weryfikatora, zły `state` odrzucony,
      `org_id` wyciągnięty z identity URL, token w bazie nieczytelny i odszyfrowywalny,
      brak `refresh_token` w odpowiedzi wykryty od razu z podpowiedzią o scope.
- [x] 🟢 Automatyczny refresh tokenu przy `401` / `INVALID_SESSION_ID` — ✅ 2026-08-31.
      `ApiClient` wykrywa wygaśnięcie i woła `OAuthService::refresh()`, po czym powtarza
      żądanie nowym tokenem. **Odwołany refresh token daje `null`, nie wyjątek** —
      to nie awaria aplikacji, tylko sygnał „połącz org ponownie”.
      Świadomie **nie zapisujemy czasu wygaśnięcia**: Salesforce nie zwraca `expires_in`
      dla tego przepływu, a długość sesji to ustawienie org. Zamiast zgadywać — reagujemy.
- [x] 🟢 `app/src/Salesforce/ApiClient.php` — ✅ 2026-08-31. cURL z retry, wspólny dla
      REST i Tooling. Transport wydzielony do interfejsu `HttpTransport`, żeby dało się
      przetestować **bez żywej org** — inaczej logika ponawiania wyszłaby dopiero w połowie
      importu Flow. **15 testów** na atrapie: odświeżenie tokenu przy 401/`INVALID_SESSION_ID`
      i powtórzenie z nowym tokenem, brak zapętlenia przy drugim 401, **brak ponawiania
      przy 400/403/404** (oszczędza limit API playgrounda), ponawianie przy 5xx i 429,
      czytelny komunikat z `errorCode` zamiast surowego JSON-a.
- [x] 🟢 **`describe` na `FlowDefinitionView`** — ✅ 2026-08-31. Opłaciło się:
      **trzy rozbieżności** wobec schematu z Fazy 1, poprawione migracją `002`.
      1. `ActiveVersionId`/`LatestVersionId` to **identyfikatory (string)**, nie numery
         wersji — miałem je jako `INT`. Numer to osobne pole `VersionNumber`.
      2. Brakowało **`RecordTriggerType`** (Create/Update/Delete) — bez niego nie
         odróżnimy Flow przy tworzeniu od tego przy aktualizacji, a to inne przypadki testowe.
      3. Brakowało `DurableId` (stabilna tożsamość), `Description` (zasila prompt Fazy 4)
         i `LastModifiedDate` (pomijanie niezmienionych Flow bez pobierania metadanych).
      Zapytanie bazowe SOQL zapisane w `Dev/reference/flowdefinitionview.md`.
- [x] 🟢 Pobranie inwentarza Flow → tabela `flows` — ✅ 2026-08-31, `Flow\FlowImporter`.
      SOQL oparty na **realnych polach** z `describe`, nie na dokumentacji.
      **Paginacja** przez `nextRecordsUrl` — bez niej import po cichu urwałby się na 2000
      rekordach; playground ma ich kilka, ale realna org może mieć setki.
      Filtr `IsTemplate = false AND ManageableState = 'unmanaged'` odsiewa szablony
      i pakiety zarządzane — nie testujemy cudzego kodu, a każdy zbędny rekord to
      zmarnowany limit API przy pobieraniu metadanych w Fazie 3.
      **Zniknięte Flow są oznaczane, nie kasowane** — kaskada zabrałaby ze sobą
      wygenerowane przypadki testowe. **13 testów**, w tym paginacja, brak duplikatów
      przy ponownym imporcie i konwersja daty ISO na format MySQL.
- [x] 🟢 Widok listy Flow z filtrem po typie i statusie — ✅ 2026-08-31, `flows.twig`.
      Trasy: `/org/connect`, `/oauth/callback`, `/org/disconnect`, `/flows`, `/flows/sync`.
      Filtry sprawdzone na danych: typ `Flow` zwraca 2 z 6, „nieaktywne” zwraca 1.
      `RecordTriggerType` widoczny w kolumnie wyzwalacza.
- [x] 🟢 Czytelny komunikat przy rozłączonej org — ✅ 2026-08-31, **nie błąd 500**.
      Sprawdzone: import bez podłączonej org daje 302 i komunikat „Najpierw podlacz org.”.
      Każdy wyjątek z `ApiClient`/`OAuthService` jest przechwytywany i pokazywany
      użytkownikowi — wygasły refresh token, cofnięty dostęp czy błąd SOQL kończą się
      zdaniem na ekranie, a nie stroną błędu.
- [ ] **Gotowe, gdy:** lista Flow w apce zgadza się z Setup → Process Automation → Flows

---

## FAZA 3 — Metadane Flow i Flow Digest

- [ ] 🟢 `app/src/Flow/MetadataFetcher.php` — `Flow.Metadata` po jednym rekordzie (ograniczenie API)
- [ ] 🟢 Cache po `metadata_hash` — nie odpytywać niezmienionych Flow
- [ ] 🟢 **Import partiami** (~5 Flow na żądanie) — wymuszone przez `max_execution_time = 180 s`
- [ ] 🟢 Pasek postępu odpytywany AJAX-em, import wznawialny po przerwaniu
- [ ] 🟢 **`app/src/Flow/DigestBuilder.php`** — najważniejszy plik w projekcie:
  - [ ] typ i trigger (obiekt, before/after save, create/update/delete)
  - [ ] entry criteria w czytelnej formie
  - [ ] `decisions` — gałęzie z warunkami
  - [ ] operacje DML — co, na czym
  - [ ] pętle + flaga „DML wewnątrz pętli"
  - [ ] dla każdego elementu: czy ma `faultConnector`
  - [ ] ekrany, pola, walidacje (Screen Flow)
- [ ] 🟢 **`app/src/Flow/RiskScanner.php`** — reguły deterministyczne, zero AI:
  - [ ] DML w pętli → `Too many DML statements: 151`
  - [ ] DML bez fault path → TC-015
  - [ ] After Save bez entry criteria → ryzyko rekursji (RT-004)
  - [ ] `Get Records` bez filtrów → nadmiar rekordów
- [ ] 🟢 Widok struktury Flow + lista wykrytych ryzyk
- [ ] **Gotowe, gdy:** na celowo zepsutym Flow zapala się „DML w pętli" i „brak fault path"

---

## FAZA 4 — Warstwa AI

- [ ] 🔵 **Doładuj konto Anthropic** — klucz jest ważny (`GET /v1/models` → 200,
      `claude-opus-5` dostępny), ale **saldo zerowe**: `/v1/messages` zwraca
      `credit balance is too low`. Ścieżka: **platform.claude.com/settings/billing**
      (komunikat API myli, kierując do nieistniejącej pozycji „Plans & Billing”).
      Wymaga roli Admin lub Billing. Kredyty przedpłacone, **wygasają po roku**
      i są bezzwrotne — dla POC kupić mało. Szacunek: ~0,09 USD za jeden Flow
      (digest 2–5 KB, nie surowy JSON), czyli 10–18 USD na cały rozwój Fazy 4 i walidację.
      Auto-doładowania nie włączać, dopóki nie znamy realnego zużycia.
      ⚠️ **Ważność klucza nie dowodzi, że da się uruchomić model** — `/v1/models`
      zwraca 200 nawet przy pustym koncie. Sprawdzać realnym wywołaniem.
- [ ] 🟢 `composer require anthropic-ai/sdk`
- [ ] 🟢 `app/src/Ai/Prompts/system_checklist.md` — TC-001…TC-026 + reguły SF + format wyjścia
- [ ] 🟢 `app/src/Ai/TestCaseGenerator.php` — model `claude-opus-5`
- [ ] 🟢 **Prompt caching** — `cacheControl` na bloku `system`, Flow Digest w `messages`
- [ ] 🟢 **Structured outputs** — JSON Schema, bez parsowania markdownu
- [ ] 🟢 **Nie ustawiać** `thinking` ani `budgetTokens` (na Opus 5 zwraca 400)
- [ ] 🟢 Guard na `stopReason === 'refusal'`
- [ ] 🟢 Mapowanie: `checklist_ref` → TC-001…TC-026, prefiksy `RT-`/`SF-`/`SCH-`/`AL-`
- [ ] 🟢 Ryzyka z `RiskScanner` jako **obowiązkowe do pokrycia** w promptcie
- [ ] 🟢 Zapis kosztu w `generation_runs`
- [ ] **Gotowe, gdy:** dla Record-Triggered Flow dostajemy TC na trigger, każdą gałąź Decision, bulk 200, brak fault path

---

## FAZA 5 — Edycja i eksport

- [ ] 🟢 Edycja i akceptacja TC przed eksportem (`source = manual` dla dopisanych ręcznie)
- [ ] 🟢 `app/src/Export/XlsxExporter.php` — 6 arkuszy w układzie `SalesforcCloud_FTF.xlsx`
- [ ] 🟢 Inventory i Test Cases wypełnione, Defect Log i Progress Tracker puste z formułami
- [ ] 🟢 Eksport PDF — widok do druku (`@media print`)
- [ ] **Gotowe, gdy:** pobrany .xlsx wygląda jak framework, tylko wypełniony konkretami z org

---

## FAZA 6 — Walidacja pomysłu

- [ ] 🔵 Uruchom na 3–5 realnych Flow
- [ ] 🔵 Napisz dla nich testy **także ręcznie**, dla porównania
- [ ] 🔵 Zmierz: czas ręcznie vs. apka · TC trafione · TC bezużyteczne · **TC brakujące** ← najważniejsze
- [ ] 🔵 Demo 3 min + liczby do zgłoszenia №00001131
- [ ] 🔵 Decyzja o kolejnym kroku: multi-tenant · Einstein/Agentforce · pełny menedżer testów

---

## Zadania poboczne (nie blokują faz)

- [ ] 🟢 Przejrzeć `Plan projektu.odt` — czy zawiera coś, czego nie ma w `plan.md`
- [ ] 🟢 Usunąć `<meta name="robots" content="noindex">` ze strony przed prawdziwym startem
- [ ] 🔵 Rozważyć zmianę repo na prywatne (obecnie **publiczne**, zawiera dokumenty firmowe)
- [ ] 🟢 Git LFS dla `.odp`, jeśli prezentacja zacznie puchnąć w historii
