# Spike OAuth do Salesforce

Samodzielny skrypt sprawdzajacy, czy OAuth do org dziala **zanim** napiszemy Faze 2.
Nie wymaga Composera ani `vendor/` - dziala mimo braku Laragona.

## Po co to jest

OAuth to najwieksza niewiadoma techniczna projektu. Spike sprawdza cztery rzeczy naraz:

1. **OAuth 2.0 Web Server Flow z PKCE** (S256) przechodzi
2. **`refresh_token` wraca** - bez niego automatyczne odnawianie sesji w Fazie 2 nie zadziala
3. **`describe` na `FlowDefinitionView`** zwraca realne nazwy i typy pol.
   To najcenniejszy wynik: dokumentacja tego obiektu bywa niekompletna, a `plan.md` wprost
   ostrzega, zeby nie zgadywac nazw pol przed napisaniem SOQL-a.
4. **Ile Flow jest w org** - to samo kryterium "Gotowe, gdy" co dla Fazy 2

Sprawdza przy okazji schemat wyszukiwania sciezek (`__DIR__/../app` -> `dirname(__DIR__, 4)`),
ktory pojdzie do `public_html/index.php` w Fazie 1.

## Krok 1 - External Client App w Salesforce (po stronie Rafala)

Setup -> wyszukaj **external client** -> **External Client App Manager** -> **New External Client App**

| Ustawienie | Wartosc |
|---|---|
| Nazwa | `Flownatic POC` |
| **Enable OAuth** | zaznaczone |
| **Callback URL** | `https://dobo.com.pl/ftf/sfoauth.php` |
| Drugi Callback URL | `https://dobo.com.pl/ftf/oauth/callback` (dla aplikacji z Fazy 2) |
| **OAuth Scopes** | `api`, `refresh_token`, `offline_access` |
| **Require PKCE** | zaznaczone |
| Refresh token policy | wazny do odwolania |

Consumer Key i Secret: **Settings -> Consumer Key and Secret** w utworzonej aplikacji.

> Salesforce potrafi propagowac nowa aplikacje **do 30 minut**. Jesli wymiana kodu na token
> odbija sie bledem tuz po utworzeniu - odczekaj, zanim zaczniesz szukac innej przyczyny.

## Krok 2 - konfiguracja

Skopiuj `sf-oauth.example.php` jako `sf-oauth.php` i wypelnij Consumer Key oraz Secret.
**Ta kopia nigdy nie trafia do repozytorium** - `.gitignore` to blokuje, ale trzeba pilnowac.

Na serwer wgrywamy ja **poza katalog publiczny**:

```powershell
.\tools\deploy.ps1 -LocalFile .\tools\sf-oauth\sf-oauth.php -RemotePath "flownatic-app/"
.\tools\deploy.ps1 -LocalFile .\tools\sf-oauth\sfoauth.php  -RemotePath "domains/dobo.com.pl/public_html/ftf/"
```

Katalog `flownatic-app/` lezy poza `domains/`, wiec nie da sie go otworzyc z przegladarki.
To ten sam katalog, w ktorym w Fazie 1 zamieszka `app/` z `.env`.

## Krok 3 - uruchomienie

Wejdz na **https://dobo.com.pl/ftf/sfoauth.php**, kliknij "Polacz z Salesforce", zaloguj sie
i zatwierdz dostep. Wynik wyswietli sie po powrocie.

## Krok 4 - posprzataj

Skrypt jest publicznie dostepny i trzyma sesje OAuth. **Kasujemy go zaraz po odczytaniu wyniku:**

```powershell
.\tools\deploy.ps1 -DeleteRemote "domains/dobo.com.pl/public_html/ftf/sfoauth.php"
.\tools\deploy.ps1 -DeleteRemote "flownatic-app/sf-oauth.php"
```

## Czego ten skrypt NIE robi

Nie zapisuje niczego do bazy, nie szyfruje tokenow i nie odswieza ich automatycznie.
To swiadomie **spike, nie kod produkcyjny** - te rzeczy powstana w Fazie 2 jako
`app/src/Salesforce/OAuthService.php` i `ApiClient.php`. Tokeny zyja tylko w pamieci zadania,
nie sa nigdzie utrwalane, a access token nie jest nawet wyswietlany.
