# Flow.Metadata — realna struktura

Odczytane z playgrounda **2026-09-01** przez Tooling API, `RT- Flownatic_Bad_Example`.
Nie z dokumentacji.

## Twarde ograniczenie API — potwierdzone komunikatem

Zapytanie o `Metadata` przy więcej niż jednym rekordzie zwraca **HTTP 400**:

```
MALFORMED_QUERY: When retrieving results with Metadata or FullName fields,
the query qualifications must specify no more than one row for retrieval.
Result size: 5
```

Stąd import metadanych to **N+1 wywołań** i stąd cache po `metadata_hash`.
Lista wersji (`SELECT Id, MasterLabel, VersionNumber, Status FROM Flow`) **wolno**
pytać zbiorczo — ograniczenie dotyczy wyłącznie pól `Metadata` i `FullName`.

## Rozmiar

Metadane wadliwego Flow: **6,8 kB**. `plan.md` zakładał 100–300 kB — te Flow są proste.
`DigestBuilder` nadal ma sens, ale głównie jako **selekcja i uporządkowanie**,
a nie kompresja: model ma dostać czytelny opis, nie surowy JSON pełen `null`-i.

## Elementy — gdzie czego szukać

Metadane to obiekt z ~50 kluczami, w większości pustymi listami. Istotne:

| Klucz | Zawiera |
|---|---|
| `start` | wyzwalacz: `object`, `triggerType`, `recordTriggerType`, `filters`, `connector` |
| `recordLookups` | Get Records |
| `recordCreates` / `recordUpdates` / `recordDeletes` | **DML** |
| `loops` | pętle |
| `decisions` | rozgałęzienia |
| `assignments`, `screens`, `subflows`, `actionCalls` | reszta elementów |

### Jak elementy są połączone

Każdy element ma `name` (identyfikator) i `connector.targetReference` wskazujący następny.
Pętla ma dwa wyjścia:

- **`nextValueConnector`** — ciało pętli, wykonywane dla każdego rekordu
- **`noMoreValuesConnector`** — co dalej po zakończeniu pętli

To jest klucz do wykrywania **DML w pętli**: trzeba przejść graf od `nextValueConnector`
i sprawdzić, które elementy są osiągalne, zanim wrócimy do pętli.
Naiwne „Flow ma pętlę i ma DML" dawałoby fałszywe alarmy.

### Fault path

`faultConnector` pojawia się jako **osobny klucz elementu** tylko wtedy, gdy jest ustawiony.
Jego brak = brak obsługi błędu.

## Prześledzony przykład — RT- Flownatic_Bad_Example

```
start            Account, RecordAfterSave, recordTriggerType=Update, filters=[]
  -> Get_Related_Contact      recordLookup, Contact, filters=[AccountId = $Record.Id]
     -> Loop_Through_Contacts loop nad kolekcja Get_Related_Contact
        nextValueConnector -> Update_Contact_In_Loop   recordUpdate  <-- DML W PETLI
           -> second_create                            recordCreate  <-- DML W PETLI
              -> z powrotem do Loop_Through_Contacts
```

Żaden element nie ma `faultConnector`.

## Które reguły `RiskScanner` zapalą się na tym Flow

| Reguła | Zapali się? | Dlaczego |
|---|---|---|
| DML w pętli | ✅ tak | `Update_Contact_In_Loop` i `second_create` w ciele pętli |
| DML bez fault path | ✅ tak | żaden element nie ma `faultConnector` |
| After Save bez entry criteria | ✅ tak | `start.filters = []`, `filterFormula = null` |
| `Get Records` bez filtrów | ❌ **nie** | `Get_Related_Contact` **ma** filtr `AccountId = $Record.Id` |

> ⚠️ **Czwarta reguła nie ma na czym się zapalić.** Żeby ją przetestować, trzeba dodać
> do tego Flow drugi `Get Records` **bez żadnych filtrów** — albo osobny Flow z taką wadą.
> Bez tego przetestujemy trzy czwarte skanera i nie dowiemy się, czy czwarta reguła działa.
