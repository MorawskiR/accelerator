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
- [ ] 🔵 **Klucz Anthropic** — `ANTHROPIC_API_KEY` z console.anthropic.com
- [ ] 🔵 **Środowisko lokalne** — zainstaluj Laragon (PHP 8.x + MySQL + Composer); obecnie **nic nie ma**
- [ ] 🔵 **Flow w playgroundzie** — utwórz 3–5 różnych typów (Record-Triggered, Screen, Scheduled)
- [ ] 🔵 **Jeden Flow celowo wadliwy** — DML wewnątrz Loop, bez fault path
      *(bez tego nie ma jak udowodnić, że RiskScanner z Fazy 3 działa)*

---

## FAZA 1 — Szkielet aplikacji i deploy

- [x] 🟢 `tools/deploy.ps1` — wgrywanie przez FTPS (`-Test`, `-ListPath`, `-LocalFile`, `-LocalDir`, `-DeleteRemote`, `-RenameFrom`/`-RenameTo`)
- [x] 🟢 Strona-wizytówka `site/index.html` na `dobo.com.pl` — dowód, że cała ścieżka deployu działa
- [ ] 🟢 `composer.json` + instalacja zależności lokalnie (Slim 4, Twig, PhpSpreadsheet, anthropic-ai/sdk)
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

- [ ] 🔵 **Connected App** w playgroundzie — OAuth 2.0 Web Server Flow **z PKCE**,
      callback `https://dobo.com.pl/ftf/oauth/callback`, scopes: `api`, `refresh_token`, `offline_access`
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
