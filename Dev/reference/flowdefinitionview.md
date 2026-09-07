# FlowDefinitionView — realne pola

Odczytane przez `describe` z playgrounda **2026-08-31**, API **v67.0**.
Nie z dokumentacji — `plan.md` wprost ostrzega, żeby nazw pól tego obiektu nie zgadywać.

Org: `resilient-narwhal-j9207g-dev-ed.trailblaze.my.salesforce.com`

## Pola (34)

```
Id                                id
DurableId                         string
ApiName                           string
Label                             string
Description                       string
ProcessType                       picklist
TriggerType                       picklist
NamespacePrefix                   string
ActiveVersionId                   string
LatestVersionId                   string
LastModifiedBy                    string
IsActive                          boolean
IsOutOfDate                       boolean
LastModifiedDate                  datetime
IsTemplate                        boolean
IsOverridable                     boolean
OverriddenById                    string
SourceTemplateId                  string
OverriddenFlowId                  string
IsSwingFlow                       boolean
Builder                           string
ManageableState                   picklist
InstalledPackageName              string
TriggerObjectOrEventLabel         string
TriggerObjectOrEventId            string
RecordTriggerType                 picklist
HasAsyncAfterCommitPath           boolean
VersionNumber                     int
TriggerOrder                      int
Environments                      multipicklist
ApiVersion                        int
CapacityCategory                  picklist
AreMetricsLoggedToDataCloud       boolean
SupportedEnvironments             string
```

## Co z tego wynika dla nas

### Pola, na których opieramy inwentarz

| Pole | Typ | Do czego |
|---|---|---|
| `DurableId` | string | **Stabilny identyfikator** — po nim rozpoznajemy Flow między importami |
| `ApiName` | string | nazwa techniczna, klucz naturalny |
| `Label` | string | nazwa czytelna dla człowieka |
| `Description` | string | opis autora, wchodzi do promptu w Fazie 4 |
| `ProcessType` | picklist | typ Flow → prefiks `RT-`/`SF-`/`SCH-`/`AL-` w eksporcie |
| `TriggerType` | picklist | rodzaj wyzwalacza |
| `RecordTriggerType` | picklist | **Create / Update / Delete** — kluczowe dla przypadków RT |
| `TriggerObjectOrEventLabel` | string | obiekt wyzwalający, czytelnie |
| `IsActive` | boolean | czy wersja jest aktywna |
| `VersionNumber` | int | numer wersji |
| `LastModifiedDate` | datetime | wykrywanie zmian bez pobierania metadanych |

### Trzy rozbieżności wobec schematu z Fazy 1

1. **`ActiveVersionId` i `LatestVersionId` to identyfikatory (string), nie numery wersji.**
   W `001_init.sql` zadeklarowałem je jako `INT`. Numer wersji to osobne pole `VersionNumber`.
2. **Brakowało `RecordTriggerType`.** Bez niego nie odróżnimy Flow uruchamianego przy tworzeniu
   od tego przy aktualizacji — a to zupełnie inne przypadki testowe w Fazie 4.
3. **Brakowało `DurableId`, `Description` i `LastModifiedDate`.** Pierwsze daje stabilną
   tożsamość, drugie zasila prompt, trzecie pozwala pomijać niezmienione Flow.

### Pola do filtrowania — oszczędzają limit API

`NamespacePrefix`, `ManageableState`, `InstalledPackageName` pozwalają **odsiać Flow
z pakietów zarządzanych**, których i tak nie testujemy. `IsTemplate` odsiewa szablony.
`IsOutOfDate` sygnalizuje Flow wymagające ponownego zapisania.

## Zapytanie bazowe dla Fazy 2

```sql
SELECT DurableId, ApiName, Label, Description, ProcessType, TriggerType,
       RecordTriggerType, TriggerObjectOrEventLabel, IsActive, VersionNumber,
       ActiveVersionId, LatestVersionId, LastModifiedDate, IsTemplate,
       NamespacePrefix, ManageableState
FROM FlowDefinitionView
WHERE IsTemplate = false AND ManageableState = 'unmanaged'
ORDER BY Label
```

## Realne dane z org — import 2026-09-01

Pierwszy udany import zwrócił **9 Flow** (z 79 w org; filtr odsiał 70 pozycji
z pakietów zarządzanych i szablonów Salesforce).

| Label | ProcessType | Obiekt | TriggerType | RecordTriggerType | Stan |
|---|---|---|---|---|---|
| AL-Closed Won Opportunities | AutoLaunchedFlow | Opportunity | RecordAfterSave | CreateAndUpdate | aktywny |
| RT- Acount queue | AutoLaunchedFlow | Account | RecordAfterSave | Create | nieaktywny |
| RT- Flownatic_Bad_Example | AutoLaunchedFlow | Account | RecordAfterSave | Update | aktywny |
| RT-Currency change | AutoLaunchedFlow | Contact | **RecordBeforeSave** | Update | aktywny |
| SCH- Task on not closed opp | AutoLaunchedFlow | Opportunity | Scheduled | — | aktywny |
| SF-Add Contact | Flow | — | — | — | nieaktywny |
| SF-Create Case for Contact | Flow | — | — | — | aktywny |
| Customer Satisfaction | Survey | — | — | — | aktywny |
| Net Promoter Score | Survey | — | — | — | aktywny |

### Obserwacje istotne dla Fazy 3 i 4

**`ProcessType` nie wystarczy do rozpoznania typu Flow.** Record-Triggered Flow mają
`ProcessType = AutoLaunchedFlow`, tak samo jak Scheduled. Rozróżnia je dopiero
`TriggerType`: `RecordAfterSave` / `RecordBeforeSave` kontra `Scheduled`.
Prefiksy `RT-`/`SCH-`/`AL-` w eksporcie trzeba wyprowadzać z **obu** pól, nie z samego
`ProcessType`.

**`RecordBeforeSave` kontra `RecordAfterSave` to inne przypadki testowe.** Before-save
nie może robić DML na własnym rekordzie ani wywoływać innych Flow — checklista musi
to uwzględniać. W org mamy po jednym przykładzie każdego rodzaju.

**Dwie ankiety (`Survey`) zakłada Salesforce automatycznie.** Nie są dziełem testera,
ale są `unmanaged`, więc filtr ich nie odsiewa. W Fazie 6 warto je pominąć przy liczeniu
czasu ręcznego kontra aplikacja, żeby nie zaniżyć wyniku.
