# Proces pracy z gałęziami

## Trzy gałęzie

| Gałąź | Rola | Środowisko | Kto wgrywa |
|---|---|---|---|
| `main` | **Stabilna wersja aplikacji.** Tylko to, co przeszło pełną regresję | `dobo.com.pl/ftf/` (produkcja) | po zatwierdzeniu |
| `uat` | **Weryfikacja i regresja.** Kandydat do wydania | `qekbnopwvk.cfolks.pl` (UAT) | po przejściu testów na feature |
| `feature/*` | **Bieżąca praca** nad jedną funkcjonalnością. Tymczasowa | lokalnie / brak | na bieżąco |

## Przepływ

```
feature/faza-1-szkielet
        │  testy funkcjonalności przechodzą
        ▼
      uat  ──►  wgranie na qekbnopwvk.cfolks.pl  ──►  PEŁNA REGRESJA
        │  regresja przechodzi
        ▼
      main ──►  wgranie na dobo.com.pl/ftf/
        │
        ▼
  zamknięcie feature brancha, otwarcie nowego
```

## Nazewnictwo gałęzi feature

Jedna gałąź na jedną fazę albo jedną wyodrębnioną funkcjonalność:

```
feature/faza-1-szkielet
feature/faza-2-oauth-salesforce
feature/faza-3-flow-digest
feature/faza-4-generator-tc
feature/faza-5-eksport-xlsx
```

Gdy faza jest zamknięta i scalona do `main` — **gałąź feature kasujemy** (lokalnie i na GitHubie)
i otwieramy nową z aktualnego `main`.

## Komendy

**Start nowej funkcjonalności** (zawsze z aktualnego `main`):
```bash
git checkout main
git pull
git checkout -b feature/faza-2-oauth-salesforce
git push -u origin feature/faza-2-oauth-salesforce
```

**Praca na feature** — commity na bieżąco, jeden na ukończony punkt z `task.md`:
```bash
git add -A
git commit -m "opis"
git push
```

**Promocja na UAT** (gdy funkcjonalność działa):
```bash
git checkout uat
git pull
git merge feature/faza-2-oauth-salesforce
git push
.\tools\deploy.ps1 -LocalDir .\public_html -RemotePath "domains/qekbnopwvk.cfolks.pl/public_html/"
```

**Promocja na produkcję** (gdy regresja przeszła):
```bash
git checkout main
git pull
git merge uat
git push
git tag -a faza-2 -m "Faza 2 zakonczona"
git push --tags
.\tools\deploy.ps1 -LocalDir .\public_html -RemotePath "domains/dobo.com.pl/public_html/ftf/"
```

**Zamknięcie feature brancha:**
```bash
git branch -d feature/faza-2-oauth-salesforce
git push origin --delete feature/faza-2-oauth-salesforce
```

---

## Co znaczy „pełna regresja" — definicja

Bez tego ten krok byłby pustym rytuałem. **Regresja = przejście wszystkich punktów poniżej**, które
są już zaimplementowane. Punkty dotyczące niezbudowanych jeszcze faz pomijamy.

Lista rośnie wraz z projektem — **po każdej zakończonej fazie dopisujemy do niej jej kryterium
„Gotowe, gdy"** z `plan.md`.

### Regresja — stan na dziś

| # | Sprawdzenie | Od fazy |
|---|---|---|
| R1 | `https://dobo.com.pl/ftf/` odpowiada, logowanie działa, widać dashboard | 1 |
| R2 | Wylogowanie działa, strony chronione nie wpuszczają bez sesji | 1 |
| R3 | „Połącz org" → OAuth → powrót z aktywnym połączeniem | 2 |
| R4 | Lista Flow zgadza się z Setup → Process Automation → Flows | 2 |
| R5 | Rozłączona org → czytelny komunikat, **nie błąd 500** | 2 |
| R6 | Import metadanych Flow kończy się, widać strukturę | 3 |
| R7 | Na celowo wadliwym Flow zapala się „DML w pętli" i „brak fault path" | 3 |
| R8 | Import przerwany w połowie da się wznowić, nie duplikuje danych | 3 |
| R9 | Generowanie TC zwraca 15–30 przypadków z `checklist_ref` | 4 |
| R10 | Drugie generowanie z rzędu: `cacheReadInputTokens > 0` | 4 |
| R11 | Edycja TC zapisuje się poprawnie | 5 |
| R12 | Eksport .xlsx otwiera się i ma układ 6 arkuszy frameworku | 5 |

**Zasada:** regresję robimy na UAT **na danych z playgrounda**, nie na produkcji. Jeśli którykolwiek
punkt nie przechodzi — poprawka wraca na gałąź feature, nie łatamy bezpośrednio na `uat` ani `main`.

---

## Dlaczego akurat tak

- **`main` zawsze działa.** W każdej chwili można pokazać aplikację, nie sprawdzając wcześniej,
  czy akurat nie jest w połowie przebudowy. Przy projekcie, którego celem jest demo dla firmy,
  to jest istotne.
- **UAT na osobnej domenie.** `qekbnopwvk.cfolks.pl` już istnieje, nic nie kosztuje i jest całkowicie
  odizolowana od produkcji. Regresja na produkcji nie byłaby regresją, tylko ryzykiem.
- **Feature kasowany po scaleniu.** Gałąź, która przeżyje swoją fazę, zaczyna zbierać niepowiązane
  zmiany i przestaje cokolwiek znaczyć. Krótkie życie gałęzi to mniej konfliktów przy scalaniu.
- **Tag na `main` po każdej fazie.** Daje punkt, do którego można wrócić jedną komendą, gdy kolejna
  faza coś zepsuje.
