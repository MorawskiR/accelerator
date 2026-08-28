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
- [ ] 🔵 **Doładuj konto Anthropic** — ⚠️ **klucz działa, ale konto nie ma środków.**
      Wywołanie `/v1/messages` zwraca `invalid_request_error`: *credit balance is too low*.
      Ścieżka: **https://platform.claude.com/settings/billing** (Settings → Billing).
      ⚠️ Komunikat API mówi „Plans & Billing” — **taka pozycja nie istnieje**, to Settings → Billing.
      Domena to dziś `platform.claude.com`, nie `console.anthropic.com`.
      Wymaga roli **Admin** lub **Billing** — na niższych opcja zakupu się nie pokaże.
      Model przedpłacony: kredyty kupuje się z góry, są dostępne od razu, **wygasają po roku**
      i są bezzwrotne — dla POC kupować mało. Auto-doładowania raczej nie włączać,
      dopóki nie znamy realnego zużycia z Fazy 4.
      ⚪ **Nie blokuje Faz 1–3** — AI wchodzi dopiero w Fazie 4. Zrobić przed nią.
      Uwaga metodyczna: sama ważność klucza (`/v1/models` → 200) **nie dowodzi**, że da się
      uruchomić model. Brak środków widać wyłącznie przy realnym wywołaniu `/v1/messages`
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
- [ ] 🔵 **Flow w playgroundzie** — 3–5 różnych typów. Chodzi o **pokrycie typów**,
      nie o liczbę: eksport z Fazy 5 ma osobne prefiksy dla każdego z nich.
      | Prefiks | Typ | Propozycja |
      |---|---|---|
      | `RT-` | Record-Triggered | na `Opportunity`, **after save**, z Decision na `StageName` |
      | `SF-` | Screen Flow | 2 ekrany, pole wymagane + walidacja |
      | `SCH-` | Scheduled | codziennie, `Get Records` na `Account` |
      | `AL-` | Autolaunched | wywoływany z innego Flow, bez triggera |
      Przynajmniej jeden **na standardowym obiekcie** (Account/Opportunity) — pola
      niestandardowe utrudniłyby czytanie wyniku w Fazie 3.
- [ ] 🔵 **Jeden Flow celowo wadliwy** — to **nie jest** punkt do odhaczenia byle jak.
      Bez niego nie udowodnimy, że `RiskScanner` z Fazy 3 cokolwiek wykrywa,
      a to sedno wartości całego narzędzia. Ma zawierać **wszystkie cztery** wady,
      bo dokładnie tych czterech reguł szuka skaner:
      1. **DML wewnątrz pętli** — `Update Records` w środku `Loop`
         → w realnym użyciu daje `Too many DML statements: 151`
      2. **DML bez fault path** — żaden element `Create/Update/Delete` nie ma
         połączenia błędu (`faultConnector`) → checklista TC-015
      3. **After Save bez entry criteria** — Record-Triggered, który uruchamia się
         przy każdej zmianie i aktualizuje ten sam rekord → ryzyko rekursji, RT-004
      4. **`Get Records` bez filtrów** — pobiera wszystko z obiektu, bez warunków
      Nazwij go rozpoznawalnie, np. `Flownatic_Bad_Example`, żeby nie pomylić go
      z poprawnymi przy testach.
      ⚠️ **Aktywuj go** — nieaktywne wersje mają inny status w `FlowDefinitionView`
      i mogłyby nie wejść do inwentarza z Fazy 2.
      *(bez tego nie ma jak udowodnić, że RiskScanner z Fazy 3 działa)*

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
- [ ] 🟢 `public_html/index.php` — front controller
- [ ] 🟢 `public_html/.htaccess` — `RewriteRule ^ index.php [QSA,L]` **+ kanoniczne 301**
      z `ftf.dobo.com.pl` na `dobo.com.pl/ftf/` — **uwaga: kierunek odwrócony 2026-08-27**.
      Oba adresy serwują ten sam katalog, ale kanoniczny jest podkatalog, bo tylko on ma
      ważny certyfikat. Callback OAuth z Fazy 2 jest dopasowywany dokładnie, więc wejście
      złym hostem wywaliłoby `redirect_uri mismatch`
- [ ] 🟢 `app/.htaccess` — `Require all denied` (druga linia obrony)
- [ ] 🟢 `app/src/Support/Config.php` — odczyt `.env`
- [ ] 🟢 `app/src/Support/Db.php` — PDO
- [ ] 🟢 `app/src/Support/Crypto.php` — AES-256-GCM na tokeny Salesforce
- [ ] 🟢 `app/src/Http/Routes.php` + `AuthMiddleware.php`
- [ ] 🟢 `app/db/migrations/001_init.sql` + `app/bin/migrate.php`
- [ ] 🟢 `app/templates/` — `layout.twig`, `login.twig`, `dashboard.twig`
- [ ] 🟢 `.env.example` do repo · 🔵 prawdziwy `.env` tylko lokalnie i na serwerze
- [ ] 🟢 `deploy.md` — spisana procedura wgrywania
- [ ] **Gotowe, gdy:** `https://dobo.com.pl/ftf/` → logowanie → dashboard

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
- [ ] 🔵 **External Client App** w playgroundzie — OAuth 2.0 Web Server Flow **z PKCE**.
      Setup → wyszukaj `external client` → **External Client App Manager** → New.
      Dwa Callback URL: `https://dobo.com.pl/ftf/sfoauth.php` (spike) oraz
      `https://dobo.com.pl/ftf/oauth/callback` (aplikacja).
      Scopes: `api`, `refresh_token`, `offline_access`. Instrukcja: `tools/sf-oauth/README.md`.
      ⚠️ Salesforce propaguje nową aplikację **do 30 minut**.
- [ ] 🟢 **Uruchom spike** i zapisz wynik — zwłaszcza listę pól `FlowDefinitionView`,
      bo na niej oprzemy SOQL. Po odczycie skasować oba pliki z serwera
- [ ] 🟢 `app/src/Salesforce/OAuthService.php` — authorize → callback → tokeny zaszyfrowane w bazie
- [ ] 🟢 Automatyczny refresh tokenu przy `401` / `INVALID_SESSION_ID`
- [ ] 🟢 `app/src/Salesforce/ApiClient.php` — cURL z retry, wspólny dla REST i Tooling
- [ ] 🟢 **Najpierw** `describe` na `FlowDefinitionView` — sprawdzić realne nazwy pól, nie zgadywać
- [ ] 🟢 Pobranie inwentarza Flow → tabela `flows`
- [ ] 🟢 Widok listy Flow z filtrem po typie i statusie
- [ ] 🟢 Czytelny komunikat przy rozłączonej org (nie błąd 500)
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
