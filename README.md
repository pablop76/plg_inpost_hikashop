# InPost Paczkomaty - HikaShop Shipping Plugin

Plugin wysyłkowy dla HikaShop (Joomla) integrujący InPost Paczkomaty z mapą GeoWidget.

## Funkcje

- ✅ Wybór paczkomatu na mapie (GeoWidget SDK)
- ✅ Obsługa paczkomatów i punktów POP
- ✅ Zapis wybranego paczkomatu w zamówieniu
- ✅ Walidacja - blokada zamówienia bez wybranego punktu
- ✅ Wyświetlanie paczkomatu w panelu admina (szczegóły zamówienia)
- ✅ Konfiguracja typu mapy: OpenStreetMap lub Google Maps
- ✅ Konfigurowalne współrzędne i zoom mapy

## Wymagania

- Joomla 4.x / 5.x
- HikaShop 4.x / 5.x
- PHP 7.4+

## Instalacja

1. Pobierz paczkę ZIP z tego repozytorium
2. W panelu Joomla: **System → Rozszerzenia → Instaluj**
3. Wgraj plik ZIP
4. Włącz plugin: **System → Wtyczki → InPost Paczkomaty**

## Konfiguracja

1. Przejdź do **Komponenty → HikaShop → Konfiguracja → Wysyłka**
2. Kliknij **Nowy** i wybierz **InPost**
3. Skonfiguruj opcje:

| Opcja | Opis | Domyślnie |
|-------|------|-----------|
| Typ mapy | OpenStreetMap lub Google Maps | OSM |
| Klucz API Google | Wymagany dla Google Maps | - |
| Szerokość geogr. (lat) | Domyślna pozycja mapy | 52.2297 |
| Długość geogr. (lng) | Domyślna pozycja mapy | 21.0122 |
| Domyślny zoom | Poziom przybliżenia | 14 |
| Pokaż paczkomaty | Włącz paczkomaty | Tak |
| Pokaż punkty POP | Włącz punkty POP | Nie |

4. Ustaw cenę wysyłki, strefę i inne standardowe opcje HikaShop
5. Zapisz

## Użycie Google Maps

1. Uzyskaj klucz API z [Google Cloud Console](https://console.cloud.google.com/)
2. Włącz Maps JavaScript API
3. Wklej klucz w ustawieniach pluginu
4. Zmień "Typ mapy" na "Google Maps"

## Baza danych

Plugin automatycznie tworzy kolumnę `inpost_locker` w tabeli `#__hikashop_order` przy pierwszym użyciu.

## Struktura plików

```
plg_inpost_hika/
├── inpost_hika.php          # Główny plik pluginu
├── inpost_hika.xml          # Manifest instalacyjny
├── index.html               # Plik bezpieczeństwa
├── language/
│   ├── en-GB/
│   │   ├── plg_hikashopshipping_inpost_hika.ini
│   │   └── plg_hikashopshipping_inpost_hika.sys.ini
│   └── pl-PL/
│       ├── plg_hikashopshipping_inpost_hika.ini
│       └── plg_hikashopshipping_inpost_hika.sys.ini
└── README.md
```

## Changelog

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

Developer - https://example.com
