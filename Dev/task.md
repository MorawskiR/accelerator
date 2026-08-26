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
- [ ] 🔵 **Subdomena `ftf.dobo.com.pl`** — DirectAdmin → Subdomeny → dodaj `ftf` do `dobo.com.pl`
- [ ] 🔵 **Certyfikat SSL** — DirectAdmin → Certyfikaty SSL → Let's Encrypt dla `ftf.dobo.com.pl`
- [ ] 🔵 **Baza MySQL** — utwórz bazę i użytkownika, zapisz dane
- [ ] 🔵 **Playground org** — zaloguj się, potwierdź uprawnienia admina
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
- [ ] 🟢 `public_html/.htaccess` — `RewriteRule ^ index.php [QSA,L]`
- [ ] 🟢 `app/.htaccess` — `Require all denied` (druga linia obrony)
- [ ] 🟢 `app/src/Support/Config.php` — odczyt `.env`
- [ ] 🟢 `app/src/Support/Db.php` — PDO
- [ ] 🟢 `app/src/Support/Crypto.php` — AES-256-GCM na tokeny Salesforce
- [ ] 🟢 `app/src/Http/Routes.php` + `AuthMiddleware.php`
- [ ] 🟢 `app/db/migrations/001_init.sql` + `app/bin/migrate.php`
- [ ] 🟢 `app/templates/` — `layout.twig`, `login.twig`, `dashboard.twig`
- [ ] 🟢 `.env.example` do repo · 🔵 prawdziwy `.env` tylko lokalnie i na serwerze
- [ ] 🟢 `deploy.md` — spisana procedura wgrywania
- [ ] **Gotowe, gdy:** `https://ftf.dobo.com.pl` → logowanie → dashboard

---

## FAZA 2 — OAuth do Salesforce i inwentarz Flow

- [ ] 🔵 **Connected App** w playgroundzie — OAuth 2.0 Web Server Flow **z PKCE**,
      callback `https://ftf.dobo.com.pl/oauth/callback`, scopes: `api`, `refresh_token`, `offline_access`
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
