# Faza 6 — protokół pomiaru

> **Po co ten plik.** Faza 6 ma dostarczyć liczby do obrony zgłoszenia **№00001131**.
> Liczba bez definicji jest bezużyteczna: „18 trafionych przypadków" nic nie znaczy, dopóki
> nie wiadomo, co znaczy „trafiony". Ten dokument ustala definicje **zanim** zaczniesz mierzyć —
> bo definicja ustalona po zobaczeniu wyników zawsze podejrzanie pasuje do wyników.

**Wypełnia:** 🔵 Rafał · **Przygotował:** 🟢 Claude · **Data utworzenia:** 2026-09-08

---

## 1. Zasada, która decyduje o wiarygodności

**Najpierw ręcznie, potem aplikacja. Bez wyjątków.**

Jeśli najpierw zobaczysz listę wygenerowaną przez Flownatic, nie da się już napisać testów
ręcznie „na czysto" — będziesz je pisał *w reakcji* na to, co zobaczyłeś. Pomiar
przestanie mierzyć oszczędność, a zacznie mierzyć Twoją zgodność z narzędziem.

Kolejność dla **każdego** Flow:

1. Otwórz Flow w Flow Builderze, napisz przypadki ręcznie, **zmierz czas**.
2. Zamknij notatki. Dopiero teraz otwórz Flownatic i wygeneruj przypadki, **zmierz czas**.
3. Porównaj obie listy według definicji z sekcji 3.

---

## 2. Co mierzymy

| Pomiar | Jednostka | Jak zmierzyć |
|---|---|---|
| **Czas ręcznie** | minuty | Od otwarcia Flow w Flow Builderze do ostatniego zapisanego przypadku |
| **Czas w aplikacji** | minuty | Od kliknięcia „Pobierz metadane" do pobrania .xlsx |
| **TC ręcznie** | sztuki | Ile przypadków napisałeś sam |
| **TC z aplikacji** | sztuki | Ile wygenerował Flownatic |
| **Trafione** | sztuki | patrz definicja niżej |
| **Bezużyteczne** | sztuki | patrz definicja niżej |
| **Brakujące** | sztuki | **najważniejsza liczba** — patrz definicja niżej |

Czas mierz **stoperem w telefonie**, nie z pamięci. Przerwy (telefon, kawa) zatrzymuj.

---

## 3. Definicje — ustalone przed pomiarem

### Trafiony
Przypadek z aplikacji, który **pokrywa się merytorycznie** z przypadkiem, który napisałeś ręcznie —
nawet jeśli brzmi inaczej. Liczy się testowana rzecz, nie sformułowanie.

> Twój: „Sprawdź, czy Flow nie wywali się na 200 rekordach"
> Aplikacji: „Bulk — 200 rekordów Account w jednej transakcji"
> → **trafiony**, mimo innego brzmienia.

### Bezużyteczny
Przypadek z aplikacji, którego **nie wykonasz** — bo dotyczy rzeczy nieistniejącej w tym Flow,
powiela inny przypadek albo jest tak ogólny, że nie da się go odtworzyć krok po kroku.

⚠️ **Nie jest bezużyteczny** przypadek, którego sam nie napisałeś, ale po przeczytaniu uznajesz
za sensowny. To jest **wartość dodana** — licz go osobno, w kolumnie „TC dodatkowe trafne".
Mylenie tych dwóch kategorii jest najczęstszym sposobem na zaniżenie wyniku narzędzia.

### Brakujący
Przypadek, który napisałeś ręcznie, a aplikacja go **nie wygenerowała**.

**To jest liczba, od której zależy, czy narzędziu można ufać.** Bezużyteczny przypadek kosztuje
minutę czytania. Brakujący przypadek to **luka w testach, o której nikt nie wie** — i dokładnie
tak wygląda ryzyko, które wypuszcza się na produkcję.

Przy każdym brakującym zapisz **dlaczego** go nie ma. To materiał na kolejne reguły:

- czy wynikał z metadanych Flow (→ da się dopisać regułę),
- czy z wiedzy o procesie biznesowym (→ narzędzie nigdy tego nie wymyśli, i trzeba to uczciwie powiedzieć w zgłoszeniu).

---

## 4. Wybór Flow — 3 do 5, różnorodne

Playground ma dziś **9 Flow**. Wybierz tak, żeby zestaw był reprezentatywny, a nie wygodny:

| # | Flow | Typ | Dlaczego ten |
|---|---|---|---|
| 1 | `RT- Flownatic_Bad_Example` | Record-Triggered | Zna go już cały projekt; ma realne błędy |
| 2 | | | najlepiej Screen Flow — inny rodzaj przypadków |
| 3 | | | coś prostego, gdzie narzędzie może wyjść na przerost formy |
| 4 | | | coś rozbudowanego, z decyzjami |
| 5 | | | opcjonalnie |

⚠️ **Nie dobieraj samych Flow z błędami.** Flow poprawny, na którym `RiskScanner` milczy, jest
równie ważnym dowodem: pokazuje, że narzędzie nie generuje fałszywych alarmów. Zgłoszenie obroni
się lepiej, jeśli w zestawie będzie choć jeden taki.

---

## 5. Tabela wyników

Wypełniaj po każdym Flow, nie na koniec — szczegóły ulatują.

| Flow | Typ | Czas ręcznie | Czas apka | TC ręcznie | TC apka | Trafione | Dodatkowe trafne | Bezużyteczne | **Brakujące** |
|---|---|---|---|---|---|---|---|---|---|
| RT- Flownatic_Bad_Example | RT | | | | 16 | | | | |
| | | | | | | | | | |
| | | | | | | | | | |
| | | | | | | | | | |
| | | | | | | | | | |
| **RAZEM** | | | | | | | | | |

### Wskaźniki liczone z tabeli

- **Oszczędność czasu** = (czas ręcznie − czas apka) / czas ręcznie
- **Pokrycie** = trafione / TC ręcznie ← *ile Twojej pracy narzędzie odtworzyło*
- **Precyzja** = (trafione + dodatkowe trafne) / TC apka ← *ile z tego, co dało, jest użyteczne*
- **Luka** = brakujące / TC ręcznie ← **ta liczba idzie do zgłoszenia jako pierwsza, także jeśli wypadnie źle**

---

## 6. Brakujące przypadki — rejestr

| Flow | Czego zabrakło | Dlaczego aplikacja tego nie wie | Da się dopisać regułę? |
|---|---|---|---|
| | | | |
| | | | |

---

## 7. Czego ten pomiar **nie** udowodni

Uczciwość tej sekcji jest częścią obrony zgłoszenia — jeśli sam wskażesz granice, trudniej
będzie je komuś wytknąć jako przemilczane.

- **Jeden tester, jedna org.** Playground Developer Edition to nie jest produkcyjna org klienta
  z setką Flow i pakietami zarządzanymi.
- **Znasz narzędzie.** Twój czas w aplikacji będzie krótszy niż osoby, która widzi ją pierwszy raz.
- **Piszesz testy ręcznie, wiedząc, że będą porównane.** To podnosi ich jakość względem
  codziennej praktyki — czyli **zaniża** przewagę narzędzia. To akurat błąd w bezpieczną stronę.
- **Nie mierzymy jakości wykonania testów**, tylko ich powstawanie. Czy testy faktycznie
  wyłapują błędy, pokaże dopiero użycie na realnym projekcie.

---

## 8. Materiał do zgłoszenia №00001131

- [ ] Tabela z sekcji 5, wypełniona
- [ ] Rejestr braków z sekcji 6
- [ ] Wyeksportowany .xlsx dla jednego Flow — **dowód rzeczowy**, nie zrzut ekranu
- [ ] Demo 3 min (scenariusz przygotuje Claude, gdy będą liczby)
- [ ] Zdanie o koszcie: **0 USD za Flow**, bez licencji i bez zależności od zewnętrznego dostawcy
