# Sales Cloud Flow Testing Framework — plan projektu

> **Zasada pracy: jedna faza naraz.** Nie zaczynamy kolejnej fazy, dopóki poprzednia nie przejdzie swojego
> kryterium „Gotowe, gdy". Każda faza kończy się czymś, co da się uruchomić i zobaczyć.

**Aplikacja:** webowa · **Hosting:** cyberfolks (`ftf.dobo.com.pl`) · **Stack:** PHP 8.1+ / MySQL / Slim 4
**AI:** Claude API (`claude-opus-5`) · **Org testowa:** hands-on playground · **Użytkownik:** jeden (POC)

---

## Status faz

| Faza | Zakres | Status |
|---|---|---|
| 0 | Fundament i weryfikacja hostingu | ⬜ Nie rozpoczęta |
| 1 | Szkielet aplikacji + deploy na produkcję | ⬜ Nie rozpoczęta |
| 2 | OAuth do Salesforce + automatyczny inwentarz Flow | ⬜ Nie rozpoczęta |
| 3 | Metadane Flow + Flow Digest + RiskScanner | ⬜ Nie rozpoczęta |
| 4 | Warstwa AI — generowanie przypadków testowych | ⬜ Nie rozpoczęta |
| 5 | Edycja i eksport do .xlsx | ⬜ Nie rozpoczęta |
| 6 | Walidacja pomysłu (Ideal validation) | ⬜ Nie rozpoczęta |

Legenda: ⬜ nie rozpoczęta · 🟡 w trakcie · ✅ zakończona

---

## Kontekst

**Skąd to się bierze.** W `Chcę zgłosić swój pomysł na stoworzenie.odt` zgłoszony został pomysł
(idea №00001131) na akcelerator do manualnego testowania Salesforce Flow. W `SalesforcCloud_FTF.xlsx`
jest gotowa treść merytoryczna: 6 arkuszy — Overview, Flow Inventory, Universal Checklist
(26 przypadków TC-001…TC-026 w 6 kategoriach), Test Cases (per typ Flow: RT-/SF-/SCH-/AL-),
Defect Log, Progress Tracker.

**Problem z wersją Excelową.** Arkusz jest statyczny i generyczny. Tester i tak musi ręcznie spisać
wszystkie Flow z org, przeczytać każdy w Flow Builderze, zrozumieć jego Decisions/Loops/DML,
i dopiero wtedy przepisać ogólne TC-001…TC-026 na konkretne kroki dla tego jednego Flow.
To 2–4 godziny na Flow, powtarzane na każdym projekcie od zera.

**Zamierzony efekt.** Aplikacja, która podłącza się do org przez API, sama czyta strukturę Flow
i zamienia generyczną checklistę w konkretne, wykonalne przypadki testowe dla tego konkretnego Flow —
z eksportem do Excela w formacie frameworku. Framework zostaje sensem merytorycznym;
aplikacja robi żmudną część.

---

## Rozumowanie od końca do początku

Łańcuch zależności — każdy punkt wymaga tego, co pod nim. Fazy to ten sam łańcuch czytany od dołu.

| # | Stan docelowy | Wymaga |
|---|---|---|
| 8 | Gotowa apka na `dobo.com.pl`, używana na realnych Flow | ↓ |
| 7 | Eksport do .xlsx w układzie 6 arkuszy frameworku | ↓ |
| 6 | Przypadki testowe w bazie, edytowalne przez testera | ↓ |
| 5 | AI generuje TC dopasowane do struktury konkretnego Flow | ↓ |
| 4 | **Flow Digest** — skondensowany opis Flow (nie surowy JSON) | ↓ |
| 3 | Pobrane metadane Flow z org (Tooling API, cache w bazie) | ↓ |
| 2 | Działający OAuth do Salesforce + lista Flow z org | ↓ |
| 1 | Szkielet PHP + baza + logowanie, **działające na produkcji** | ↓ |
| 0 | Dostępy, playground org, klucz API, lokalne środowisko | — |

**Dwie decyzje kolejnościowe, które są celowe:**

1. **Deploy na produkcję w Fazie 1, nie na końcu.** Ryzyko hostingowe (wersja PHP, brak Composera,
   uprawnienia katalogów, HTTPS pod callback OAuth) jest największe i najgorsze do odkrycia po trzech
   tygodniach pisania kodu lokalnie. Callback OAuth i tak wymaga publicznego HTTPS-a już w Fazie 2.
2. **Parser (Faza 3) przed AI (Faza 4).** Deterministyczny parser robi to, co musi być w 100%
   powtarzalne — strukturę i ryzyka techniczne. Model dostaje czysty, mały opis i robi to, w czym jest
   dobry — wymyśla scenariusze i dane testowe. Taniej, celniej, powtarzalnie, i część wartości działa
   nawet gdy API AI jest niedostępne. Gdyby wrzucać surowy JSON do modelu, jakość byłaby losowa.

---

## Architektura

```
Przeglądarka  ──►  ftf.dobo.com.pl  (Apache + PHP, cyberfolks)
                          │
                          ├──►  Salesforce Tooling API   (metadane Flow)
                          ├──►  Claude API                (generowanie TC)
                          └──►  MySQL                     (cache + wyniki)
```

Układ katalogów na serwerze — **kod źródłowy i sekrety poza katalogiem publicznym**:

```
~/domains/ftf.dobo.com.pl/
├── public_html/              ← document root, tylko to jest widoczne z sieci
│   ├── index.php             ← front controller
│   ├── .htaccess             ← rewrite wszystkiego do index.php
│   └── assets/
└── app/                      ← NIEDOSTĘPNE z przeglądarki
    ├── .env                  ← ANTHROPIC_API_KEY, SF_CLIENT_SECRET, APP_KEY
    ├── vendor/               ← zależności Composera
    ├── src/
    ├── templates/
    ├── db/migrations/
    └── storage/logs/
```

**Stack:** Slim 4 + PDO + Twig + `phpoffice/phpspreadsheet` + `anthropic-ai/sdk`.
Świadomie **nie Laravel** — na współdzielonym hostingu to walka z document rootem, uprawnieniami
i `artisan`, a nie potrzebujemy niczego, co daje w zamian.

### Schemat bazy

| Tabela | Rola |
|---|---|
| `users` | jedno konto, `password_hash` |
| `sf_connections` | org: `instance_url`, `access_token_enc`, `refresh_token_enc`, `expires_at` |
| `flows` | inwentarz: `api_name`, `label`, `process_type`, `trigger_object`, `trigger_type`, `is_active` |
| `flow_versions` | `version_number`, `status`, `metadata_json`, `metadata_hash`, `digest_json`, `fetched_at` |
| `test_cases` | `tc_code`, `category`, `title`, `steps`, `expected`, `priority`, `checklist_ref`, `source`(ai/manual), `status` |
| `generation_runs` | `model`, `input_tokens`, `output_tokens`, `cost_usd` — koszt widoczny od pierwszego dnia |

---

# FAZA 0 — Fundament i weryfikacja hostingu

**Cel:** usunąć niewiadome, zanim powstanie linijka kodu. Ta faza jest w całości poza kodem.

- [ ] **Zweryfikuj pakiet cyberfolks** (panel klienta): wersja PHP (potrzebne **8.1+**), czy jest **SSH**,
      czy jest dostęp do **Composera** na serwerze, czy można ustawić subdomenę z własnym document rootem
  - Brak Composera na serwerze **nie blokuje niczego** — `vendor/` budujemy lokalnie i wgrywamy FTP-em.
    Trzeba to tylko wiedzieć z góry, bo zmienia procedurę deployu z Fazy 1.
- [ ] **Subdomena** `ftf.dobo.com.pl` + certyfikat SSL (Let's Encrypt z panelu)
  - Osobna subdomena, nie podkatalog — izolacja od reszty strony i czysty callback OAuth
- [ ] **Baza MySQL** + użytkownik (zapisz dane dostępowe)
- [ ] **Playground org** — zaloguj się, potwierdź uprawnienia admina (potrzebne do Tooling API na Flow)
- [ ] **Klucz Anthropic** — `ANTHROPIC_API_KEY` z console.anthropic.com
- [ ] **Lokalne środowisko:** PHP 8.1+ / Composer / MySQL (Laragon albo XAMPP) + `git init` w katalogu projektu
- [ ] **Utwórz 3–5 Flow w playgroundzie** różnych typów (Record-Triggered, Screen, Scheduled)
  - **Jeden zrób celowo źle:** DML wewnątrz Loop, bez fault path.
    To będzie dowód, że analizator z Fazy 3 faktycznie działa.

**Gotowe, gdy:** znasz wersję PHP i dostępność SSH/Composera · subdomena odpowiada po HTTPS ·
baza istnieje · playground ma 3–5 Flow, w tym jeden celowo wadliwy.

---

# FAZA 1 — Szkielet aplikacji i deploy na produkcję

**Cel:** działający, zalogowany „pusty" dashboard pod `https://ftf.dobo.com.pl`.
Najważniejsza faza pod względem ryzyka.

### Pliki do utworzenia

- [ ] `public_html/index.php` — front controller, ładuje `../app/vendor/autoload.php`
- [ ] `public_html/.htaccess` — `RewriteRule ^ index.php [QSA,L]`
- [ ] `app/src/Http/Routes.php`, `app/src/Http/AuthMiddleware.php`
- [ ] `app/src/Support/Config.php` — odczyt `.env`
- [ ] `app/src/Support/Db.php` — PDO singleton
- [ ] `app/src/Support/Crypto.php` — AES-256-GCM, klucz `APP_KEY` z `.env` (do tokenów SF w Fazie 2)
- [ ] `app/db/migrations/001_init.sql` + `app/bin/migrate.php`
- [ ] `app/templates/layout.twig`, `login.twig`, `dashboard.twig`
- [ ] `deploy.md` — spisana procedura wgrywania

### Uwagi

- Logowanie: sesja PHP + `password_verify` na hashu w `users`. Jedno konto, bez rejestracji.
- Migracje: zwykłe pliki `.sql` uruchamiane przez `app/bin/migrate.php` z CLI albo wklejane
  w phpMyAdmin. Bez ORM-owej magii.
- `app/.htaccess` z `Require all denied` jako druga linia obrony, gdyby katalog jednak był serwowany.
- `deploy.md` powstaje **teraz**, nie później — za miesiąc nie będziesz pamiętał, co się gdzie wgrywa.

**Gotowe, gdy:** wchodzisz na `https://ftf.dobo.com.pl`, logujesz się, widzisz dashboard.
Deploy jest powtarzalny i opisany.

---

# FAZA 2 — OAuth do Salesforce i automatyczny inwentarz

**Cel:** lista wszystkich Flow z playgrounda wyświetlona w aplikacji.
To zastępuje ręczne wypełnianie arkusza „Flow Inventory".

- [ ] **Connected App** w playgroundzie: OAuth 2.0 Web Server Flow **z PKCE**,
      callback `https://ftf.dobo.com.pl/oauth/callback`, scopes: `api`, `refresh_token`, `offline_access`
- [ ] `app/src/Salesforce/OAuthService.php` — authorize → callback → wymiana kodu na tokeny →
      zapis **zaszyfrowanych** tokenów w `sf_connections`; automatyczny refresh przy `401` /
      `INVALID_SESSION_ID`
- [ ] `app/src/Salesforce/ApiClient.php` — cURL z retry i obsługą refreshu, wspólny dla REST i Tooling
- [ ] **Inwentarz Flow** — pobranie przez zwykły REST z `FlowDefinitionView` (obiekt widokowy,
      można pytać zbiorczo, tanio)
- [ ] Zapis do `flows` + widok listy z filtrem po typie i statusie

> ⚠️ **Zanim napiszesz zapytanie SOQL**, zawołaj `/services/data/v67.0/sobjects/FlowDefinitionView/describe`
> i sprawdź faktyczne nazwy pól w swojej wersji API. Dokumentacja Salesforce dla tego obiektu jest
> niekompletna i nie warto zgadywać.
> **Fallback**, gdyby coś nie grało: Tooling API
> `SELECT Id, DeveloperName, ActiveVersionId, LatestVersionId FROM FlowDefinition`.

**Gotowe, gdy:** po kliknięciu „Połącz org" i zalogowaniu do Salesforce widzisz tabelę swoich Flow —
nazwa, typ, obiekt, trigger, status, wersja.

---

# FAZA 3 — Metadane Flow i Flow Digest (serce jakości)

**Cel:** czytelna, skondensowana reprezentacja struktury Flow + deterministyczne wykrywanie ryzyk.
**Wciąż bez AI.**

> ⚠️ **Ograniczenie API, które kształtuje ten etap:** pole `Flow.Metadata` w Tooling API można pobrać
> **tylko gdy zapytanie zwraca maksymalnie jeden rekord** — inaczej dostajesz błąd. Import org to więc
> N+1 wywołań: jedno na listę, po jednym na każdy Flow.

- [ ] `app/src/Flow/MetadataFetcher.php` — per Flow:
      `SELECT Id, MasterLabel, ProcessType, Status, VersionNumber, Metadata FROM Flow WHERE Id = '...'`
      Zapis surowego JSON-a w `flow_versions.metadata_json` + `metadata_hash`.
      **Jeśli hash się nie zmienił, nie wołaj API ponownie** — chroni dobowy limit wywołań
      playgrounda (Developer Edition) i przyspiesza pracę.
- [ ] Import partiami z paskiem postępu odpytywanym AJAX-em
      (cron cyberfolks, limit 60 min/zadanie — zostaw na później, gdy pojawią się org z setkami Flow)
- [ ] **`app/src/Flow/DigestBuilder.php`** — najważniejszy plik w projekcie.
      Zamienia 100–300 KB surowego JSON-a na 2–5 KB konkretów:
  - [ ] typ i trigger: obiekt, before/after save, create/update/delete
  - [ ] entry criteria przetłumaczone na czytelne warunki
  - [ ] `decisions` — każda gałąź z warunkiem i tym, dokąd prowadzi
  - [ ] operacje DML: co tworzy / aktualizuje / usuwa i na jakim obiekcie
  - [ ] pętle — **i osobno flaga, czy DML znajduje się wewnątrz pętli**
  - [ ] dla każdego elementu: czy ma `faultConnector`, czy **nie ma**
  - [ ] ekrany, pola, walidacje (Screen Flow)
- [ ] **`app/src/Flow/RiskScanner.php`** — reguły deterministyczne, zero AI:
  - [ ] DML w pętli → ryzyko `Too many DML statements: 151`
        *(dokładnie ten błąd jest w DEF-003 w Twoim Defect Logu)*
  - [ ] element DML bez fault path → wprost generuje TC-015 z checklisty
  - [ ] Flow bez entry criteria na After Save → ryzyko rekursji (RT-004)
  - [ ] brak filtrów w `Get Records` → ryzyko pobrania nadmiaru rekordów

**Gotowe, gdy:** wybierasz Flow i widzisz jego czytelną strukturę oraz listę wykrytych ryzyk.
Na celowo zepsutym Flow z Fazy 0 **musi** zapalić się „DML w pętli".

---

# FAZA 4 — Warstwa AI: generowanie przypadków testowych

**Cel:** klikasz „Generuj testy" → dostajesz 15–30 konkretnych TC dla tego Flow.

- [ ] `composer require anthropic-ai/sdk`
- [ ] `app/src/Ai/Prompts/system_checklist.md` — część stała promptu
- [ ] `app/src/Ai/TestCaseGenerator.php`

### Kształt wywołania

```php
use Anthropic\Client;

$client = new Client(apiKey: Config::get('ANTHROPIC_API_KEY'));

$message = $client->messages->create(
    model: 'claude-opus-5',
    maxTokens: 16000,
    system: [[
        'type'         => 'text',
        'text'         => $stalyPromptZChecklista,   // Universal Checklist + reguły SF + format
        'cacheControl' => ['type' => 'ephemeral'],
    ]],
    messages: [['role' => 'user', 'content' => $flowDigestJson]],
);
```

### Cztery rzeczy, na których to stoi

- [ ] **Prompt caching.** Część stała — TC-001…TC-026, reguły Salesforce, definicja formatu wyjścia —
      ląduje w `system` z `cacheControl`. Zmienny Flow Digest idzie w `messages`, czyli **po** punkcie
      cache'owania. Minimalny cache'owalny prefiks to ~1024 tokeny — checklista z regułami spokojnie
      to przekracza. Weryfikacja: `$message->usage->cacheReadInputTokens` > 0 przy drugim wywołaniu.
- [ ] **Structured outputs** (`output_config.format` z JSON Schema) — wymuszają tablicę obiektów
      z polami `tc_code`, `category`, `title`, `steps[]`, `expected`, `priority`, `checklist_ref`.
      Bez parsowania markdownu.
- [ ] **Adaptive thinking** jest na Opus 5 domyślnie włączone — po prostu nie ustawiaj parametru
      `thinking`. **Nie przekazuj `budgetTokens`** — na tym modelu zwraca błąd 400.
- [ ] **Guard na odmowę:** sprawdzaj `$message->stopReason === 'refusal'` przed czytaniem treści.

### Mapowanie na framework

To jest to, co czyni z tego **akcelerator**, a nie generyczny generator:

- [ ] każdy wygenerowany TC ma `checklist_ref` wskazujący na konkretne TC-001…TC-026
- [ ] kod dostaje prefiks wg typu Flow zgodny z arkuszem „Test Cases": `RT-` / `SF-` / `SCH-` / `AL-`
- [ ] ryzyka z `RiskScanner` (Faza 3) wchodzą do promptu jako **obowiązkowe do pokrycia** — jeśli parser
      wykrył DML w pętli, model musi wygenerować test bulk na 200 rekordów

**Koszt** (Opus 5: $5 / $25 za 1M tokenów wej./wyj.): ok. 6K tokenów wejścia + 4K wyjścia na Flow
≈ **$0,13**, z cache'em taniej. Kilkanaście Flow to grosze — koszt AI nie jest w tym projekcie
czynnikiem ryzyka i nie ma powodu schodzić na słabszy model.

**Gotowe, gdy:** dla Record-Triggered Flow dostajesz TC pokrywające trigger, każdą gałąź Decision,
bulk 200 rekordów i brakujący fault path — z odwołaniami do checklisty.

---

# FAZA 5 — Edycja i eksport

**Cel:** domknięcie pętli — z org do gotowego pliku, który można oddać klientowi.

- [ ] **Edycja TC przed eksportem** — tester akceptuje, poprawia, dopisuje własne (`source = manual`)
  - To nie jest ozdobnik: wygenerowane testy muszą przejść przez człowieka, inaczej całość jest
    niewiarygodna jako narzędzie konsultanckie
- [ ] **`app/src/Export/XlsxExporter.php`** (`phpoffice/phpspreadsheet`) — generuje plik w układzie
      `SalesforcCloud_FTF.xlsx`: 6 arkuszy, te same nagłówki, ikony i kolejność.
      „Flow Inventory" i „Test Cases" wypełnione automatycznie, „Defect Log" i „Progress Tracker"
      puste i gotowe do pracy, z formułami.
- [ ] **Eksport PDF** — widok do druku (HTML + `@media print`), na demo i dla klienta

**Gotowe, gdy:** pobierasz .xlsx, otwierasz i wygląda jak Twój framework — tylko wypełniony konkretami
z org zamiast przykładami.

---

# FAZA 6 — Walidacja pomysłu (Ideal validation)

To jest „punkt drugi" z Twojego zgłoszenia. Bez tego cała reszta jest tylko kodem.

- [ ] Uruchom na 3–5 realnych Flow. Dla każdego napisz testy **też ręcznie**, po swojemu
- [ ] Zmierz i zapisz: czas ręcznie vs. czas w aplikacji · ile TC trafionych · ile bezużytecznych ·
      **ile brakujących** ← to najważniejsza liczba, pokazuje czy narzędziu można ufać
- [ ] Zbierz materiał do zgłoszenia №00001131: demo 3 min + konkretne liczby
  - Argument „4 godziny → 2 minuty" działa tylko poparty pomiarem
- [ ] **Dopiero teraz** decyzja o kolejnym kroku: multi-tenant · Einstein/Agentforce zamiast Claude API
      (dla klientów wrażliwych na wyjście danych poza org) · pełny menedżer testów

---

## Ryzyka i jak je adresujemy

| Ryzyko | Kiedy wyjdzie | Reakcja |
|---|---|---|
| Brak Composera / za stare PHP na cyberfolks | Faza 0 | `vendor/` budowany lokalnie, wgrywany FTP |
| `Flow.Metadata` = 1 rekord na zapytanie | Faza 3 | cache po `metadata_hash`, import partiami |
| Limit wywołań API playgrounda (Developer Edition) | Faza 3 | ten sam cache; nie odpytuj niezmienionych Flow |
| Uprawnienia do Tooling API na Flow | Faza 2 | w playgroundzie jesteś adminem; u klienta wymagać „View All Data" / „Manage Flow" |
| Wyciek sekretów (`ANTHROPIC_API_KEY`, refresh token SF) | Faza 1 | `.env` poza `public_html` + `Require all denied`; tokeny SF szyfrowane AES-256-GCM |
| Wygasły refresh token / rozłączona org | Faza 2 | czytelny komunikat i przycisk ponownej autoryzacji, nie cichy błąd 500 |
| Model generuje ogólniki zamiast konkretów | Faza 4 | jakość zależy od Flow Digest, nie od promptu — dlatego Faza 3 jest przed Fazą 4 |

---

## Weryfikacja end-to-end

Test akceptacyjny całości, do przejścia po Fazie 5:

1. `https://ftf.dobo.com.pl` → logowanie → dashboard
2. „Połącz org" → OAuth do playgrounda → powrót z aktywnym połączeniem
3. Lista Flow zgadza się z **Setup → Process Automation → Flows** w org (nazwy, typy, statusy)
4. Wybór Flow → import metadanych → widoczna struktura + wykryte ryzyka
5. Na celowo zepsutym Flow z Fazy 0: `RiskScanner` **musi** zgłosić „DML w pętli" i „brak fault path"
6. „Generuj testy" → 15–30 TC z odwołaniami do TC-001…TC-026
7. Drugie generowanie z rzędu: `usage->cacheReadInputTokens > 0` — sprawdzalne w `generation_runs`
8. Edycja jednego TC → eksport .xlsx → plik otwiera się i ma układ frameworku
9. Rozłącz org, spróbuj generować → czytelny komunikat, nie błąd 500

---

## Materiały źródłowe

| Plik | Rola |
|---|---|
| `Chcę zgłosić swój pomysł na stoworzenie.odt` | zgłoszenie pomysłu, idea №00001131 |
| `SalesforcCloud_FTF.xlsx` | **treść merytoryczna** — 6 arkuszy, TC-001…TC-026, przypadki per typ Flow |
| `Sales Cloud Flow Testing Framework.odp` | szkic prezentacji (slajdy 1–3) |
| `Salesforce Go with the Flow Ebook - PL 2.2025.pdf` | materiał referencyjny o Flow |
