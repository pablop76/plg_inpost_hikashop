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
4. Jeśli zamówienie ma status "confirmed" - przesyłka zostanie automatycznie opłacona
5. Jeśli zamówienie nie jest potwierdzone - kliknij **"Opłać przesyłkę"** gdy będzie gotowe
6. Po opłaceniu pojawi się przycisk **"Pobierz etykietę"**

### Logika tworzenia etykiet

- Przesyłka wymaga ręcznego utworzenia w zamówieniu Hikashop
- Etykieta PDF dostępna tylko PO UTWORZENIU PRZESYŁKI

### Obsługiwane rozmiary paczek

| Rozmiar     | Wymiary (dł/szer/wys) | Waga max |
| ----------- | --------------------- | -------- |
| Mała (A)    | 380 x 640 x 80 mm     | 25 kg    |
| Średnia (B) | 380 x 640 x 190 mm    | 25 kg    |
| Duża (C)    | 410 x 380 x 640 mm    | 25 kg    |

## Tryb Debug

Plugin obsługuje dwa rodzaje debugowania:

### Debug do pliku
Gdy włączony, plugin zapisuje logi do pliku:

```
/logs/inpost_hika_debug.log
```

Logowane są: wybór paczkomatu, zapis do bazy, potwierdzenie zamówienia, wywołania API ShipX.

### Debug na zapleczu
Gdy włączony, wyświetla pełne odpowiedzi API bezpośrednio w panelu admina (przydatne do diagnozowania problemów).

## Baza danych

Plugin automatycznie tworzy kolumny w tabeli `#__hikashop_order`:

- `inpost_locker` - nazwa wybranego paczkomatu
- `inpost_shipment_id` - ID przesyłki w ShipX (po utworzeniu)

## Struktura plików (Joomla 5/6)

```
plg_inpost_hika/
├── inpost_hika.php          # Legacy entry point
├── inpost_hika.xml          # Manifest instalacyjny
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
└── README.md
```

## Changelog

### v4.2.8 (2026-07-04)

- **BUGFIX (anulowanie przesyłki)**: Poprawiono endpoint anulowania na `DELETE /v1/shipments/{id}`
  (zgodnie z oficjalną wtyczką InPost dla WooCommerce). Wcześniej wtyczka wołała nieistniejący
  `POST /v1/shipments/{id}/cancel` — anulowanie **nigdy** się nie udawało, co przyczyniało się do
  osieroconych przesyłek. Dodano obsługę metod `DELETE`/`PUT` w kliencie HTTP (`callShipXApi`).
  Uwaga: InPost i tak nie pozwala anulować przesyłki o statusie `confirmed` (reguła biznesowa).
- Pobieranie etykiety: `format=Pdf` (wcześniej `pdf`) — zgodnie z oficjalną wtyczką.

### v4.2.7 (2026-07-04)

- **NOWOŚĆ**: Przycisk „Utwórz ponownie" jest teraz dostępny także dla **opłaconej** (potwierdzonej)
  przesyłki — obok „Pobierz etykietę". Wcześniej dało się odtworzyć tylko przesyłkę nieopłaconą,
  więc po potwierdzeniu nie było jak zmienić np. rozmiaru paczki czy paczkomatu bez ręcznego
  grzebania w bazie. Przycisk ma potwierdzenie (JS `confirm`), bo anuluje istniejącą przesyłkę.
- `handleRecreateShipment()` raportuje teraz, **czy** anulowanie w InPost faktycznie się powiodło —
  jeśli InPost odmówił (przesyłka nadana/odebrana), pokazuje ostrzeżenie, żeby sprawdzić ją ręcznie
  w Managerze Paczek (unikniesz podwójnej opłaty).

### v4.2.6 (2026-07-04)

- **NOWOŚĆ**: Wybór rozmiaru paczki (Mała A / Średnia B / Duża C) **per zamówienie** przy tworzeniu
  przesyłki w panelu admina. Wcześniej rozmiar był brany wyłącznie z globalnej konfiguracji
  (`Domyślny rozmiar paczki`) i nie dało się go dobrać do konkretnego zamówienia. Selektor
  domyślnie wskazuje rozmiar z konfiguracji, komunikat potwierdzający pokazuje użyty rozmiar.
- Uzupełniono brakujące klucze językowe rozmiarów w pliku en-GB.

### v4.2.5 (2026-07-04)

- **BUGFIX (kluczowy)**: Naprawiono tworzenie WIELU przesyłek ShipX dla jednego zamówienia przy
  powtarzanych próbach. Przyczyna: InPost przygotowuje oferty przewozowe **asynchronicznie** —
  wtyczka sprawdzała je natychmiast po utworzeniu przesyłki, nie znajdowała, uznawała to za błąd
  opłacenia, po czym **kasowała `inpost_shipment_id`** i (nieskutecznie) anulowała przesyłkę.
  To rozbrajało zabezpieczenie przed duplikatami z v4.2.2 — kolejne kliknięcie tworzyło nową
  przesyłkę. Zmiany:
  - `buyShipmentOffer()` **odpytuje oferty z ponawianiem** (do 5 prób co 2 s) zanim uzna, że ich nie ma
  - przy niegotowych ofertach / braku środków przesyłka **NIE jest już kasowana** — ID zostaje
    zapisane, więc guard blokuje duplikaty, a użytkownik może dokończyć płatność
  - dodano brakujący przycisk **„Opłać przesyłkę"** w panelu zamówienia (obok „Utwórz ponownie")
    dla przesyłki utworzonej, ale jeszcze nieopłaconej (handler `buy_shipment` istniał, ale nie
    był podpięty do żadnego przycisku)

### v4.2.4 (2026-07-04)

- **BUGFIX**: Poprawka z v4.2.3 (`services/provider.php`) nie usuwała błędu `Class
  "hikashopShippingPlugin" not found` we wszystkich przypadkach — guard tam obejmował tylko
  ścieżkę, w której to *nasz* kod tworzy instancję wtyczki. Guard przeniesiony do
  `src/Extension/InpostHika.php`, tuż przed deklaracją klasy — działa niezależnie od tego, co
  dokładnie wyzwala autoload tego pliku (nasz kod, Joomla, cokolwiek innego)

### v4.2.3 (2026-07-04)

- **BUGFIX**: Naprawiono błąd `Class "hikashopShippingPlugin" not found` pojawiający się w
  niektórych kontekstach (np. podczas aktualizacji wtyczki w Menedżerze Rozszerzeń Joomla).
  HikaShop rejestruje klasę bazową `hikashopShippingPlugin` leniwie (przy pierwszym załadowaniu
  jego `helper.php`) — jeśli to jeszcze nie nastąpiło w danym żądaniu, `services/provider.php`
  teraz dociąga ten plik ręcznie, zanim spróbuje utworzyć instancję wtyczki

### v4.2.2 (2026-07-04)

- **BUGFIX**: Zabezpieczono `handleCreateShipment()` przed utworzeniem kilku przesyłek ShipX
  dla tego samego zamówienia (np. przy podwójnym kliknięciu przycisku "Utwórz przesyłkę" albo
  ponownych próbach po błędzie "kod paczkomatu niepoprawny") — teraz sprawdza, czy zamówienie
  nie ma już zapisanego `inpost_shipment_id`, zanim wywoła API
- Scentralizowano odczyt `inpost_shipment_id` do wspólnej metody `getShipmentIdForOrder()`
  (wcześniej to samo zapytanie SQL powielone w 4 miejscach)

### v4.2.1 (2026-07-04)

- **BUGFIX**: Naprawiono ścieżkę do `map.html` (stare API GeoWidget) hardkodowaną od głównego
  katalogu domeny (`/plugins/...`) — łamała instalacje Joomla w podkatalogu. Teraz budowana
  z `Uri::root()`
- **BUGFIX**: Naprawiono ryzyko nadpisania `order_shipping_params` wartością `false` w
  `onAfterOrderConfirm`, gdy dane nie były poprawnym zserializowanym obiektem
- Dodano `map.html` do manifestu (`<files>`) — plik był wymagany w runtime, ale nie był
  instalowany u użytkowników
- Usunięto nieaktualną sekcję README o integracji Google Maps (funkcja nie istnieje od v4.0.0)
  i martwe pola `map_type`/`google_api_key`
- Refaktor: ujednolicono 3 zduplikowane bloki wyszukiwania wybranego paczkomatu do wspólnych
  metod `findSelectedLocker()`/`extractLockerFromCartParams()`
- Wzmocniono escapowanie wartości konfiguracyjnych wstrzykiwanych do inline `<script>` (JSON
  zamiast `addslashes()`)

### v4.2.0 (2026-01-20)

- **BUGFIX**: Naprawiono utratę wybranego paczkomatu podczas zmiany metody płatności w checkout
  - Plugin teraz szuka danych paczkomatu w wielu źródłach (cart_params, order_shipping_params, session)
  - Rozwiązuje problem, gdy zmiana płatności (np. PayU) powodowała odświeżenie strony i utratę sesji
- Poprawiono metody `onShippingDisplay`, `onAfterOrderConfirm`, `onBeforeOrderCreate`

### v4.1.0 (2026-01-17)

- **NOWOŚĆ**: Opcja "Wymagaj potwierdzenia zamówienia" - ogranicza tworzenie przesyłek do statusów confirmed/shipped
- **NOWOŚĆ**: Opcja "Debug na zapleczu" - wyświetlanie odpowiedzi API w panelu admina
- Poprawki tłumaczeń polskich

### v4.0.0 (2026-01-13)

- **BREAKING**: Pełna kompatybilność z Joomla 5/6
- Migracja do namespace `Pablop76\Plugin\HikashopShipping\InpostHika`
- Dodano `services/provider.php` (Service Provider)
- Zamieniono przestarzałe klasy JFactory, JText na nowe API Joomla
- Struktura katalogów zgodna z Joomla 5/6 (`src/Extension/`)
- Wymagane PHP 8.1+

### v3.0.0 (2026-01-13)

- **NOWOŚĆ**: Pełna integracja ShipX API
- Tworzenie przesyłek InPost z panelu admina
- Opłacanie przesyłek (automatyczne dla potwierdzonych zamówień)
- Pobieranie etykiet PDF
- Konfiguracja danych nadawcy
- Wybór domyślnego rozmiaru paczki
- Automatyczne łączenie z danymi odbiorcy z zamówienia
- Obsługa środowiska Sandbox i Produkcji
- Przyjazne komunikaty błędów (np. nieistniejący paczkomat)
- Sekcja ShipX widoczna tylko w adminie (nie w emailach do klienta)

### v2.1.0 (2026-01-13)

- Dodano tryb API (Produkcja/Sandbox) - przygotowanie pod ShipX
- Dodano tryb debug (logowanie do pliku)
- Automatyczny zoom mapy zależny od typu (OSM:13, Google:6)
- Poprawki tłumaczeń

### v2.0.0 (2026-01-12)

- Dodano wybór typu mapy (OSM/Google)
- Dodano konfigurację domyślnej lokalizacji i zoom
- Dodano konfigurację typów punktów (paczkomaty/POP)
- Wyświetlanie paczkomatu w szczegółach zamówienia (admin)
- Walidacja wyboru przed złożeniem zamówienia

### v1.0.0

- Pierwsza wersja z podstawową funkcjonalnością

## Licencja

GNU/GPLv3 - http://www.gnu.org/licenses/gpl-3.0.html

## Autor

Paweł Półtoraczyk - https://github.com/pablop76
