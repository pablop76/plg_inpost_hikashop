# InPost Paczkomaty - HikaShop Shipping Plugin

> ⚠️ **UWAGA:** Ta wtyczka nie jest przeznaczona do użytku komercyjnego. Nie przeszła wszystkich testów i może zawierać błędy. Używasz na własną odpowiedzialność.

Plugin wysyłkowy dla HikaShop (Joomla 4/5/6) integrujący InPost Paczkomaty z mapą GeoWidget i **pełną integracją ShipX API** (tworzenie przesyłek i etykiet).

## Funkcje

### Wybór paczkomatu (Frontend)

- ✅ Wybór paczkomatu na mapie (GeoWidget)
- ✅ **Wybór wersji API mapy:**
  - **Stare API** - działa na localhost bez tokena
  - **Nowe API v5** - wymaga tokena dla domeny (generowany w Manager Paczek)
- ✅ Obsługa paczkomatów i punktów POP
- ✅ Zapis wybranego paczkomatu w zamówieniu
- ✅ Walidacja - blokada zamówienia bez wybranego punktu
- ✅ Modal z mapą i przyciskiem zamknięcia

### ShipX API (Admin - tworzenie przesyłek)

- ✅ Tworzenie przesyłki InPost bezpośrednio z panelu zamówienia
- ✅ Automatyczne pobieranie danych odbiorcy z zamówienia
- ✅ Konfigurowalne dane nadawcy
- ✅ Wybór rozmiaru paczki (Mała A / Średnia B / Duża C)
- ✅ Pobieranie etykiety PDF
- ✅ Obsługa środowiska Sandbox (testowe) i Produkcji
- ✅ **Opcja wymagania potwierdzenia zamówienia** przed utworzeniem przesyłki

### Inne

- ✅ Wyświetlanie paczkomatu w panelu admina (szczegóły zamówienia)
- ✅ Tryb debug (logowanie do pliku)
- ✅ **Debug na zapleczu** (wyświetlanie odpowiedzi API w adminie)

## Wymagania

- **Joomla 4.x / 5.x / 6.x**
- **HikaShop 4.x / 5.x**
- **PHP 8.1+**

## Instalacja

1. Pobierz paczkę ZIP z tego repozytorium
2. W panelu Joomla: **System → Rozszerzenia → Instaluj**
3. Wgraj plik ZIP
4. Włącz plugin: **System → Wtyczki → InPost Paczkomaty**

## Aktualizacje

Wtyczka zgłasza się do serwera aktualizacji Joomli, więc nowe wersje pojawiają się w **System → Aktualizacje → Rozszerzenia**.

**Jeśli sklep z wersją 4.2.22 lub starszą nie widzi aktualizacji**, zainstaluj najnowszą paczkę ręcznie, jeden raz. Przyczyna leży po stronie HikaShopa: przy każdej swojej aktualizacji szuka on „własnego” serwera aktualizacji po słowie „hikashop” w adresie i pierwszemu trafieniu nadpisuje adres swoim. W starszych instalacjach HikaShopa pierwszym trafieniem bywał serwer tej wtyczki (jej repozytorium ma to słowo w nazwie) i Joomla przestawała pytać o jej aktualizacje.

Od 4.2.23 adres serwera aktualizacji w manifeście nie zawiera tego słowa dosłownie (`%68ikashop`, GitHub oddaje ten sam plik), a skrypt instalacyjny odpina wtyczkę od serwera przejętego wcześniej przez HikaShop i Joomla zakłada jej własny.

## Konfiguracja

1. Przejdź do **Komponenty → HikaShop → Konfiguracja → Wysyłka**
2. Kliknij **Nowy** i wybierz **InPost**
3. Skonfiguruj opcje:

### Ustawienia API

| Opcja                | Opis                                   | Domyslnie |
| -------------------- | -------------------------------------- | --------- |
| Tryb API             | Produkcja lub Sandbox (testowe)        | Produkcja |
| Token ShipX API      | Token autoryzacyjny z Managerów Paczek | -         |
| ID organizacji ShipX | ID organizacji z Managera Paczek       | -         |

### Dane nadawcy (wymagane do tworzenia przesyłek)

| Opcja                   | Opis                              |
| ----------------------- | --------------------------------- |
| Imię i nazwisko nadawcy | Wymagane                          |
| Nazwa firmy nadawcy     | Opcjonalne                        |
| Email nadawcy           | Wymagane                          |
| Telefon nadawcy         | Wymagane                          |
| Ulica nadawcy           | Wymagane                          |
| Numer budynku           | Wymagane                          |
| Miasto                  | Wymagane                          |
| Kod pocztowy            | Wymagane                          |
| Domyślny rozmiar paczki | Mała (A) / Średnia (B) / Duża (C) |

### Ustawienia mapy

| Opcja                   | Opis                                                              | Domyslnie |
| ----------------------- | ----------------------------------------------------------------- | --------- |
| **Wersja API mapy**     | Stare API (localhost) / Nowe API v5 (wymaga tokena)               | Stare     |
| Token GeoWidget         | Token publiczny z Manager Paczek (tylko dla API v5)               | -         |
| Konfiguracja punktów    | parcelCollect / parcelCollectPayment / parcelCollect247 (API v5)  | parcelCollect |
| Szerokość geogr. (lat)  | Domyślna pozycja mapy                                             | 52.2297   |
| Długość geogr. (lng)    | Domyślna pozycja mapy                                             | 21.0122   |
| Domyślny zoom           | Poziom przybliżenia (pusty = auto)                                | auto      |
| Pokaż paczkomaty        | Włącz paczkomaty (stare API)                                      | Tak       |
| Pokaż punkty POP        | Włącz punkty POP (stare API)                                      | Nie       |
| Tryb debug              | Logowanie do pliku `/logs/inpost_hika_debug.log`                  | Nie       |
| Debug na zapleczu       | Wyświetlanie odpowiedzi API w panelu admina                       | Nie       |
| Wymagaj potwierdzenia   | Tylko zamówienia `confirmed`/`shipped` mogą mieć przesyłki        | Tak       |

#### Różnice między wersjami API

| Cecha                   | Stare API                    | Nowe API v5                        |
| ----------------------- | ---------------------------- | ---------------------------------- |
| Token                   | Nie wymaga                   | Wymaga (z Manager Paczek)          |
| Localhost               | ✅ Działa                    | ✅ Działa                          |
| Konfiguracja punktów    | Paczkomaty + POP (checkboxy) | parcelCollect, parcelCollectPayment, parcelCollect247, parcelSend |

4. Ustaw cenę wysyłki, strefę i inne standardowe opcje HikaShop
5. Zapisz

## Konfiguracja ShipX API

### Pobranie danych autoryzacyjnych

#### Środowisko Sandbox (testowe)

1. Zarejestruj się na: https://sandbox-manager.paczkomaty.pl/
2. Uzupełnij wszystkie dane (Moje konto → Dane)
3. Przejdź do: Moje konto → API
4. Skopiuj **Token** i **ID organizacji**
5. Doładuj konto wirtualnie w zakładce Płatności

#### Środowisko Produkcyjne

1. Zaloguj się na: https://manager.paczkomaty.pl/
2. Przejdź do: Moje konto → API
3. Skopiuj **Token** i **ID organizacji**

### Tworzenie przesyłki

1. Przejdź do szczegółów zamówienia w HikaShop
2. Jeśli zamówienie ma wysyłkę InPost, zobaczysz sekcję "InPost ShipX (Admin)"
3. Kliknij **"Utwórz przesyłkę InPost"**
4. InPost potwierdza przesyłkę sam, po kilku sekundach. Osobnego kroku opłacania nie ma
5. Gdy przesyłka jest gotowa, **"Pobierz etykietę"** zwraca plik PDF

### Zabezpieczenie przed drugą przesyłką

Przesyłki InPost są płatne, więc wtyczka pilnuje, żeby jedno zamówienie nie dostało dwóch:

- na czas tworzenia przesyłki zamówienie jest rezerwowane jednym zapytaniem do bazy, więc podwójne kliknięcie przycisku woła InPost tylko raz
- gdy InPost nie odpowie (przekroczony czas, błąd serwera), nie wiadomo, czy przesyłka powstała. Tworzenie zostaje wtedy zablokowane, a panel zamówienia prosi o sprawdzenie w Managerze Paczek, czy istnieje przesyłka z opisem „Zamówienie #numer”. Przycisk **"Odblokuj tworzenie przesyłki"** zdejmuje blokadę
- jednoznaczna odmowa InPost (błędny kod paczkomatu, zły telefon, złe dane dostępowe) niczego nie blokuje: można poprawić dane i spróbować od razu
- **"Utwórz ponownie"** przy przesyłce już potwierdzonej ostrzega przed kliknięciem, że InPost jej nie anuluje i że powstanie druga, osobno płatna

### Logika tworzenia etykiet

- Przesyłka wymaga ręcznego utworzenia w zamówieniu Hikashop
- Etykieta PDF dostępna tylko PO UTWORZENIU PRZESYŁKI

### Obsługiwane rozmiary paczek

| Rozmiar     | Wymiary (dł/szer/wys) | Waga max |
| ----------- | --------------------- | -------- |
| Mała (A)    | 380 x 640 x 80 mm     | 25 kg    |
| Średnia (B) | 380 x 640 x 190 mm    | 25 kg    |
| Duża (C)    | 410 x 380 x 640 mm    | 25 kg    |

## Wyświetlanie paczkomatu w panelu admina

Wybrany paczkomat pokazuje się w panelu admina na **dwa niezależne sposoby**:

### 1. Własny box wtyczki (działa na każdej edycji HikaShop)

W szczegółach zamówienia, pod listą produktów, wtyczka rysuje własną ramkę z nazwą wybranego
paczkomatu. Renderuje ją zdarzenie `onAfterOrderProductsListingDisplay`, więc jest widoczna
**niezależnie od edycji HikaShop** (także na darmowej/Starter) i nie wymaga żadnej konfiguracji.

### 2. Pole zamówienia `inpost_locker` (sekcja „Dodatkowe informacje")

Wtyczka automatycznie zakłada pole własne zamówienia `inpost_locker` w tabeli `#__hikashop_field`.
O tym, gdzie HikaShop je pokazuje, decydują dwa przełączniki w edytorze pola
(**Komponenty → HikaShop → Wyświetlanie → Pola**):

| Ustawienie pola HikaShop | Kolumna w bazie         | Gdzie widać paczkomat                          |
| ------------------------ | ----------------------- | ---------------------------------------------- |
| **Backend**              | `field_backend`         | Wiersz w sekcji „Dodatkowe informacje" zamówienia |
| **Listing**              | `field_backend_listing` | Osobna kolumna na **liście** zamówień          |

- ⚠️ **Natywna sekcja „Dodatkowe informacje" renderuje pola własne tylko na HikaShop Business**
  (`hikashop_level(2)`). Na darmowej/Starter ta sekcja w ogóle nie pokazuje pól niestandardowych —
  wtedy paczkomat widać wyłącznie przez własny box wtyczki (punkt 1). Kolumna na liście zamówień
  (**Listing**) działa na każdej edycji.
- **Po instalacji „Listing" jest domyślnie włączony** (`field_backend_listing=1`) razem z „Backend",
  więc paczkomat pokazuje się od razu i w szczegółach, i jako kolumna na liście.
- **To tylko ustawienie startowe.** Możesz w edytorze pola dowolnie zaznaczać/odznaczać „Listing"
  i „Backend" — wtyczka **nie nadpisuje ręcznych zmian**. Flagi wyświetlania ustawiane są
  wyłącznie przy pierwszym zakładaniu pola; potem self-heal jedynie uzupełnia pusty podpis pola,
  nie ruszając Twoich ustawień. Jeśli więc odznaczysz „Listing", zostanie odznaczony na stałe.

## Tryb Debug

Plugin obsługuje dwa rodzaje debugowania:

### Debug do pliku
Gdy włączony, plugin zapisuje logi do pliku w katalogu dzienników Joomli (`log_path` z konfiguracji, zwykle `administrator/logs`):

```
administrator/logs/inpost_hika_debug.php
```

Logowane są: wybór paczkomatu, zapis do bazy, potwierdzenie zamówienia, wywołania API ShipX.

Plik ma rozszerzenie `.php` i zaczyna się od `die()`, tak jak dzienniki samej Joomli. W dzienniku są dane odbiorców (imię, e-mail, telefon), a katalog dzienników bywa dostępny z sieci: zwykły plik `.log` dałoby się pobrać przeglądarką. Dziennik czyta się przez FTP albo menedżer plików. Wersje do 4.2.21 pisały do `inpost_hika_debug.log`; przy pierwszym zapisie wtyczka przenosi jego treść do nowego pliku i stary usuwa. Jeśli debugowanie jest wyłączone, stary plik trzeba usunąć ręcznie.

### Debug na zapleczu
Gdy włączony, wyświetla pełne odpowiedzi API bezpośrednio w panelu admina (przydatne do diagnozowania problemów).

## Baza danych

Plugin automatycznie tworzy kolumny w tabeli `#__hikashop_order`:

- `inpost_locker` - nazwa wybranego paczkomatu. Zapisywana razem z zamówieniem, więc jest już w potwierdzeniu dla klienta i w powiadomieniu o nowym zamówieniu dla sklepu
- `inpost_shipment_id` - ID przesyłki w ShipX (po utworzeniu); na czas tworzenia stoi tu rezerwacja w postaci `pending:<znacznik czasu>`
- `inpost_tracking_number` - numer nadania

## Budowanie paczki i testy

```
php -d extension=zip build.php
php tests/run.php
```

`build.php` pakuje dokładnie to, co wymienia manifest, do `build/plg_inpost_hikashop-<wersja>.zip`.

Testy chodzą po prawdziwej instalacji Joomli z HikaShopem (ścieżka w zmiennej `JOOMLA_PATH`), z wtyczką zainstalowaną z tej paczki. Zakładają fikcyjnego klienta i zamówienia bez pozycji, a na końcu je kasują. Nie wysyłają poczty i nie łączą się z InPost: wywołania ShipX są podstawione.

## Struktura plików (Joomla 5/6)

```
plg_inpost_hika/
├── inpost_hika.php          # Legacy entry point
├── inpost_hika.xml          # Manifest instalacyjny
├── script.php               # Skrypt instalacyjny (porządkuje serwer aktualizacji)
├── index.html               # Plik bezpieczeństwa
├── services/
│   └── provider.php         # Service Provider (Joomla 5/6)
├── src/
│   └── Extension/
│       └── InpostHika.php   # Główna klasa pluginu
├── language/
│   ├── en-GB/
│   │   ├── en-GB.plg_hikashopshipping_inpost_hika.ini
│   │   └── en-GB.plg_hikashopshipping_inpost_hika.sys.ini
│   └── pl-PL/
│       ├── pl-PL.plg_hikashopshipping_inpost_hika.ini
│       └── pl-PL.plg_hikashopshipping_inpost_hika.sys.ini
├── tests/                   # Testy (poza paczką)
├── build.php                # Budowanie paczki (poza paczką)
└── README.md
```

## Licencja

GNU/GPLv3 - http://www.gnu.org/licenses/gpl-3.0.html

## Autor

Paweł Półtoraczyk - https://github.com/pablop76
