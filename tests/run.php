<?php
/**
 * Testy wtyczki InPost na zainstalowanej Joomli z HikaShopem.
 *
 * Uruchomienie:
 *   php tests/run.php
 *
 * Co jest prawdziwe: baza sklepu, zapis zamówienia przez HikaShopa, zdarzenia
 * wtyczek, treść wiadomości o zamówieniu. Co jest podstawione: wywołania API
 * InPost (żadne żądanie nie wychodzi do ShipX) i przekierowania.
 *
 * Żadna wiadomość nie jest wysyłana: zamówienie powstaje z wyłączoną wysyłką,
 * a treść potwierdzenia jest tylko budowana i czytana.
 *
 * Dane testowe (klient, adres, zamówienia bez pozycji) są fikcyjne i kasowane
 * na końcu, także przy błędzie.
 */

require_once __DIR__ . '/bootstrap-joomla.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Pablop76\Plugin\HikashopShipping\InpostHika\Extension\InpostHika;

$zdane = 0;
$bledy = 0;

function wynik(string $opis, bool $ok, string $szczegol = ''): void
{
    global $zdane, $bledy;

    if ($ok) {
        $zdane++;
        echo '  OK   ' . $opis . PHP_EOL;

        return;
    }

    $bledy++;
    echo '  BLAD ' . $opis . ($szczegol !== '' ? ': ' . $szczegol : '') . PHP_EOL;
}

function naglowek(string $tytul): void
{
    echo PHP_EOL . $tytul . PHP_EOL;
}

/**
 * Wtyczka z podstawionym API InPost. Nic nie wychodzi do sieci.
 */
final class WtyczkaTestowa extends InpostHika
{
    /** @var list<array{metoda: string, adres: string}> */
    public array $wywolaniaApi = [];

    /** @var list<mixed> kolejne odpowiedzi na POST (tworzenie przesyłki) */
    public array $odpowiedziPost = [];

    public ?object $odpowiedzGet = null;

    /** @var list<int> */
    public array $przekierowania = [];

    public bool $zaplecze = true;

    public ?object $parametryDostawy = null;

    public string $plikDziennika = '';

    protected function callShipXApi($method, $endpoint, $data = null, $shippingParams = null, $rawResponse = false)
    {
        $this->wywolaniaApi[] = ['metoda' => (string) $method, 'adres' => (string) $endpoint];

        if ($method === 'POST') {
            return array_shift($this->odpowiedziPost);
        }

        if ($method === 'GET') {
            return $this->odpowiedzGet;
        }

        return null;
    }

    protected function redirectToOrder($orderId)
    {
        $this->przekierowania[] = (int) $orderId;
    }

    protected function isAdminContext()
    {
        return $this->zaplecze;
    }

    protected function getShippingParamsForOrder($order)
    {
        return $this->parametryDostawy ?? parent::getShippingParamsForOrder($order);
    }

    protected function resolveDebugLogPath()
    {
        return $this->plikDziennika !== '' ? $this->plikDziennika : parent::resolveDebugLogPath();
    }

    protected function fetchTrackingNumberWithRetry($shipmentId, $shippingParams, $maxAttempts = 4, $delayMs = 1200)
    {
        return null;
    }

    public function ileWywolan(string $metoda): int
    {
        return count(array_filter($this->wywolaniaApi, static fn (array $w): bool => $w['metoda'] === $metoda));
    }

    public function zarezerwuj($orderId)
    {
        return $this->claimShipmentSlot($orderId);
    }

    public function zwolnij($orderId, $claim = null)
    {
        return $this->releaseShipmentClaim($orderId, $claim);
    }

    public function utworz($order, $kod, $parametry)
    {
        $this->handleCreateShipment($order, $kod, $parametry, 'small');
    }

    public function odmowa($wynik)
    {
        return $this->isDefiniteRefusal($wynik);
    }

    public function zapiszDebug($komunikat, $dane, $parametry)
    {
        $this->debug($komunikat, $dane, $parametry);
    }
}

function nowaWtyczka(): WtyczkaTestowa
{
    $dispatcher = Factory::getContainer()->get('dispatcher');

    return new WtyczkaTestowa($dispatcher, ['name' => 'inpost_hika', 'type' => 'hikashopshipping']);
}

// --- przygotowanie ---------------------------------------------------

$pdo    = polaczenieZBaza();
$prefix = przedrostekTabel();
$app    = Factory::getApplication();

$idMetody = (int) $pdo->query(
    "SELECT shipping_id FROM {$prefix}hikashop_shipping WHERE shipping_type = 'inpost_hika'
     ORDER BY shipping_published DESC, shipping_id ASC LIMIT 1"
)->fetchColumn();

if ($idMetody <= 0) {
    fwrite(STDERR, 'Brak metody dostawy inpost_hika w HikaShopie.' . PHP_EOL);
    exit(2);
}

$znacznik   = bin2hex(random_bytes(4));
$paczkomat  = 'TST' . strtoupper(substr($znacznik, 0, 3)) . 'M - Testowa 1, Testowo';
$kod        = strtok($paczkomat, ' ');
$waluta     = (int) hikashop_config()->get('main_currency', 1);

$pdo->prepare("
    INSERT INTO {$prefix}hikashop_user (user_cms_id, user_email, user_partner_email, user_params)
    VALUES (0, :email, '', '')
")->execute([':email' => 'test-inpost-' . $znacznik . '@example.invalid']);
$idKlienta = (int) $pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO {$prefix}hikashop_address
        (address_user_id, address_type, address_firstname, address_lastname, address_street,
         address_post_code, address_city, address_telephone, address_published)
    VALUES (:klient, 'shipping', 'Jan', 'Testowy', 'Testowa 1', '00-001', 'Testowo', '600100200', 1)
")->execute([':klient' => $idKlienta]);
$idAdresu = (int) $pdo->lastInsertId();

// Zamówienie do testów ShipX: wstawione wprost, bez pozycji (bez wpływu na magazyn).
$pdo->prepare("
    INSERT INTO {$prefix}hikashop_order
        (order_number, order_user_id, order_created, order_modified, order_status, order_type,
         order_full_price, order_currency_id, order_shipping_id, order_shipping_method,
         order_shipping_address_id, order_billing_address_id, order_token, inpost_locker)
    VALUES
        (:numer, :klient, :teraz, :teraz, 'confirmed', 'sale',
         12.34, :waluta, :metoda, 'inpost_hika', :adres, :adres, :token, :paczkomat)
")->execute([
    ':numer'     => 'INPTEST' . strtoupper($znacznik),
    ':klient'    => $idKlienta,
    ':teraz'     => time(),
    ':waluta'    => $waluta,
    ':metoda'    => $idMetody,
    ':adres'     => $idAdresu,
    ':token'     => bin2hex(random_bytes(16)),
    ':paczkomat' => $paczkomat,
]);
$idShipx = (int) $pdo->lastInsertId();

$idNowego      = 0;
$katalogLogow  = sys_get_temp_dir() . '/inpost_test_' . $znacznik;
$stanSesji     = $app->getUserState('hikashop.inpost_locker', '');

register_shutdown_function(static function () use ($pdo, $prefix, $idKlienta, $idAdresu, $idShipx, &$idNowego, $katalogLogow, $app, $stanSesji): void {
    foreach (array_filter([$idShipx, $idNowego]) as $id) {
        $pdo->prepare("DELETE FROM {$prefix}hikashop_order WHERE order_id = :id")->execute([':id' => $id]);
        $pdo->prepare("DELETE FROM {$prefix}hikashop_history WHERE history_order_id = :id")->execute([':id' => $id]);
        $pdo->prepare("DELETE FROM {$prefix}hikashop_order_product WHERE order_id = :id")->execute([':id' => $id]);
    }

    $pdo->prepare("DELETE FROM {$prefix}hikashop_address WHERE address_id = :id")->execute([':id' => $idAdresu]);
    $pdo->prepare("DELETE FROM {$prefix}hikashop_user WHERE user_id = :id")->execute([':id' => $idKlienta]);

    foreach ((array) glob($katalogLogow . '/*') as $plik) {
        @unlink($plik);
    }
    @rmdir($katalogLogow);

    $app->setUserState('hikashop.inpost_locker', $stanSesji);
});

$numerPrzesylki = static function (int $orderId) use ($pdo, $prefix) {
    $q = $pdo->prepare("SELECT inpost_shipment_id FROM {$prefix}hikashop_order WHERE order_id = :id");
    $q->execute([':id' => $orderId]);

    return $q->fetchColumn();
};

$ustawNumer = static function (int $orderId, ?string $wartosc) use ($pdo, $prefix): void {
    $pdo->prepare("UPDATE {$prefix}hikashop_order SET inpost_shipment_id = :w WHERE order_id = :id")
        ->execute([':w' => $wartosc, ':id' => $orderId]);
};

echo 'Joomla: ' . JPATH_BASE . ', metoda dostawy: ' . $idMetody . ', paczkomat testowy: ' . $kod . PHP_EOL;

// --- 1. Paczkomat w zamówieniu od chwili jego utworzenia -------------------

naglowek('1. Paczkomat zapisany razem z zamówieniem (pierwsze wiadomości)');

// Zdarzenia obsługuje wtyczka zainstalowana w witrynie, tak jak przy prawdziwym zakupie.
PluginHelper::importPlugin('hikashopshipping');

$orderClass = hikashop_get('class.order');
$orderClass->sendEmailAfterOrderCreation = false;

$budujZamowienie = static function () use ($idKlienta, $waluta, $idMetody, $idAdresu): stdClass {
    $zamowienie                            = new stdClass();
    $zamowienie->order_user_id             = $idKlienta;
    $zamowienie->order_status              = 'created';
    $zamowienie->order_type                = 'sale';
    $zamowienie->order_currency_id         = $waluta;
    $zamowienie->order_full_price          = 12.34;
    $zamowienie->order_shipping_id         = $idMetody;
    $zamowienie->order_shipping_method     = 'inpost_hika';
    $zamowienie->order_shipping_address_id = $idAdresu;
    $zamowienie->order_billing_address_id  = $idAdresu;
    $zamowienie->order_shipping_params         = new stdClass();
    $zamowienie->order_shipping_params->prices = [];

    return $zamowienie;
};

// Bez wybranego paczkomatu zamówienie nie może powstać.
$app->setUserState('hikashop.inpost_locker', '');
$bezPaczkomatu = $budujZamowienie();
$zapisane      = $orderClass->save($bezPaczkomatu);
wynik('bez wybranego paczkomatu zamówienie nie powstaje', empty($zapisane), 'zapisano ' . var_export($zapisane, true));

if (!empty($zapisane)) {
    $idNowego = (int) $zapisane;
}

$app->getMessageQueue(true);

$app->setUserState('hikashop.inpost_locker', $paczkomat);
$nowe     = $budujZamowienie();
$idNowego = (int) $orderClass->save($nowe);
wynik('z wybranym paczkomatem zamówienie powstaje', $idNowego > 0);

$q = $pdo->prepare("SELECT inpost_locker, order_shipping_params FROM {$prefix}hikashop_order WHERE order_id = :id");
$q->execute([':id' => $idNowego]);
$wiersz = $q->fetch(PDO::FETCH_ASSOC) ?: ['inpost_locker' => null, 'order_shipping_params' => ''];

wynik(
    'paczkomat jest w kolumnie zamówienia zaraz po zapisie',
    $wiersz['inpost_locker'] === $paczkomat,
    'w bazie: ' . var_export($wiersz['inpost_locker'], true)
);
wynik('paczkomat jest w parametrach dostawy zaraz po zapisie', str_contains((string) $wiersz['order_shipping_params'], $kod));
wynik('ceny dostawy w parametrach zostały', str_contains((string) $wiersz['order_shipping_params'], 'prices'));

// Treść potwierdzenia budujemy tak, jak robi to HikaShop w trakcie zapisu zamówienia,
// ale niczego nie wysyłamy.
$trescMaila = '';

try {
    $doMaila = $orderClass->get($idNowego);
    $orderClass->loadOrderNotification($doMaila, 'order_creation_notification');
    $trescMaila = (string) ($doMaila->mail->body ?? '');
} catch (Throwable $blad) {
    $trescMaila = '';
    echo '  (budowanie wiadomości: ' . $blad->getMessage() . ')' . PHP_EOL;
}

wynik('treść potwierdzenia zamówienia została zbudowana', strlen($trescMaila) > 500, 'długość ' . strlen($trescMaila));
wynik('potwierdzenie zamówienia dla klienta zawiera paczkomat', str_contains($trescMaila, $kod));

$trescDlaSklepu = '';

try {
    $doMaila = $orderClass->get($idNowego);
    $orderClass->loadOrderNotification($doMaila, 'order_admin_notification');
    $trescDlaSklepu = (string) ($doMaila->mail->body ?? '');
} catch (Throwable $blad) {
    echo '  (budowanie wiadomości dla sklepu: ' . $blad->getMessage() . ')' . PHP_EOL;
}

wynik('powiadomienie o nowym zamówieniu dla sklepu zawiera paczkomat', str_contains($trescDlaSklepu, $kod));

$app->setUserState('hikashop.inpost_locker', '');
$app->getMessageQueue(true);

// --- 2. Rezerwacja na czas tworzenia przesyłki ----------------------------

naglowek('2. Rezerwacja zamówienia na czas tworzenia przesyłki');

$wtyczka   = nowaWtyczka();
$pierwsza  = $wtyczka->zarezerwuj($idShipx);
$druga     = $wtyczka->zarezerwuj($idShipx);

wynik('pierwsza rezerwacja się udaje', is_string($pierwsza) && str_starts_with($pierwsza, 'pending:'));
wynik('druga rezerwacja tego samego zamówienia jest odrzucona', $druga === false);
wynik('w bazie stoi rezerwacja', $numerPrzesylki($idShipx) === $pierwsza);
wynik('cudzej rezerwacji nie da się zwolnić', $wtyczka->zwolnij($idShipx, 'pending:1') === false && $numerPrzesylki($idShipx) === $pierwsza);
wynik('własną rezerwację da się zwolnić', $wtyczka->zwolnij($idShipx, $pierwsza) === true && $numerPrzesylki($idShipx) === null);

$ustawNumer($idShipx, '555001');
wynik('zamówienia z numerem przesyłki nie da się zarezerwować', $wtyczka->zarezerwuj($idShipx) === false && $numerPrzesylki($idShipx) === '555001');
wynik('zwalnianie rezerwacji nie kasuje numeru przesyłki', $wtyczka->zwolnij($idShipx) === false && $numerPrzesylki($idShipx) === '555001');
$ustawNumer($idShipx, null);

wynik('odpowiedź 400 to odmowa', $wtyczka->odmowa((object) ['_httpCode' => 400, 'error' => 'validation_failed']) === true);
wynik('odpowiedź 401 to odmowa', $wtyczka->odmowa((object) ['_httpCode' => 401]) === true);
wynik('brak odpowiedzi to nie odmowa', $wtyczka->odmowa(null) === false);
wynik('odpowiedź 500 to nie odmowa', $wtyczka->odmowa((object) ['_httpCode' => 500]) === false);
wynik('odpowiedź 502 bez JSON to nie odmowa', $wtyczka->odmowa((object) ['_httpCode' => 502, '_raw' => '<html>']) === false);

// --- 3. Tworzenie przesyłki ----------------------------------------------

naglowek('3. Tworzenie przesyłki: brak odpowiedzi, odmowa, sukces, powtórne kliknięcie');

$parametry = (object) [
    'enable_shipx'          => 1,
    'api_mode'              => 'sandbox',
    'shipx_token'           => 'token-testowy',
    'shipx_organization_id' => '1',
    'debug'                 => 0,
    'default_parcel_size'   => 'small',
    'sending_method'        => 'parcel_locker',
    'require_confirmed'     => 1,
];

$zamowienie = $orderClass->get($idShipx);

// Brak odpowiedzi InPost: nie wiadomo, czy przesyłka powstała.
$w = nowaWtyczka();
$w->odpowiedziPost = [null];
$w->utworz($zamowienie, $kod, $parametry);
$komunikaty = implode(' | ', array_map(static fn ($k) => (string) $k['message'], $app->getMessageQueue(true)));

wynik('bez odpowiedzi InPost wtyczka woła API jeden raz', $w->ileWywolan('POST') === 1);
wynik('bez odpowiedzi InPost rezerwacja zostaje', str_starts_with((string) $numerPrzesylki($idShipx), 'pending:'), 'w bazie: ' . var_export($numerPrzesylki($idShipx), true));
wynik(
    'administrator czyta, że wynik jest nieznany',
    $komunikaty === Text::sprintf('PLG_HIKASHOPSHIPPING_INPOST_HIKA_CREATE_UNKNOWN', $idShipx)
        && str_contains($komunikaty, '#' . $idShipx) && !str_contains($komunikaty, 'PLG_HIKASHOPSHIPPING'),
    $komunikaty
);

// Kolejne kliknięcie przy rezerwacji: InPost nie jest wołany.
$w = nowaWtyczka();
$w->odpowiedziPost = [(object) ['id' => 999001, '_httpCode' => 201]];
$w->utworz($zamowienie, $kod, $parametry);
$app->getMessageQueue(true);

wynik('przy rezerwacji ponowne kliknięcie nie woła InPost', $w->ileWywolan('POST') === 0);
wynik('przy rezerwacji ponowne kliknięcie nie zapisuje przesyłki', str_starts_with((string) $numerPrzesylki($idShipx), 'pending:'));

$w->zwolnij($idShipx);

// Błąd serwera InPost: też nie wiadomo.
$w = nowaWtyczka();
$w->odpowiedziPost = [(object) ['_httpCode' => 500, '_raw' => 'Internal Server Error']];
$w->utworz($zamowienie, $kod, $parametry);
$app->getMessageQueue(true);

wynik('po błędzie 500 rezerwacja zostaje', str_starts_with((string) $numerPrzesylki($idShipx), 'pending:'));

$w->zwolnij($idShipx);

// Jednoznaczna odmowa: można próbować od razu jeszcze raz.
$w = nowaWtyczka();
$w->odpowiedziPost = [(object) ['error' => 'validation_failed', 'details' => (object) ['target_point' => ['does_not_exist']], '_httpCode' => 400]];
$w->utworz($zamowienie, $kod, $parametry);
$komunikaty = implode(' | ', array_map(static fn ($k) => (string) $k['message'], $app->getMessageQueue(true)));

wynik('po odmowie InPost rezerwacja jest zwolniona', $numerPrzesylki($idShipx) === null, 'w bazie: ' . var_export($numerPrzesylki($idShipx), true));
wynik('po odmowie administrator czyta powód', str_contains($komunikaty, 'paczkomatu nie istnieje'), $komunikaty);
wynik('po odmowie nie ma komunikatu o nieznanym wyniku', !str_contains($komunikaty, Text::sprintf('PLG_HIKASHOPSHIPPING_INPOST_HIKA_CREATE_UNKNOWN', $idShipx)));

// Sukces.
$w = nowaWtyczka();
$w->odpowiedziPost = [(object) ['id' => 777001, 'tracking_number' => 'TESTTRACK' . strtoupper($znacznik), '_httpCode' => 201]];
$w->utworz($zamowienie, $kod, $parametry);
$app->getMessageQueue(true);

wynik('po sukcesie numer przesyłki zastępuje rezerwację', (string) $numerPrzesylki($idShipx) === '777001', 'w bazie: ' . var_export($numerPrzesylki($idShipx), true));

// Kliknięcie przy istniejącej przesyłce.
$w = nowaWtyczka();
$w->odpowiedziPost = [(object) ['id' => 777002, '_httpCode' => 201]];
$w->utworz($zamowienie, $kod, $parametry);
$app->getMessageQueue(true);

wynik('przy istniejącej przesyłce InPost nie jest wołany', $w->ileWywolan('POST') === 0);
wynik('istniejąca przesyłka zostaje przy zamówieniu', (string) $numerPrzesylki($idShipx) === '777001');

// --- 4. Panel ShipX w zapleczu -------------------------------------------

naglowek('4. Panel ShipX: widok zamówienia, faktura, wiadomość');

$rysuj = static function (WtyczkaTestowa $wtyczka, object $zamowienie, string $typ): string {
    ob_start();
    $wtyczka->onAfterOrderProductsListingDisplay($zamowienie, $typ);

    return (string) ob_get_clean();
};

$zrownowazony = static fn (string $html): bool => substr_count($html, '<div') === substr_count($html, '</div>');

// Pytanie z okna potwierdzenia w tej postaci, w jakiej trafia do atrybutu onsubmit.
$pytanie = static fn (string $klucz, string ...$wartosci): string => htmlspecialchars(
    (string) json_encode(Text::sprintf($klucz, ...$wartosci)),
    ENT_QUOTES,
    'UTF-8'
);

$zamowienie = $orderClass->get($idShipx);

// Faktura w zapleczu: sama ramka z paczkomatem.
$w = nowaWtyczka();
$w->parametryDostawy = $parametry;
$w->odpowiedzGet     = (object) ['status' => 'confirmed', 'tracking_number' => 'TESTTRACK', '_httpCode' => 200];
$html = $rysuj($w, $zamowienie, 'order_back_invoice');

wynik('faktura: jest ramka z paczkomatem', str_contains($html, 'inpost_locker_info') && str_contains($html, $kod));
wynik('faktura: nie ma panelu ShipX ani przycisków', !str_contains($html, 'inpost_shipx_admin') && !str_contains($html, 'inpost_action'));
wynik('faktura: bez pytania API InPost', count($w->wywolaniaApi) === 0);

// Widok zamówienia, przesyłka potwierdzona (nie da się anulować).
$w = nowaWtyczka();
$w->parametryDostawy = $parametry;
$w->odpowiedzGet     = (object) ['status' => 'confirmed', 'tracking_number' => 'TESTTRACK', '_httpCode' => 200];
$html = $rysuj($w, $zamowienie, 'order_back_show');

wynik('widok zamówienia: jest panel ShipX', str_contains($html, 'inpost_shipx_admin') && str_contains($html, 'get_label'));
wynik('widok zamówienia: znaczniki div są zrównoważone', $zrownowazony($html), substr_count($html, '<div') . ' otwarć, ' . substr_count($html, '</div>') . ' zamknięć');
wynik('przesyłka potwierdzona: ostrzeżenie o drugiej płatnej przesyłce przed kliknięciem', str_contains($html, $pytanie('PLG_HIKASHOPSHIPPING_INPOST_HIKA_RECREATE_CONFIRM_PAID', '777001', 'confirmed')));

// Przesyłka jeszcze do anulowania.
$w = nowaWtyczka();
$w->parametryDostawy = $parametry;
$w->odpowiedzGet     = (object) ['status' => 'created', 'tracking_number' => 'TESTTRACK', '_httpCode' => 200];
$html = $rysuj($w, $zamowienie, 'order_back_show');

wynik('przesyłka niepotwierdzona: zwykłe pytanie o anulowanie', str_contains($html, $pytanie('PLG_HIKASHOPSHIPPING_INPOST_HIKA_RECREATE_CONFIRM', '777001')));
wynik('oba pytania mają różną treść', $pytanie('PLG_HIKASHOPSHIPPING_INPOST_HIKA_RECREATE_CONFIRM', '777001') !== $pytanie('PLG_HIKASHOPSHIPPING_INPOST_HIKA_RECREATE_CONFIRM_PAID', '777001', 'confirmed'));

// Rezerwacja.
$ustawNumer($idShipx, 'pending:' . (time() - 60));
$w = nowaWtyczka();
$w->parametryDostawy = $parametry;
$html = $rysuj($w, $zamowienie, 'order_back_show');

wynik('rezerwacja: jest przycisk odblokowania', str_contains($html, 'release_pending'));
wynik('rezerwacja: nie ma przycisku tworzenia przesyłki', !str_contains($html, 'create_shipment'));
wynik('rezerwacja: bez pytania API InPost', count($w->wywolaniaApi) === 0);
wynik('rezerwacja: znaczniki div są zrównoważone', $zrownowazony($html));

// Bez przesyłki.
$ustawNumer($idShipx, null);
$w = nowaWtyczka();
$w->parametryDostawy = $parametry;
$html = $rysuj($w, $zamowienie, 'order_back_show');

wynik('bez przesyłki: jest formularz tworzenia', str_contains($html, 'create_shipment'));
wynik('bez przesyłki: znaczniki div są zrównoważone', $zrownowazony($html));

// Wiadomość e-mail: sama ramka.
$w = nowaWtyczka();
$w->parametryDostawy = $parametry;
$html = $rysuj($w, $zamowienie, 'email_notification_html');

wynik('wiadomość: jest ramka, nie ma panelu ShipX', str_contains($html, 'inpost_locker_info') && !str_contains($html, 'inpost_shipx_admin'));

// Żądanie akcji przy zamówieniu z inną metodą dostawy.
$obce = clone $zamowienie;
$obce->order_shipping_method = 'manual';
$app->getInput()->set('inpost_action', 'release_pending');
$app->getInput()->set('order_id', $idShipx);
$ustawNumer($idShipx, 'pending:' . time());

$w = nowaWtyczka();
$w->parametryDostawy = $parametry;
$rysuj($w, $obce, 'order_back_show');

wynik('zamówienie z inną metodą dostawy: akcja wtyczki się nie wykonuje', str_starts_with((string) $numerPrzesylki($idShipx), 'pending:') && $w->przekierowania === []);

$app->getInput()->set('inpost_action', '');
$app->getInput()->set('order_id', 0);
$ustawNumer($idShipx, null);

// --- 5. Dziennik debug ----------------------------------------------------

naglowek('5. Dziennik debug chroniony przed pobraniem z sieci');

mkdir($katalogLogow, 0775, true);
$staryPlik = $katalogLogow . '/inpost_hika_debug.log';
$nowyPlik  = $katalogLogow . '/inpost_hika_debug.php';
file_put_contents($staryPlik, "stary wpis z danymi odbiorcy\n");

$w = nowaWtyczka();
$w->plikDziennika = $nowyPlik;
$w->zapiszDebug('nowy wpis', ['a' => 1], (object) ['debug' => 1]);
$w->zapiszDebug('drugi wpis', null, (object) ['debug' => 1]);

$tresc = is_file($nowyPlik) ? (string) file_get_contents($nowyPlik) : '';

wynik('dziennik zaczyna się od blokady die()', str_starts_with($tresc, "#\n#<?php die('Forbidden.'); ?>\n"));
wynik('blokada jest w pliku tylko raz', substr_count($tresc, "die('Forbidden.')") === 1);
wynik('stary dziennik został przeniesiony', str_contains($tresc, 'stary wpis z danymi odbiorcy'));
wynik('nowe wpisy są dopisywane', str_contains($tresc, 'nowy wpis') && str_contains($tresc, 'drugi wpis'));
wynik('stary plik .log został usunięty', !is_file($staryPlik));

$wykonany = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($nowyPlik));
wynik('plik otwarty przez serwer PHP nie oddaje treści', $wykonany !== '' && !str_contains($wykonany, 'wpis'), $wykonany);

$w = nowaWtyczka();
$w->plikDziennika = $katalogLogow . '/wylaczony.php';
$w->zapiszDebug('nie powinno powstać', null, (object) ['debug' => 0]);
wynik('przy wyłączonym debugowaniu nic się nie zapisuje', !is_file($katalogLogow . '/wylaczony.php'));

// --- 6. Manifest i tłumaczenia -------------------------------------------

naglowek('6. Manifest i pliki językowe');

$manifest = simplexml_load_file(__DIR__ . '/../inpost_hika.xml');
$wersja   = trim((string) $manifest->version);
$nazwa    = trim((string) $manifest->name);

wynik('nazwa w manifeście jest dosłowna, nie kluczem językowym', $nazwa !== '' && !preg_match('/^[A-Z0-9_]+$/', $nazwa), $nazwa);

$nazwaWBazie = (string) $pdo->query("SELECT name FROM {$prefix}extensions WHERE type = 'plugin' AND folder = 'hikashopshipping' AND element = 'inpost_hika'")->fetchColumn();
wynik('zainstalowana wtyczka ma w bazie nazwę dosłowną', $nazwaWBazie === $nazwa, $nazwaWBazie);

foreach (['inpost_hika.php', 'services/provider.php', 'src/Extension/InpostHika.php'] as $plik) {
    $naglowekPliku = (string) file_get_contents(__DIR__ . '/../' . $plik, false, null, 0, 400);
    wynik('wersja w ' . $plik . ' zgodna z manifestem', str_contains($naglowekPliku, '@version     ' . $wersja));
}

// Znak BOM na początku pliku PHP trafia na wyjście przy każdym jego dołączeniu:
// do 4.2.21 miał go plik startowy wtyczki, więc każde żądanie z HikaShopem dostawało
// trzy niewidoczne bajty w treści strony.
$zBom = [];

foreach (['inpost_hika.php', 'services/provider.php', 'src/Extension/InpostHika.php', 'inpost_hika.xml'] as $plik) {
    if (str_starts_with((string) file_get_contents(__DIR__ . '/../' . $plik, false, null, 0, 3), "\xEF\xBB\xBF")) {
        $zBom[] = $plik;
    }
}

wynik('pliki wtyczki nie zaczynają się od znaku BOM', $zBom === [], implode(', ', $zBom));

ob_start();
include JPATH_PLUGINS . '/hikashopshipping/inpost_hika/inpost_hika.php';
$wyjscie = (string) ob_get_clean();
wynik('dołączenie pliku startowego niczego nie wypisuje', $wyjscie === '', bin2hex($wyjscie));

$kod_ = (string) file_get_contents(__DIR__ . '/../src/Extension/InpostHika.php');
preg_match_all('/PLG_HIKASHOPSHIPPING_INPOST_HIKA_[A-Z0-9_]*[A-Z0-9]/', $kod_, $trafienia);
$klucze = array_values(array_unique($trafienia[0]));

foreach (['pl-PL', 'en-GB'] as $jezyk) {
    $ini    = (string) file_get_contents(__DIR__ . '/../language/' . $jezyk . '/' . $jezyk . '.plg_hikashopshipping_inpost_hika.ini');
    $braki  = [];

    foreach ($klucze as $klucz) {
        // Klucze składane w kodzie (np. ..._SIZE_ + rozmiar) kończą się przedrostkiem.
        if (preg_match('/_(SIZE|ST)$/', $klucz)) {
            continue;
        }

        // Plik angielski ma część starych wpisów bez przedrostka wtyczki: sprawdzamy w nim
        // tylko teksty dodane razem z rezerwacją.
        if ($jezyk === 'en-GB' && !preg_match('/_(PENDING|CREATE_UNKNOWN|RECREATE)/', $klucz)) {
            continue;
        }

        if (!preg_match('/^' . preg_quote($klucz, '/') . '=/m', $ini)) {
            $braki[] = $klucz;
        }
    }

    wynik('plik ' . $jezyk . ' ma wszystkie teksty używane w kodzie', $braki === [], implode(', ', $braki));
}

// --- podsumowanie ---------------------------------------------------------

echo PHP_EOL . 'Zdane: ' . $zdane . ', błędy: ' . $bledy . PHP_EOL;

exit($bledy === 0 ? 0 : 1);
