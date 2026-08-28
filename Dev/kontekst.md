# Flownatic — kontekst projektu

> **Do czego służy ten plik.** Jest to briefing na zimny start. Jeśli sesja została przerwana,
> kontekst skompaktowany albo wracasz po tygodniu — przeczytaj ten plik od góry do dołu, a będziesz
> wiedział, gdzie jesteśmy i dlaczego. Aktualizujemy go **na końcu każdej fazy** oraz **zawsze, gdy
> zapadnie decyzja projektowa** albo **gdy coś okaże się inne, niż zakładaliśmy**.

**Ostatnia aktualizacja:** 2026-08-27 · **Aktualny stan:** Faza 0 — subdomena postawiona

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
| Silnik AI | **Claude API**, model `claude-opus-5` | Działa z każdą org, w tym Developer Edition. Einstein/Agentforce wymagałby licencji, których playground może nie mieć — rozważymy w Fazie 6 |
| Zakres MVP | **Analizator Flow → generator TC** | To sedno pomysłu i to, czego Excel nie potrafi. Pełny menedżer testów byłby głównie przepisaniem arkusza |
| Użytkownicy | **Jeden, jedna org** | POC. Bez `tenant_id`, bez izolacji — najszybsza droga do walidacji |
| Kolejność | **Deploy w Fazie 1, nie na końcu** | Ryzyko hostingowe najgorzej odkrywać po trzech tygodniach kodowania. Callback OAuth i tak wymaga publicznego HTTPS już w Fazie 2 |
| Adres produkcyjny | **`dobo.com.pl/ftf/`, nie subdomena** | Subdomena i podkatalog to fizycznie ten sam katalog, więc subdomena nie daje izolacji. Podkatalog ma ważny, publicznie zaufany certyfikat od ręki — subdomena nie ma żadnego. Decyzja z 2026-08-27 |
| Kolejność | **Parser (Faza 3) przed AI (Faza 4)** | Deterministyczny parser robi to, co musi być powtarzalne. Model dostaje mały, czysty opis i robi to, w czym jest dobry. Taniej, celniej, i część wartości działa bez API AI |

---

## 3. Infrastruktura — stan faktyczny

**Hosting:** cyberfolks, pakiet **cyber_SPRINT**, panel **DirectAdmin**
**Konto:** `qekbnopwvk` · **Serwer:** `s65.cyber-folks.pl` (185.208.164.165) · **Katalog domowy:** `/home/qekbnopwvk`

| Element | Stan |
|---|---|
| PHP | **8.4.21**, LiteSpeed, Linux |
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
6. **Na Opus 5 nie ustawiać** `budgetTokens` ani `thinking` — zwraca 400. Thinking jest domyślnie włączone.
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

**Stan na koniec sesji 2026-08-27** (15 commitów tego dnia). **Faza 0: 9 punktów zamkniętych, 5 otwartych.**

**Infrastruktura stoi w całości.** Subdomena postawiona, omyłkowa `ftp` skasowana, baza MySQL działa
i jest zweryfikowana, playground Salesforce potwierdzony razem z profilem System Administrator.
Powstał pierwszy plik Fazy 1 — `app/composer.json`.

**Adres produkcyjny: `https://dobo.com.pl/ftf/`** (decyzja z 2026-08-27, szczegóły w sekcji 2).
Nie `ftf.dobo.com.pl`. Certyfikat dla subdomeny jest opcjonalny i **nic już nie blokuje**.

**Callback OAuth przesądzony:** `https://dobo.com.pl/ftf/oauth/callback`. Wchodzi do Connected App
w Fazie 2, a Salesforce dopasowuje go dokładnie — dlatego adres domknięto przed Fazą 2, nie w trakcie.

**Baza:** `qekbnopwvk_flownatic` na MariaDB 10.6.27, pusta, `utf8mb4_unicode_ci`. Uwaga na przyszłość:
**panel zakłada bazy w `utf8mb3`** mimo prośby o `utf8mb4` — trzeba poprawiać `ALTER DATABASE`, póki
baza jest pusta. Zweryfikowane testem zapisu i odczytu emoji bez strat.

**Incydent bezpieczeństwa — zamknięty.** W katalogu repozytorium wylądował plik, którego nazwa i treść
były hasłem do bazy. Do gita nie trafił (sprawdzona cała historia i wszystkie gałęzie), został usunięty,
a hasło zmienione. `.gitignore` dostał regułę `/*.txt`, bo repo jest publiczne i najbliższe `git add -A`
by ten plik złapało. **Wniosek na przyszłość: haseł nie wpisujemy w terminalu, tylko w Notatniku.**

**Otwarte punkty Fazy 0 — wszystkie 🔵:** klucz `ANTHROPIC_API_KEY` (potrzebny dopiero w Fazie 4),
Flow w playgroundzie 3–5 typów oraz jeden celowo wadliwy (potrzebne w Fazie 2–3), certyfikat SSL
(opcjonalny) i **Laragon**.

**Spike OAuth — napisany, zaparkowany.** `tools/sf-oauth/sfoauth.php` powstał 2026-08-28, żeby
zawczasu zdjąć największe ryzyko techniczne. Jest **nieuruchomiony** — wracamy do niego
w Fazie 2, zgodnie z zasadą jednej fazy naraz. Wymaga Consumer Key/Secret z External Client App.
Nie ma lokalnego PHP, więc jego składnia nie została zweryfikowana maszynowo.

**Największe otwarte ryzyko — jedyna realna blokada:** brak środowiska lokalnego. Na komputerze **nie ma
ani PHP, ani Composera, ani MySQL**. `app/composer.json` jest napisany, ale `composer install` nie ma czym
się uruchomić, a na serwerze nie ma powłoki, więc `vendor/` musi powstać lokalnie. Pliki Fazy 1 można
pisać już teraz — ale nie da się ich uruchomić ani przetestować, dopóki Laragon nie stoi.
