# Flownatic — kontekst projektu

> **Do czego służy ten plik.** Jest to briefing na zimny start. Jeśli sesja została przerwana,
> kontekst skompaktowany albo wracasz po tygodniu — przeczytaj ten plik od góry do dołu, a będziesz
> wiedział, gdzie jesteśmy i dlaczego. Aktualizujemy go **na końcu każdej fazy** oraz **zawsze, gdy
> zapadnie decyzja projektowa** albo **gdy coś okaże się inne, niż zakładaliśmy**.

**Ostatnia aktualizacja:** 2026-09-08 · **Aktualny stan:** Fazy 1–5 zamknięte, POC domknięty — Faza 6 (pomiar) odłożona na później

---

## 1. Czym jest ten projekt

**Flownatic** — akcelerator do manualnego testowania Salesforce Flow, budowany jako aplikacja webowa.

**Problem.** Istnieje gotowy framework w Excelu (`SalesforcCloud_FTF.xlsx`, 6 arkuszy, 26 uniwersalnych
przypadków TC-001…TC-026). Jest statyczny i generyczny. Tester i tak musi ręcznie spisać wszystkie Flow
z org, przeczytać każdy w Flow Builderze, zrozumieć jego Decisions/Loops/DML i dopiero wtedy przepisać
ogólne TC na konkretne kroki. To 2–4 godziny na jeden Flow, powtarzane na każdym projekcie od zera.

**Rozwiązanie.** Aplikacja podłącza się do org przez API, sama czyta strukturę Flow i zamienia generyczną
checklistę w konkretne, wykonalne przypadki testowe dla tego konkretnego Flow — z eksportem do Excela
w formacie frameworku. Framework zostaje sensem merytorycznym; aplikacja robi żmudną część.

**Pochodzenie.** Pomysł zgłoszony wewnętrznie w firmie jako **idea №00001131**. Wcześniejsze zgłoszenie
(№00001130, dotyczyło Copado) pozostało bez odpowiedzi i jest nieaktualne. Celem Fazy 6 jest zebranie
twardych liczb do obrony tego zgłoszenia.

---

## 2. Decyzje projektowe i ich uzasadnienie

| Decyzja | Wybór | Dlaczego |
|---|---|---|
| Backend | **PHP 8.4 + MySQL** | Natywne dla cyberfolks. Node.js wymagałby ręcznego `nohup` i proxy w `.htaccess`, bez PM2 i bez wsparcia hostingu |
| Framework | **Slim 4** (nie Laravel) | Na współdzielonym hostingu Laravel to walka z document rootem i `artisan`, bez korzyści w zamian |
| Generator TC | **Reguły deterministyczne, bez płatnego API** | Decyzja z 2026-09-07: projekt ma działać bez kosztów. Subskrypcji Claude nie da się podpiąć pod wywołania API — to osobne rozliczenia. Prozę modelu odzyskuje most przez schowek. Szczegóły w sekcji 7 |
| Zakres MVP | **Analizator Flow → generator TC** | To sedno pomysłu i to, czego Excel nie potrafi. Pełny menedżer testów byłby głównie przepisaniem arkusza |
| Użytkownicy | **Jeden, jedna org** | POC. Bez `tenant_id`, bez izolacji — najszybsza droga do walidacji |
| Kolejność | **Deploy w Fazie 1, nie na końcu** | Ryzyko hostingowe najgorzej odkrywać po trzech tygodniach kodowania. Callback OAuth i tak wymaga publicznego HTTPS już w Fazie 2 |
| Adres produkcyjny | **`dobo.com.pl/ftf/`, nie subdomena** | Subdomena i podkatalog to fizycznie ten sam katalog, więc subdomena nie daje izolacji. Podkatalog ma ważny, publicznie zaufany certyfikat od ręki — subdomena nie ma żadnego. Decyzja z 2026-08-27 |
| Kolejność | **Parser (Faza 3) przed generatorem (Faza 4)** | Deterministyczny parser robi to, co musi być powtarzalne. **Ta kolejność uratowała projekt 2026-09-07**: gdy zapadła decyzja o zerowym koszcie, nie trzeba było wyrzucić niczego — wartość stała poza AI, a Faza 4 miała gotowy wsad |

---

## 3. Infrastruktura — stan faktyczny

**Hosting:** cyberfolks, pakiet **cyber_SPRINT**, panel **DirectAdmin**
**Konto:** `qekbnopwvk` · **Serwer:** `s65.cyber-folks.pl` (185.208.164.165) · **Katalog domowy:** `/home/qekbnopwvk`

| Element | Stan |
|---|---|
| PHP | **8.4.24**, LiteSpeed, Linux (2026-08-27 było 8.4.21 — hosting podbija sam) |
| Rozszerzenia | Wszystkie wymagane obecne, w tym krytyczny **`zip`** (przesądza o Fazie 5) |
| `max_execution_time` | **180 s** — twarde ograniczenie, patrz sekcja 4 |
| `memory_limit` | 128 MB |
| `disable_functions` | `exec, shell_exec, system, proc_open, popen, symlink, link`… → **brak powłoki** |
| Domeny | `domains/dobo.com.pl`, `domains/qekbnopwvk.cfolks.pl` |
| **Adres produkcyjny** | **`https://dobo.com.pl/ftf/`** — ważny certyfikat, działa. Katalog: `domains/dobo.com.pl/public_html/ftf/` |
| `ftf.dobo.com.pl` | Subdomena istnieje od 2026-08-27, ale **nie jest adresem produkcyjnym** — brak certyfikatu obejmującego tę nazwę |
| `ftp.dobo.com.pl` | Utworzona omyłkowo, **subdomena skasowana 2026-08-27** (vhost zwraca 403). Osierocony katalog `public_html/ftp/` czeka na usunięcie |
| **Playground Salesforce** | `resilient-narwhal-j9207g-dev-ed.trailblaze.my.salesforce.com` |
| API Salesforce | do **v67.0 (Summer '26)**, 37 wersji — zgodne z założeniem `plan.md` |
| Baza MySQL | `qekbnopwvk_flownatic`, MariaDB 10.6.27, **utf8mb4_unicode_ci**, pusta. Dane w `%USERPROFILE%\.flownatic-db.txt` |
| `dobo.com.pl` | Strona-wizytówka Flownatic (`site/index.html`). Oryginalna reklama hostingu zachowana jako `index-hosting-oryginal.html` |

**Dostęp do serwera — ważne.** SSH jest włączone na koncie (port 222), ale **nie działa z firmowego
laptopa**: połączenie przerywa się błędem `Corrupted MAC on input` jeszcze przed pytaniem o hasło —
ruch jest najpewniej modyfikowany przez firmowe zabezpieczenia sieci. Zmiana pakietu hostingowego tego
**nie naprawi**, bo problem jest na drodze sieciowej, nie po stronie serwera.

**Działającą drogą jest FTPS** (port 21 + TLS) przez `tools/deploy.ps1`:

```powershell
.\tools\deploy.ps1 -Test                                    # sprawdzenie połączenia
.\tools\deploy.ps1 -ListPath "domains/dobo.com.pl/public_html/"
.\tools\deploy.ps1 -LocalFile .\site\index.html -RemotePath "domains/dobo.com.pl/public_html/"
.\tools\deploy.ps1 -LocalDir .\public_html -RemotePath "domains/dobo.com.pl/public_html/ftf/"
.\tools\deploy.ps1 -DeleteRemote "domains/x/public_html/plik.php"
.\tools\deploy.ps1 -RemoveDir "domains/x/public_html/stare"          # pokazuje plan, nie kasuje
.\tools\deploy.ps1 -RemoveDir "domains/x/public_html/stare" -Force   # wykonuje
.\tools\deploy.ps1 -UploadZip .\app\vendor -RemotePath "flownatic-app/vendor"   # katalog -> zip -> rozpakowanie na serwerze
.\tools\deploy.ps1 -RenameFrom "sciezka/a" -RenameTo "sciezka/b"
```

**DNS — lokalnemu resolverowi nie ufać.** Firmowa sieć przechwytuje DNS: `nslookup` odpowiada z
`127.0.0.1` i zwraca ten sam adres AWS (`35.168.95.233`) dla **każdej** nazwy, także nieistniejącej —
ignoruje nawet jawnie wskazany serwer. Do weryfikacji używać DNS-over-HTTPS:

```bash
curl -s -H 'accept: application/dns-json' "https://cloudflare-dns.com/dns-query?name=ftf.dobo.com.pl&type=A"
```

Google (`https://dns.google/resolve?name=NAZWA&type=A`) też działa i nie wymaga nagłówka, ale cache'uje
NXDOMAIN na czas `SOA minimum`, czyli **3600 s**. Jeśli pytałeś o nazwę **przed** jej utworzeniem, przez
godzinę będzie zwracał NXDOMAIN mimo istniejącego rekordu — wtedy pytaj Cloudflare.

**Sprawdzenie vhosta z pominięciem DNS** (działa nawet przy zatrutym cache — testuje sam serwer):

```bash
curl -s -o /dev/null -w "HTTP %{http_code}" --resolve "ftf.dobo.com.pl:80:185.208.164.165" "http://ftf.dobo.com.pl/"
```

**Sekrety.** Dane FTP leżą w `%USERPROFILE%\.ftp-dobo.txt` — **poza repozytorium**. Skrypt przekazuje
hasło curl-owi przez tymczasowy `.netrc`, więc nie pojawia się ani w rozmowie, ani w liście procesów.
Klucz SSH `%USERPROFILE%\.ssh\cyberfolks_dobo` został wygenerowany, ale jest bezużyteczny (patrz wyżej).

---

## 4. Ograniczenia, o których nie wolno zapomnieć

1. **`max_execution_time = 180 s`** — import wszystkich Flow jednym żądaniem urwie się w połowie
   i zostawi bazę w stanie częściowym. Partie (~5 Flow na żądanie) są **wymogiem, nie optymalizacją**.
2. **`Flow.Metadata` w Tooling API — tylko jeden rekord na zapytanie.** Import org to N+1 wywołań.
   Stąd cache po `metadata_hash` i wznawialność importu.
3. **Brak powłoki na serwerze** — Composer uruchamiamy lokalnie, `vendor/` wgrywamy przez FTP.
4. **Limit API playgrounda** (Developer Edition) — nie odpytywać niezmienionych Flow.
5. **`DOCUMENT_ROOT` raportuje `private_html`**, choć pliki idą do `public_html`. Nie polegać na
   `$_SERVER['DOCUMENT_ROOT']` — używać `__DIR__`.
6. ~~Na Opus 5 nie ustawiać `budgetTokens` ani `thinking`~~ — **nieaktualne od 2026-09-07**: aplikacja nie woła płatnego API. Zostawione, bo wróci, gdyby kiedyś doszedł `ApiGenerator`.
7. **DocumentRoot subdomeny leży WEWNĄTRZ `public_html` domeny głównej** —
   `domains/dobo.com.pl/public_html/ftf/`, a nie `domains/ftf.dobo.com.pl/`, jak zakładał `plan.md`.
   DirectAdmin na cyberfolks tak właśnie zakłada subdomeny. **Konsekwencja bezpieczeństwa:** katalog
   `app/` postawiony obok document roota byłby dostępny z sieci razem z `.env` (klucz Anthropic,
   `APP_KEY`, hasło do bazy). Dlatego `app/` idzie do **`~/flownatic-app/`**, poza `domains/`.
   `public_html/index.php` szuka autoloadera po kolei: najpierw `__DIR__/../app` (układ lokalny),
   potem `dirname(__DIR__, 4) . '/flownatic-app'` (układ serwera) — dzięki temu ten sam kod
   działa lokalnie i na produkcji.
   **Drugi skutek:** aplikacja jest dostępna pod dwoma adresami — `dobo.com.pl/ftf/` oraz
   `ftf.dobo.com.pl`. Ciasteczka sesji nie są między nimi współdzielone, a callback OAuth z Fazy 2
   dopasowuje się dokładnie, więc `public_html/.htaccess` musi robić kanoniczne 301
   **na `dobo.com.pl/ftf/`** — to on jest adresem produkcyjnym, bo tylko on ma ważny certyfikat.

---

## 5. Repozytorium i pliki

**Repo:** https://github.com/MorawskiR/accelerator — gałąź `main`
⚠️ **Repozytorium jest PUBLICZNE.** Rafał został o tym poinformowany i świadomie zdecydował o publikacji
mimo że zawiera dokumenty firmowe. **Konsekwencja: nigdy nie commitować `.env`** — boty skanujące GitHuba
znajdują wyciekniętý klucz API w kilkanaście sekund. `.gitignore` to blokuje, ale trzeba pilnować.

```
POC/
├── Dev/
│   ├── plan.md        ← plan z podziałem na 7 faz, architektura, uzasadnienia
│   ├── task.md        ← operacyjny tracker zadań (tu odznaczamy postęp)
│   └── kontekst.md    ← ten plik
├── site/index.html    ← strona-wizytówka na dobo.com.pl
├── tools/
│   ├── deploy.ps1     ← wgrywanie przez FTPS
│   └── _check.php     ← diagnostyka PHP (wgrywana tymczasowo, potem kasowana)
├── SalesforcCloud_FTF.xlsx                      ← TREŚĆ MERYTORYCZNA frameworku
├── Chcę zgłosić swój pomysł na stoworzenie.odt  ← zgłoszenie №00001131
├── Plan projektu.odt                            ← jeszcze nieprzeczytany
└── Sales Cloud Flow Testing Framework.odp       ← prezentacja (5 MB)
```

Ebook `Salesforce Go with the Flow Ebook - PL 2.2025.pdf` (9,8 MB) jest w `.gitignore` — materiał
publiczny, nie nasze autorstwo.

---

## 6. Jak pracujemy

- **Jedna faza naraz, punkt po punkcie.** Nie zaczynamy kolejnej fazy, dopóki poprzednia nie przejdzie
  swojego kryterium „Gotowe, gdy". Rafał wyraźnie o to poprosił.
- **Język:** polski, w rozmowie i w dokumentacji projektu.
- **Commity:** jeden na ukończony punkt z `task.md`, po polsku, wypychane na bieżąco.
- **Gałęzie:** `feature/*` → `uat` (regresja na `qekbnopwvk.cfolks.pl`) → `main` (produkcja na
  `dobo.com.pl/ftf/`). Po zamknięciu fazy gałąź feature kasujemy i otwieramy nową.
  Pełny proces i definicja regresji: **[`git-workflow.md`](git-workflow.md)**.
- **Podział ról:** 🔵 panel hostingu, Salesforce, konta i klucze — po stronie Rafała.
  🟢 kod, skrypty, deploy, dokumentacja — po stronie Claude (przez FTPS ma dostęp do serwera).
- **Hasła nigdy nie trafiają do rozmowy** — zawsze przez plik poza repozytorium albo wpisywane
  bezpośrednio przez Rafała.

---

## 7. Gdzie jesteśmy i co dalej

**Stan na koniec sesji 2026-09-07.**
**Faza 0 ✅ · Faza 1 ✅ · Faza 2 ✅ · Faza 3 ✅ ZAMKNIĘTA.**
Gałąź: `feature/faza-3-widok` (wyszła z `feature/faza-3-flow-digest`, zawiera całą jej historię).
Kod: 15 klas, ~2600 linii w `app/src/`.

### Aplikacja działa na produkcji i czyta prawdziwą org

**https://dobo.com.pl/ftf/** → logowanie → Flow → **Połącz z Salesforce** → **Pobierz Flow**.
Import zwraca **9 Flow** (z 79 w org; filtr odsiewa pakiety zarządzane i szablony).
Dane logowania: `%USERPROFILE%\.flownatic-login.txt`.

⚠️ Firmowa sieć blokuje `dobo.com.pl` — testować z telefonu albo `curl --resolve`.

### Faza 3 — rdzeń gotowy i przetestowany na realnych danych

| Klasa | Rola |
|---|---|
| `Flow\MetadataFetcher` | N+1 wywołań (API inaczej nie pozwala), cache dwustopniowy, partie po 5 |
| `Flow\DigestBuilder` | 415 linii, **4375 B → 1180 B** na realnym Flow |
| `Flow\RiskScanner` | cztery reguły deterministyczne, zero AI |

**Na `RT- Flownatic_Bad_Example` wykryte 5 ryzyk:** 3 wysokie, 2 średnie —
DML w pętli (×2), brak fault path (×2), After Save bez kryteriów wejścia.

**Najważniejsza decyzja projektowa:** wykrywanie DML w pętli robi **przejście grafu**
od `nextValueConnector`, a nie zliczanie elementów. Naiwne „Flow ma pętlę i ma DML"
zgłaszałoby poprawne Flow jako błędne. Osobny test na fałszywy alarm: Flow z DML
**po** pętli, z fault path i kryteriami → **zero ryzyk**.

### Widok Flow — gotowy 2026-09-07

`GET /flows/{id}` pokazuje **najpierw ryzyka, potem strukturę** — bo ryzyka są powodem, dla
którego tester tu wchodzi, a struktura jest dowodem, skąd się wzięły. Lista Flow ma teraz link
w nazwie i kolumnę z licznikami ryzyk.

**Odkryta luka, którą trzeba było domknąć:** `MetadataFetcher` zapisywał wyłącznie surowe
metadane i przy każdej zmianie zerował `digest_json` / `risks_json` — a **nic ich nie liczyło**.
Digest i ryzyka nie trafiały do bazy w ogóle. Domyka to `Flow\FlowAnalyzer`:

- **liczy leniwie**, przy oglądaniu, a nie przy imporcie — import ma twarde 180 s i każda
  sekunda w nim jest droga, a digest to czysty PHP bez wywołań API, więc jest tani;
- **ale zapisuje**, bo Faza 4 wysyła digest do modelu, a Faza 5 eksportuje ryzyka do .xlsx —
  obie muszą dostać dokładnie to, co tester zobaczył na ekranie;
- `podsumowania()` dolicza brakujące przy wejściu na listę (limit 50 na żądanie).

`MetadataFetcher::pobierzJeden()` pobiera metadane jednego Flow z pominięciem kolejki partii —
tester klika w konkretny Flow i nie ma powodu czekać na całą kolejkę.

Teksty ryzyk dostały polskie znaki: idą wprost na ekran, a w Fazie 5 do eksportu .xlsx.

**Weryfikacja bez bazy i bez org:** `php tests/widok-flow.php` renderuje `flow.twig` na czterech
fixture'ach. Na `bad-example.json` (realne metadane `RT- Flownatic_Bad_Example`) widać oba ryzyka
z kryterium fazy, a na `po-petli.json` i `czysty.json` **zero fałszywych alarmów**. Podgląd HTML
ląduje w `tests/out/` (poza repo).

### Import metadanych partiami z paskiem postępu — gotowy 2026-09-07

Trzy trasy zamiast jednej, bo **formularz musi działać bez JavaScriptu**:
`POST /flows/metadane` robi jedną partię i wraca z komunikatem, `POST /flows/metadane/partia`
robi to samo w JSON-ie, a `GET /flows/metadane/stan` zwraca stan kolejki. Skrypt na liście Flow
tylko przejmuje ten formularz i klika go w pętli, rysując pasek.

Dlaczego tak, a nie jedno długie żądanie: `max_execution_time` to 180 s, a import to N+1 wywołań
API. Każda partia jest osobnym żądaniem, więc żadne nie zbliża się do limitu.

**Wznawialność wychodzi z bazy, nie z sesji.** Kolejkę wyznacza `MetadataFetcher::oczekujace()`
— Flow bez zapisanej wersji albo zmienione po ostatnim pobraniu. Zamknięcie karty w połowie
niczego nie psuje: kolejne wejście podejmuje od miejsca zatrzymania.

**Zatrzymanie na braku postępu.** Partia, w której ani jeden Flow się nie pobrał, kończy pętlę
zamiast powtarzać ten sam błąd do wyczerpania licznika — inaczej zerwana org albo wygasły token
zjadałyby limit API playgrounda 200 razy pod rząd.

`oczekujace()`, `ileOczekuje()` i nowe `stanKolejki()` są statyczne: liczenie kolejki nie dotyka
API, więc lista Flow nie musi budować klienta Salesforce (a więc i odświeżać tokenu) tylko po to,
żeby pokazać licznik.

### ✅ FAZA 3 ZAMKNIĘTA — 2026-09-07

Wszystkie 8 punktów odhaczonych, **kryterium „Gotowe, gdy" potwierdzone w przeglądarce na
produkcji**: `RT- Flownatic_Bad_Example` pokazuje **„Wykryte ryzyka (5) — 3 × wysokie,
2 × średnie"**. Te liczby składają się tylko w jeden sposób — DML w pętli ×2 i After Save bez
kryteriów (wysokie) plus brak fault path ×2 (średnie) — bo reguła After Save zgłasza najwyżej
jedno ryzyko na Flow.

Analizator działa więc od początku do końca na żywej org, **bez jednej linijki AI**. To był cały sens
kolejności: Faza 4 dostaje mały, czysty opis zamiast surowego JSON-a.

**Regresja:** R6 i R7 potwierdzone ręcznie. **R8 czeka** — wznawialność jest zaimplementowana,
ale nie była przeklikana (zamknąć kartę w trakcie importu, wejść ponownie, sprawdzić, czy pasek
podejmuje od miejsca zatrzymania i czy liczba Flow się nie zmienia).

### Silnik A Fazy 4 działa na produkcji — 2026-09-07

`GET /flows/{id}` ma sekcję **Przypadki testowe** i przycisk „Generuj testy”.
`TemplateGenerator` instancjonuje checklistę nazwami z digestu; na realnym
`RT- Flownatic_Bad_Example` wychodzi **16 przypadków**. Zero wywołań płatnego API.

**Kluczowa zmiana porządkowa: `Generator\Framework`.** Kody TC-001…TC-026 i przypadki per typ
Flow są przepisane z arkusza do kodu i weryfikowalne metodą `znany()`. Do tej pory wpisywaliśmy je
z pamięci — i tak powstał błąd, który ta zmiana naprawia: `RiskScanner` odsyłał „Get Records bez
filtrów” do **TC-020**, czyli do profilu użytkownika standardowego. Właściwy kod to **TC-010**.
Potwierdzone na produkcji: ryzyka zwracają dziś TC-018, TC-015, RT-004, TC-010.

**Gdzie żyją przypadki:** generator jest czystą funkcją i nie dotyka bazy (zero `Db::`), a zapisem
zajmuje się `TestCaseRepository` → tabela `test_cases`, wpięta w **`flow_version_id`**, nie w `flow_id`.
Ponowne generowanie nadpisuje wyłącznie w obrębie tego samego `source`, więc dopiski `manual` przetrwają.

**Luka znaleziona przy okazji i naprawiona:** zmiana Flow w org zerowała digest i ryzyka, ale
`test_cases` zostawały i po cichu opisywały poprzednią wersję. Widok pokazuje teraz baner
z obiema datami. Sygnałem jest `digested_at`, **nie** `fetched_at` — to drugie odświeża się także
przy metadanych bez zmian i fałszywie unieważniałoby listę po każdym pobraniu.

Wgrane 7 plików, bez migracji — `test_cases` istnieje od `001_init.sql`. Diagnostyka na produkcyjnym
PHP 8.4.24 potwierdziła: klasy się ładują, 15 przypadków na testowym Flow, **zero nieznanych odwołań**.

**Silnik B też działa na produkcji — 2026-09-07, 18:30.** `PromptBuilder` składa gotowy prompt
(checklista + digest + ryzyka + format), przycisk kopiuje go do schowka, a `ClipboardImporter`
przyjmuje odpowiedź z powrotem. Importer jest odporny na to, co człowiek naprawdę wkleja: JSON
w płotku markdown, JSON po zdaniu wstępnym modelu, opakowanie w obiekt, priorytety po angielsku.
Nieznany `checklist_ref` podmienia na kod ogólny zamiast odrzucać przypadek — jedna literówka
modelu nie może kasować wklejonej pracy.

### ⏸️ Faza 6 odłożona — decyzja z 2026-09-08

**Działa, więc zostaje jak jest.** Pomiar do zgłoszenia №00001131 robimy później — najlepiej
przy pierwszym realnym projekcie z Flow klienta, bo liczby z prawdziwej org broną pomysłu
mocniej niż te z playgrounda Developer Edition.

⚠️ **Co to znaczy przy prezentowaniu narzędzia:** argument „4 godziny → 2 minuty” jest na razie
**twierdzeniem, nie pomiarem**. Protokół czeka gotowy w `Dev/walidacja-pomiar.md` — definicje
ustalone przed pomiarem, zasada „najpierw ręcznie”, tabela i rejestr braków.

**Stan POC: domknięty funkcjonalnie.** Pełna pętla od inwentarza po plik .xlsx, koszt działania
0 USD, wszystko na produkcji, Fazy 1–5 otagowane.

### ✅ FAZA 5 ZAMKNIĘTA — 2026-09-08

**Akcelerator robi pełną pętlę: z org do pliku, który można oddać klientowi.**
Inwentarz → metadane → digest → ryzyka → przypadki → przegląd przez człowieka → eksport
w układzie frameworku albo wydruk roboczy z kratkami na wynik.

Trzy decyzje z tej fazy, których nie widać w kodzie na pierwszy rzut oka:

- **Układ .xlsx odczytany z oryginalnego arkusza**, nie wymyślony — kolory (`1F3864`, `2E75B6`,
  `DEEAF1`, `FFF2CC`), szerokości kolumn i treść od kolumny B. Test **otwiera zbudowany plik
  ponownie**, bo plik, którego nie da się odczytać, jest bezwartościowy.
- **Odrzucone przypadki nie trafiają ani do .xlsx, ani na wydruk** — warunek stoi w dwóch
  miejscach niezależnie, bo plik idzie do klienta.
- **Edycja nie zmienia źródła przypadku.** Poprawiony przypadek z reguł nadal jest z reguł
  i zostanie nadpisany — inaczej jedna literówka zamrażałaby go na zawsze, a lista przestawałaby
  odzwierciedlać metadane. Kto chce trwałej wersji, dopisuje własny (`source = manual`).

**PDF przez przeglądarkę**, bez dokładania biblioteki — wynik ten sam, o jedną zależność mniej
do wgrywania przez FTP.

### ✅ FAZA 4 ZAMKNIĘTA — 2026-09-07

Kryterium potwierdzone na produkcji: kliknięcie „Generuj testy” na `RT- Flownatic_Bad_Example`
zwróciło **„Wygenerowano 16 przypadków testowych. Dopiski własne pozostały nietknięte.”** — liczba
zgadza się z lokalnym testem, a druga część komunikatu dowodzi, że nadpisywanie działa per źródło.

**Git uporządkowany tego samego dnia.** `main` (379b92b) ma po raz pierwszy komplet Faz 1–4
i odpowiada temu, co działa na produkcji. Zdalnie zostały dwie gałęzie — `main` i `uat` — plus
tagi `faza-3` i `faza-4`. Siedem gałęzi feature skasowanych.

**Następna faza: 5 — edycja przypadków i eksport do .xlsx** w układzie sześciu arkuszy frameworku.
Gałąź `feature/faza-5-eksport-xlsx` wychodzi z czystego `main`.

### Zanim ruszy Faza 4 — trzy rzeczy do uprzątnięcia

1. 🔵 **Poskładać gita:** `uat` ← `feature/faza-3-widok`, `main` ← `uat`, tag `faza-3`, potem
   skasować gałąź feature. Dziś `main` nie ma kodu, a produkcja żyje z gałęzi feature.
2. 🔵 **Postawić UAT** (`deploy.md`, sekcja 8) — pominięcie go było świadomym jednorazowym
   wyjątkiem, nie zmianą procesu. Faza 4 wprowadza koszty API, więc regresja na produkcji
   przestaje być tania.
3. ~~Doładować konto Anthropic~~ — **anulowane 2026-09-07.** Faza 4 została przeredagowana tak,
   żeby obyć się bez płatnego API. Nic tu nie blokuje.

### Faza 4 bez kosztów — decyzja z 2026-09-07

Rafał: **projekt ma działać bez kosztów, subskrypcja powinna wystarczyć.**

⚠️ **Rzecz, do której nie warto wracać:** subskrypcja Claude i kredyty API to **dwa osobne
rozliczenia**. Aplikacja PHP wołająca `/v1/messages` obciąża kredyty API niezależnie od tego,
czy uwierzytelni się kluczem, czy profilem OAuth — subskrypcji nie da się pod to podpiąć.
Wybór był binarny: kredyty albo brak wywołań z serwera.

Dla porządku, cennik sprawdzony tego dnia (`claude-opus-5`: $5/$25 za 1M tokenów wej./wyj.):
realny koszt to **0,15–0,25 USD za Flow** — nie 0,09, jak zakładał stary `task.md`, bo tamten
szacunek pomijał tokeny myślenia. Cała Faza 4 wyszłaby na 10–20 USD. Kwota nieduża, ale decyzja
brzmi „zero", więc plan idzie w tę stronę.

**Nowy kształt Fazy 4: dwa źródła TC wpięte w jeden interfejs `TestCaseSource`.**

- **`TemplateGenerator`** — domyślny, deterministyczny, zero kosztów. Instancjonuje checklistę
  TC-001…TC-026 nazwami z digestu: operacje wyzwalacza, każda gałąź decyzji z warunkiem, bulk 200
  przy DML w pętli, wymuszony błąd przy braku fault path, wolumen przy `Get Records` bez filtrów.
- **Most przez schowek** — „Kopiuj prompt" → Claude.ai albo Claude Code (pokryte subskrypcją) →
  „Wklej wynik" z walidacją schematu. Odzyskuje prozę modelu, gdy potrzebna jest na demo.
- Miejsce na `ApiGenerator` zostaje w interfejsie, gdyby kiedyś pojawiły się kredyty.

**Kryterium „Gotowe, gdy" Fazy 4 nie zmieniło się ani o słowo** — i to jest najlepszy dowód, że
kolejność Faz 3→4 była dobra. Digest ma wszystko, czego generator potrzebuje; gdyby projekt stał
na wrzucaniu surowego JSON-a do modelu, ta decyzja kosztowałaby przepisanie połowy aplikacji.

**Kompromis, o którym trzeba wiedzieć:** przypadki z reguł są poprawne, ale sztampowe językowo.
Model pisze kroki naturalniej. Most przez schowek to odzyskuje, kosztem dwóch `Ctrl+V`.

**Deploy wykonany 2026-09-07 — produkcja ma Fazę 3.** Decyzja Rafała: **pomijamy UAT ten jeden
raz**, żeby zobaczyć efekt od razu. UAT stawiamy przed Fazą 4 — procedura i pułapki czekają
w `deploy.md`, sekcja 8.

Wgrane 9 plików w kolejności bezpiecznej dla żądań w locie: najpierw cztery klasy `Flow/` (same
dodatki, nic ich jeszcze nie woła), potem trzy szablony, na końcu `Routes.php` i `index.php`.
Sprawdzone po wdrożeniu: `/ftf/flows/1` zwraca **302 na login** zamiast 404, a diagnostyka na
produkcyjnym **PHP 8.4.24** (lokalnie mamy 8.3) potwierdziła, że klasy się ładują, cache Twiga
jest zapisywalny, a silnik zwraca `dml_w_petli`, `dml_bez_fault_path` i `after_save_bez_kryteriow`.
Plik diagnostyczny skasowany zaraz po odczycie.

⚠️ **Git rozjechał się z rzeczywistością i trzeba to poskładać.** `main` **nie ma ani jednej
linijki kodu aplikacji** (57 commitów za gałęzią feature), `uat` ma tylko dokument o gałęziach.
Cały kod z Faz 1–3 żyje wyłącznie na `feature/faza-3-widok` — a to on stoi na produkcji.
Do zrobienia 🔵: `uat` ← `feature/faza-3-widok`, potem `main` ← `uat`, tag `faza-3`. Bez tego
`main` przestaje znaczyć „stabilna wersja", a przy kolejnej fazie nie będzie z czego wychodzić.

### Stan produkcji przed tym deployem (dla porządku)

⚠️ **Produkcja stała na stanie z 31 sierpnia (koniec Fazy 2).** Sprawdzone 2026-09-07 przez FTP
i `curl`: zdalny `flownatic-app/src/Flow/` zawiera **wyłącznie `FlowImporter.php`**, brak
`flow.twig` w szablonach, `GET /ftf/flows/1` → **404**. Czyli nie tylko dzisiejsza praca, ale
**cała Faza 3 z 1 września nigdy nie została wgrana**. `/ftf/health` odpowiada, baza `tak`,
PHP na produkcji to dziś **8.4.24** (kontekst mówił 8.4.21 — wersja podskoczyła sama).

Do wgrania jest 8 plików: `src/Flow/{MetadataFetcher,DigestBuilder,RiskScanner,FlowAnalyzer}.php`,
`src/Http/Routes.php`, `templates/{flow,flows,layout}.twig`. **Migracja bazy nie jest potrzebna** —
`digest_json`, `risks_json` i `digested_at` istnieją od `001_init.sql`, tylko dotąd nikt ich nie
wypełniał. `vendor/` bez zmian.

### Do zrobienia po stronie Rafała 🔵

**Dodać do `RT- Flownatic_Bad_Example` drugi `Get Records` bez żadnych filtrów.**
Obecny (`Get_Related_Contact`) ma filtr `AccountId = $Record.Id`, więc czwarta reguła
nie ma na czym się zapalić na realnym Flow. Przetestowana jest tylko syntetycznie.

**Promocja Faz 1 i 2** na `uat` i `main` — polecenia w `git-workflow.md`.
Gałęzie kolejnych faz wychodzą z bieżącego stanu, więc nic nie ginie, ale kolejność się rozjeżdża.
Uwaga: deploy na UAT to nie jedna linia — środowisko nie ma `vendor/`, `.env` ani bazy.

### Dokumentacja badań — czytać przed zmianami w Fazie 3 i 4

- `Dev/reference/flowdefinitionview.md` — 34 realne pola inwentarza + obserwacje
- `Dev/reference/flow-metadata.md` — struktura `Flow.Metadata`, graf połączeń, ograniczenie API

### Rzeczy, o których łatwo zapomnieć

- **`ProcessType` nie wystarcza do rozpoznania typu Flow** — Record-Triggered i Scheduled
  mają tę samą wartość `AutoLaunchedFlow`. Rozróżnia je `TriggerType`.
- **`Metadata` tylko przy jednym rekordzie** — inaczej `MALFORMED_QUERY`.
- **Pisz jawne `?Typ`** — lokalne PHP 8.3 nie pokaże deprecjacji z produkcyjnego 8.4.
- **SOQL wymaga pojedynczych cudzysłowów.**
- **Twig ma `auto_reload => true`** — bez tego zmiany szablonów są niewidoczne na produkcji.
- **Hasła i klucze nigdy do rozmowy** — pliki `.flownatic-*.txt` poza repozytorium.
