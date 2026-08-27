# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Język

**Rozmowa, dokumentacja projektu i commity — po polsku.** Kod, nazwy plików i identyfikatory — po angielsku.

## Czym jest to repozytorium

**Flownatic** — akcelerator do manualnego testowania Salesforce Flow. Aplikacja webowa (PHP 8.4 / MySQL /
Slim 4) podłącza się do org przez API, czyta strukturę Flow, deterministycznie wykrywa ryzyka, a następnie
przez Claude API zamienia generyczną checklistę TC-001…TC-026 w konkretne przypadki testowe i eksportuje je
do .xlsx w układzie frameworku.

**Stan: projekt jest przed pierwszą linijką kodu aplikacji.** W repo są dziś tylko: dokumentacja w `Dev/`,
strona-wizytówka `site/index.html` i narzędzia w `tools/`. Katalogi `app/` i `public_html/` z opisu
architektury **jeszcze nie istnieją** — powstaną w Fazie 1.

## Dokumentacja — kolejność czytania

Cały kontekst projektu żyje w `Dev/`. Przed pracą przeczytaj w tej kolejności:

| Plik | Rola |
|---|---|
| `Dev/kontekst.md` | **Zacznij tutaj.** Briefing na zimny start: gdzie jesteśmy, dane hostingu, decyzje i ich uzasadnienia |
| `Dev/plan.md` | Plan 7 faz, architektura docelowa, schemat bazy, ryzyka — wyjaśnia **dlaczego** |
| `Dev/task.md` | Operacyjny tracker — tu odznaczamy postęp `[ ]` → `[x]` |
| `Dev/git-workflow.md` | Gałęzie, promocja na UAT/produkcję, definicja „pełnej regresji" (R1–R12) |

**Te pliki utrzymujemy na bieżąco:** `task.md` przy każdym ukończonym punkcie, `kontekst.md` na koniec
każdej fazy oraz zawsze, gdy zapadnie decyzja projektowa albo coś okaże się inne, niż zakładaliśmy.

## Sposób pracy

- **Jedna faza naraz, punkt po punkcie.** Nie zaczynamy kolejnej fazy, dopóki poprzednia nie przejdzie
  swojego kryterium „Gotowe, gdy" z `plan.md`. Rafał wyraźnie o to poprosił.
- **Jeden commit na jeden ukończony punkt z `task.md`**, opis po polsku, wypychany na bieżąco.
- **Podział ról:** 🔵 = Rafał (panel DirectAdmin, Salesforce, konta, klucze API), 🟢 = Claude (kod, skrypty,
  deploy, dokumentacja). Zadania 🔵 w `task.md` odznacza Rafał — nie próbuj ich wykonać za niego.
- **Hasła i klucze nigdy nie trafiają do rozmowy** — zawsze przez plik poza repozytorium.

## Deploy — jedyna droga na serwer

**Na serwerze nie ma powłoki** (`disable_functions` blokuje `exec`/`shell_exec`/…), a **SSH nie działa
z tego laptopa** (`Corrupted MAC on input` — firmowa sieć modyfikuje ruch; zmiana hostingu tego nie naprawi).
Działa wyłącznie **FTPS przez `tools/deploy.ps1`**:

```powershell
.\tools\deploy.ps1 -Test                                     # sprawdzenie połączenia
.\tools\deploy.ps1 -ListPath "domains/dobo.com.pl/public_html/"
.\tools\deploy.ps1 -LocalFile .\site\index.html -RemotePath "domains/dobo.com.pl/public_html/"
.\tools\deploy.ps1 -LocalDir .\public_html -RemotePath "domains/dobo.com.pl/public_html/ftf/"
.\tools\deploy.ps1 -DeleteRemote "domains/x/public_html/plik.php"
.\tools\deploy.ps1 -RemoveDir "domains/x/public_html/stare"          # pokazuje plan, nie kasuje
.\tools\deploy.ps1 -RemoveDir "domains/x/public_html/stare" -Force   # wykonuje
.\tools\deploy.ps1 -RenameFrom "sciezka/a" -RenameTo "sciezka/b"
```

Dane FTP czytane są z `%USERPROFILE%\.ftp-dobo.txt` (poza repo); hasło trafia do curl-a przez tymczasowy
`.netrc`, więc nie widać go w liście procesów. `-LocalDir` wgrywa rekurencyjnie, plik po pliku.

**Konsekwencja braku powłoki: `composer install` uruchamiamy lokalnie, a `vendor/` wgrywamy przez FTP.**
Nie ma sposobu, by wykonać cokolwiek na serwerze poza żądaniem HTTP do wgranego pliku PHP.

Do diagnostyki produkcji służy `tools/_check.php` — wgrywany **tymczasowo** i kasowany zaraz po odczycie
(`-DeleteRemote`). Celowo nie używa `phpinfo()`.

## Środowiska i gałęzie

| Gałąź | Środowisko | Adres |
|---|---|---|
| `feature/*` | lokalnie | — |
| `uat` | UAT, tu robimy pełną regresję | `qekbnopwvk.cfolks.pl` |
| `main` | produkcja | `dobo.com.pl/ftf/` |

Przepływ: `feature/*` → `uat` (regresja R1–R12 z `git-workflow.md`, na danych z playgrounda) → `main` + tag
`faza-N`. Poprawki po nieudanej regresji wracają na gałąź feature — **nie łatamy bezpośrednio na `uat` ani
`main`**. Po zamknięciu fazy gałąź feature kasujemy lokalnie i na GitHubie.

## Testy i budowanie

**Nie ma jeszcze ani testów, ani builda.** Na komputerze nie ma PHP, Composera ani MySQL — instalacja
Laragona to otwarte zadanie z Fazy 0 i największe bieżące ryzyko. Dopóki go nie ma, `composer install`
i `app/bin/migrate.php` są niewykonalne.

Weryfikacja odbywa się dziś ręcznie: kryterium „Gotowe, gdy" danej fazy plus lista regresji R1–R12.

## Twarde ograniczenia — nie zapominać

1. **`max_execution_time = 180 s`** na produkcji. Import wszystkich Flow jednym żądaniem urwie się w połowie
   i zostawi bazę w stanie częściowym. **Partie (~5 Flow na żądanie) to wymóg, nie optymalizacja.**
2. **`Flow.Metadata` w Tooling API zwraca się tylko, gdy zapytanie daje maksymalnie jeden rekord.** Import org
   to N+1 wywołań → stąd cache po `metadata_hash` i wznawialność importu.
3. **Limit API playgrounda** (Developer Edition) — nie odpytywać Flow o niezmienionym hashu.
4. **`memory_limit = 128M`** — pilnować przy generowaniu dużych .xlsx.
5. **`DOCUMENT_ROOT` raportuje `private_html`**, choć pliki idą do `public_html`. Budując ścieżki używać
   `__DIR__`, nigdy `$_SERVER['DOCUMENT_ROOT']`.
6. **Na `claude-opus-5` nie ustawiać `thinking` ani `budgetTokens`** — API zwraca 400. Adaptive thinking jest
   domyślnie włączone.

## Bezpieczeństwo

**Repozytorium jest publiczne** i zawiera dokumenty firmowe — Rafał wie o tym i świadomie tak zdecydował.
Praktyczna konsekwencja: **`.env` nigdy nie trafia do commita** (boty skanują GitHuba w kilkanaście sekund).
`.gitignore` to blokuje, ale trzeba pilnować.

Architektura docelowa trzyma kod i sekrety **poza katalogiem publicznym**: `public_html/` to document root,
a równoległy `app/` (z `.env`, `vendor/`, `src/`) jest niedostępny z sieci — dodatkowo zabezpieczony
`app/.htaccess` z `Require all denied`. Tokeny Salesforce szyfrowane AES-256-GCM kluczem `APP_KEY`.

## Decyzje architektoniczne, których nie podważamy bez powodu

- **Slim 4, nie Laravel** — na współdzielonym hostingu Laravel to walka z document rootem i `artisan`, bez
  korzyści w zamian.
- **PHP, nie Node.js** — cyberfolks nie wspiera Node (ręczny `nohup` + proxy w `.htaccess`, bez PM2).
- **Deterministyczny parser (Faza 3) przed AI (Faza 4).** `DigestBuilder` zamienia 100–300 KB surowego JSON-a
  na 2–5 KB konkretów, a `RiskScanner` wykrywa ryzyka regułami, zero AI. Model dostaje mały, czysty opis
  i robi to, w czym jest dobry. Wrzucanie surowego JSON-a do modelu dawałoby losową jakość.
- **Deploy na produkcję w Fazie 1, nie na końcu** — ryzyko hostingowe najgorzej odkrywać po trzech tygodniach
  kodowania, a callback OAuth i tak wymaga publicznego HTTPS już w Fazie 2.
- **Jeden użytkownik, jedna org** — POC, bez `tenant_id` i izolacji.

## Materiały źródłowe (nie edytować)

`SalesforcCloud_FTF.xlsx` to **treść merytoryczna frameworku** — 6 arkuszy, TC-001…TC-026, przypadki per typ
Flow z prefiksami `RT-`/`SF-`/`SCH-`/`AL-`. Eksport z Fazy 5 musi odtworzyć dokładnie ten układ.
`Chcę zgłosić swój pomysł na stoworzenie.odt` to zgłoszenie idea №00001131, którego obroną jest Faza 6.
