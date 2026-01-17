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

### Inne

- ✅ Wyświetlanie paczkomatu w panelu admina (szczegóły zamówienia)
- ✅ Tryb debug (logowanie do pliku)

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
| Tryb debug              | Logowanie do pliku                                                | Nie       |

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

## Użycie Google Maps

1. Uzyskaj klucz API z [Google Cloud Console](https://console.cloud.google.com/)
2. Włącz Maps JavaScript API
3. Wklej klucz w ustawieniach pluginu
4. Zmień "Typ mapy" na "Google Maps"

## Tryb Debug

Gdy włączony, plugin zapisuje logi do pliku:

```
/logs/inpost_hika_debug.log
```

Logowane są: wybór paczkomatu, zapis do bazy, potwierdzenie zamówienia, wywołania API ShipX.

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
├── plg_hikashop_inpost_display/  # Dodatkowy plugin (opcjonalny)
│   ├── inpost_display.php
│   ├── inpost_display.xml
│   ├── services/
│   │   └── provider.php
│   └── src/
│       └── Extension/
│           └── InpostDisplay.php
└── README.md
```
## Licencja

GNU/GPLv3 - http://www.gnu.org/licenses/gpl-3.0.html

## Autor

Paweł Półtoraczyk - https://github.com/pablop76
