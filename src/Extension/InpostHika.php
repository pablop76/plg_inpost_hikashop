<?php
/**
 * @package     HikaShop InPost Paczkomaty Shipping Plugin
 * @version     4.2.20
 * @copyright   (C) 2026
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Pablop76\Plugin\HikashopShipping\InpostHika\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die('Restricted access');

// HikaShop definiuje hikashopShippingPlugin leniwie: dopiero pierwsze załadowanie jego
// helper.php rejestruje spl_autoload_register dla tej klasy. W kontekstach, gdzie HikaShop
// jeszcze nie "wystartował" w danym żądaniu (np. podczas instalacji/aktualizacji tej wtyczki
// w Menedżerze Rozszerzeń), ta klasa bazowa nie jest jeszcze dostępna. Ten guard musi być
// w TYM pliku, przed deklaracją klasy - PHP odkłada kompilację "class X extends Y" do momentu
// wykonania tej linii, więc wystarczy załadować helper.php wcześniej w tym samym pliku,
// niezależnie od tego, co dokładnie wywołało autoload InpostHika (nasz services/provider.php,
// czy jakikolwiek inny kod Joomli/HikaShop).
if (!class_exists('hikashopShippingPlugin', false)) {
	$inpostHikaHelperPath = JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php';
	if (is_file($inpostHikaHelperPath)) {
		require_once $inpostHikaHelperPath;
	}
}

class InpostHika extends \hikashopShippingPlugin
{
	// GeoWidget - stare API (bez tokena) i nowe API v5
	const GEO_WIDGET_JS_OLD = 'https://geowidget.easypack24.net/js/sdk-for-javascript.js';
	const GEO_WIDGET_CSS_OLD = 'https://geowidget.easypack24.net/css/easypack.css';
	// GeoWidget v5 - produkcja
	const GEO_WIDGET_JS = 'https://geowidget.inpost.pl/inpost-geowidget.js';
	const GEO_WIDGET_CSS = 'https://geowidget.inpost.pl/inpost-geowidget.css';
	// GeoWidget v5 - sandbox
	const GEO_WIDGET_JS_SANDBOX = 'https://sandbox-easy-geowidget-sdk.easypack24.net/inpost-geowidget.js';
	const GEO_WIDGET_CSS_SANDBOX = 'https://sandbox-easy-geowidget-sdk.easypack24.net/inpost-geowidget.css';
	
	// ShipX API - Produkcja
	const SHIPX_API_URL = 'https://api-shipx-pl.easypack24.net';
	// ShipX API - Sandbox
	const SHIPX_API_URL_SANDBOX = 'https://sandbox-api-shipx-pl.easypack24.net';

	public $multiple = true;
	public $name = 'inpost_hika';
	public $doc_form = 'inpost_hika';

	protected $orderFieldName = 'inpost_locker';

	// Definicja pól konfiguracyjnych dla HikaShop
	public $pluginConfig = array(
		// ShipX - włącznik i tryb API
		'enable_shipx' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_ENABLE_SHIPX', 'boolean', '0'),
		'api_mode' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_API_MODE', 'list', array(
			'production' => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_API_PRODUCTION',
			'sandbox' => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_API_SANDBOX'
		)),
		// ShipX API
		'shipx_token' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SHIPX_TOKEN', 'textarea'),
		'shipx_organization_id' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SHIPX_ORGANIZATION_ID', 'input', ''),
		// Dane nadawcy (wymagane do tworzenia przesyłek)
		'sender_name' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SENDER_NAME', 'input', ''),
		'sender_company' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SENDER_COMPANY', 'input', ''),
		'sender_email' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SENDER_EMAIL', 'input', ''),
		'sender_phone' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SENDER_PHONE', 'input', ''),
		'sender_street' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SENDER_STREET', 'input', ''),
		'sender_building' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SENDER_BUILDING', 'input', ''),
		'sender_city' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SENDER_CITY', 'input', ''),
		'sender_postcode' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SENDER_POSTCODE', 'input', ''),
		// Domyślny rozmiar paczki
		'default_parcel_size' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_PARCEL_SIZE', 'list', array(
			'small' => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_SIZE_SMALL',
			'medium' => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_SIZE_MEDIUM',
			'large' => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_SIZE_LARGE'
		)),
		// Format etykiety: normal (A4, drukarka laserowa/atramentowa) lub A6 (drukarka termiczna/etykietowa)
		'label_type' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_LABEL_TYPE', 'list', array(
			'normal' => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_LABEL_TYPE_NORMAL',
			'A6' => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_LABEL_TYPE_A6'
		)),
		// Sposób nadania paczki (ShipX custom_attributes.sending_method)
		'sending_method' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SENDING_METHOD', 'list', array(
			'parcel_locker' => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_SENDING_PARCEL_LOCKER',
			'dispatch_order' => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_SENDING_DISPATCH_ORDER',
			'pop' => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_SENDING_POP'
		)),
		// Mapa GeoWidget - wybór API
		'geowidget_api' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_GEOWIDGET_API', 'list', array(
			'old' => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_GEOWIDGET_API_OLD',
			'v5' => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_GEOWIDGET_API_V5',
			'v5_sandbox' => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_GEOWIDGET_API_V5_SANDBOX'
		)),
		'geowidget_token' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_GEOWIDGET_TOKEN', 'input', ''),
		'geowidget_config' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_GEOWIDGET_CONFIG', 'list', array(
			'parcelCollect' => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_CONFIG_COLLECT',
			'parcelCollectPayment' => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_CONFIG_COLLECT_PAYMENT',
			'parcelCollect247' => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_CONFIG_COLLECT_247',
			'parcelSend' => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_CONFIG_SEND'
		)),
		'default_lat' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_DEFAULT_LAT', 'input', '52.2297'),
		'default_lng' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_DEFAULT_LNG', 'input', '21.0122'),
		'default_zoom' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_DEFAULT_ZOOM', 'input', ''),
		'show_parcel_lockers' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SHOW_LOCKERS', 'boolean', '1'),
		'show_pops' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SHOW_POPS', 'boolean', '0'),
		'debug' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_DEBUG', 'boolean', '0'),
		'debug_admin' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_DEBUG_ADMIN', 'boolean', '0'),
		// Wymagaj potwierdzenia zamówienia przed utworzeniem przesyłki
		'require_confirmed' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_REQUIRE_CONFIRMED', 'boolean', '1'),
	);

	public function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);
		$this->loadLanguage();
		
		$app = Factory::getApplication();
		$input = $app->input;
		
		// Obsługa AJAX zapisu wyboru paczkomatu (frontend)
		$lockerSave = $input->post->getString('inpost_locker_save', '');
		if ($lockerSave !== '') {
			$app->setUserState('hikashop.inpost_locker', $lockerSave);
		}
	}

	/**
	 * Wyświetla informację o paczkomacie po liście produktów w zamówieniu
	 * Event z Display API HikaShop
	 * $type może być: 'order_back_show', 'order_back_invoice', 'email_notification_html'
	 */
	public function onAfterOrderProductsListingDisplay(&$order, $type)
	{
		// Sprawdź czy to kontekst emaila
		$isEmail = (strpos($type, 'email') !== false);
		
		// Sprawdź czy to admin
		$app = Factory::getApplication();
		$isAdmin = $app->isClient('administrator') && !$isEmail;
		
		// Obsługa akcji AJAX w adminie (tworzenie przesyłki, pobieranie etykiety) - PRZED wyświetlaniem
		if ($isAdmin) {
			$this->handleAdminAjaxActions($order);
		}
		
		// Sprawdź czy zamówienie ma metodę InPost
		if (empty($order->order_shipping_method) || $order->order_shipping_method !== $this->name) {
			return;
		}
		
		// Upewnij się że kolumny/pole istnieją (tylko nie w emailu)
		if ($isAdmin) {
			$this->ensureShipmentIdFieldExists();
			// Pole HikaShop `inpost_locker` zakładaliśmy dotąd tylko na checkoucie i przy zapisie
			// konfiguracji metody. Zakładamy je również, gdy admin po prostu otworzy zamówienie -
			// dzięki temu wiersz paczkomatu pojawia się w natywnej sekcji "Dodatkowe informacje"
			// (po jednym odświeżeniu) nawet na sklepie, na którym wcześniejsza, wadliwa wersja
			// wtyczki nie zdążyła go poprawnie założyć.
			$this->ensureOrderFieldExists();
		}

		// Pobierz paczkomat z bazy (bo może nie być w obiekcie $order)
		$locker = '';
		if (!empty($order->inpost_locker)) {
			$locker = $order->inpost_locker;
		} else {
			// Pobierz z bazy danych
			$db = Factory::getContainer()->get(DatabaseInterface::class);
			$query = $db->getQuery(true)
				->select($db->quoteName('inpost_locker'))
				->from($db->quoteName('#__hikashop_order'))
				->where($db->quoteName('order_id') . ' = ' . (int)$order->order_id);
			$db->setQuery($query);
			$locker = $db->loadResult();
		}

		// Backfill dla starszych zamówień: paczkomat bywa zapisany tylko w order_shipping_params,
		// bo kolumna inpost_locker mogła nie istnieć w chwili składania zamówienia (patrz historia
		// wersji z błędną nazwą kolumny field_frontend). Uzupełniamy kolumnę, żeby wartość pokazała
		// się także w natywnej sekcji "Dodatkowe informacje". Czytamy WYŁĄCZNIE ze źródła powiązanego
		// z tym zamówieniem (bez fallbacku do sesji), by nie przenieść przypadkiem cudzego wyboru.
		if (empty($locker) && !empty($order->order_shipping_params)) {
			$sp = $order->order_shipping_params;
			if (is_string($sp)) {
				$sp = @unserialize($sp);
			}
			if (is_object($sp) && !empty($sp->inpost_locker)) {
				$locker = $sp->inpost_locker;
				if ($isAdmin) {
					$this->ensureOrderFieldExists();
					$db = Factory::getContainer()->get(DatabaseInterface::class);
					$backfill = $db->getQuery(true)
						->update($db->quoteName('#__hikashop_order'))
						->set($db->quoteName('inpost_locker') . ' = ' . $db->quote($locker))
						->where($db->quoteName('order_id') . ' = ' . (int)$order->order_id);
					$db->setQuery($backfill);
					$db->execute();
				}
			}
		}

		if (empty($locker)) {
			return;
		}
		
		// Pobierz shipping_params dla konfiguracji ShipX
		$shippingParams = $this->getShippingParamsForOrder($order);

		// Pobierz shipment_id z bazy (jeśli już utworzono przesyłkę)
		$shipmentId = $this->getShipmentIdForOrder($order->order_id);

		$label = Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_FIELD_LABEL');
		
		// Wyświetl HTML bezpośrednio (echo) - to jest wywoływane w trakcie renderowania
		echo '<div id="inpost_locker_info" style="background:#fff3cd; border:2px solid #ffc107; padding:15px; margin:15px 0; border-radius:8px; font-size:14px;">';
		echo '<strong style="color:#856404; font-size:16px;">📦 ' . htmlspecialchars($label) . ':</strong><br>';
		echo '<span style="color:#333; font-size:15px; font-weight:bold;">' . htmlspecialchars($locker) . '</span>';
		echo '</div>';
		

		   // Sekcja ShipX API - TYLKO DLA ADMINA i NIE dla emaili
		   if (!$isAdmin) {
			   return; // Email/klient widzi tylko paczkomat, nie widzi sekcji ShipX
		   }



		   // Sprawdź czy ShipX jest włączony
		   $enableShipx = !empty($shippingParams->enable_shipx);
		   if (!$enableShipx) {
			   return; // ShipX wyłączony - nie pokazuj sekcji admin
		   }
		
		// Sprawdź status zamówienia HikaShop - tylko potwierdzone/opłacone mogą mieć tworzone przesyłki
		$requireConfirmed = isset($shippingParams->require_confirmed) ? (bool)$shippingParams->require_confirmed : true;
		if ($requireConfirmed) {
			$orderStatus = $order->order_status ?? '';
			$allowedStatuses = array('confirmed', 'shipped');
			if (!in_array($orderStatus, $allowedStatuses)) {
				echo '<div style="background:#fff3cd; border:2px solid #ffc107; padding:12px; margin:10px 0; border-radius:6px; color:#856404; font-size:14px;">';
				echo '<strong>⚠️ Zamówienie nie jest potwierdzone</strong><br>';
				echo 'Tworzenie przesyłki InPost jest możliwe tylko dla zamówień o statusie: <b>' . implode(', ', $allowedStatuses) . '</b>.<br>';
				echo 'Aktualny status: <b>' . htmlspecialchars($orderStatus) . '</b>';
				echo '</div>';
				return;
			}
		}
		
		// Pobierz tylko kod paczkomatu (pierwszy element przed " - ")
		$lockerName = $locker;
		if (strpos($locker, ' - ') !== false) {
			$lockerName = trim(explode(' - ', $locker)[0]);
		}
		// Dodatkowo wyczyść z niepotrzebnych znaków - zostaw tylko litery i cyfry
		$lockerCode = preg_replace('/[^A-Z0-9]/i', '', $lockerName);
		
		// Sekcja ShipX API (tylko admin)
		$envIsSandbox = ($shippingParams->api_mode ?? 'production') === 'sandbox';
		$envBadge = $envIsSandbox
			? '<span style="background:#4caf50; color:#fff; padding:2px 8px; border-radius:4px; font-size:12px;">SANDBOX</span>'
			: '<span style="background:#d32f2f; color:#fff; padding:2px 8px; border-radius:4px; font-size:12px;">PRODUKCJA — przesyłki płatne</span>';

		echo '<div id="inpost_shipx_admin" style="background:#e3f2fd; border:2px solid #2196f3; padding:15px; margin:15px 0; border-radius:8px; font-size:14px;">';
		echo '<strong style="color:#1565c0;">🚚 InPost ShipX (Admin):</strong> ' . $envBadge
			. ' <small>(kod: ' . htmlspecialchars($lockerCode) . ')</small><br>';

		if (!empty($shippingParams->debug)) {
			$debugLogFile = $this->resolveDebugLogPath();
			echo '<small style="color:#666;">Log debug: '
				. ($debugLogFile !== ''
					? htmlspecialchars($debugLogFile)
					: '<span style="color:#d32f2f;">brak zapisywalnego katalogu logów — sprawdź log_path w konfiguracji Joomli</span>')
				. '</small><br>';
		}

		if (!empty($shipmentId)) {
			// Przesyłka już utworzona - sprawdź jej status (informacyjnie)
			$shipmentInfo = $this->callShipXApi('GET', '/v1/shipments/' . $shipmentId, null, $shippingParams);
			$shipmentStatus = $shipmentInfo->status ?? 'unknown';
			// Numer nadania (tracking number) - po nim szukasz przesyłki w Managerze Paczek.
			// UWAGA: $shipmentId (np. 14066186) to WEWNĘTRZNE ID ShipX, NIE numer nadania.
			// InPost przydziela numer ASYNCHRONICZNIE (czasem pojawia się ~1 s po utworzeniu),
			// dlatego cache'ujemy go w bazie: gdy live-GET już go zwraca - utrwalamy; gdy jeszcze
			// nie - używamy wartości zapisanej wcześniej (krótki polling przy tworzeniu przesyłki).
			// Dzięki temu numer jest widoczny od razu po utworzeniu, bez ręcznego odświeżania strony.
			$storedTracking = $this->getTrackingNumberForOrder($order->order_id);
			$trackingNumber = $shipmentInfo->tracking_number ?? null;
			if (!empty($trackingNumber)) {
				if ($trackingNumber !== $storedTracking) {
					$this->storeTrackingNumber($order->order_id, $trackingNumber);
				}
			} else {
				$trackingNumber = $storedTracking;
			}

			// Czytelna nazwa statusu obok technicznej (np. "confirmed — Przygotowana do nadania").
			$statusHuman = $this->translateShipmentStatus($shipmentStatus);
			$statusSuffix = ($statusHuman !== $shipmentStatus) ? ' — ' . $statusHuman : '';
			echo '<span style="color:#28a745; font-weight:bold;">✅ ' . Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SHIPMENT_CREATED') . ': ' . htmlspecialchars($shipmentId) . '</span>';
			echo ' <span style="color:#666;">(status: ' . htmlspecialchars($shipmentStatus) . htmlspecialchars($statusSuffix) . ')</span><br>';
			if (!empty($trackingNumber)) {
				echo '<span style="color:#1565c0;">📮 Numer nadania: <strong>' . htmlspecialchars($trackingNumber)
					. '</strong></span> <small style="color:#666;">(po tym numerze szukaj w Managerze Paczek)</small><br>';
			} else {
				echo '<small style="color:#856404;">Numer nadania jeszcze nieprzydzielony - InPost przetwarza przesyłkę.</small><br>';
				// Numer przydzielany jest async (zwykle w kilka sekund). Zamiast kazać
				// adminowi ręcznie odświeżać stronę, odświeżamy ją automatycznie kilka razy,
				// aż numer się pojawi. Licznik w URL (inpost_tn_wait) ogranicza to do 3 prób,
				// żeby nie zapętlić przeładowań, gdy InPost wyjątkowo długo przetwarza.
				$reloadCount = (int) $app->input->getInt('inpost_tn_wait', 0);
				if ($reloadCount < 3) {
					$reloadUrl = Route::_('index.php?option=com_hikashop&ctrl=order&task=edit&cid='
						. (int)$order->order_id . '&inpost_tn_wait=' . ($reloadCount + 1), false);
					echo '<small style="color:#856404;">⏳ Czekam na numer nadania — strona odświeży się automatycznie…</small><br>';
					echo '<script>setTimeout(function(){ window.location.href = '
						. json_encode($reloadUrl) . '; }, 4000);</script>';
				} else {
					echo '<small style="color:#856404;">Jeśli numer wciąż się nie pojawił, odśwież stronę ręcznie za chwilę '
						. '(InPost nietypowo długo przetwarza przesyłkę).</small><br>';
				}
			}
			// Pełna odpowiedź API ShipX nie jest pokazywana na stronie zamówienia — to dane
			// diagnostyczne; ich miejsce jest w logach (JPATH_ROOT/logs/inpost_hika_debug.log,
			// gdy w konfiguracji włączony jest „debug”).
			$this->debug('Shipment info (order view)', $shipmentInfo, $shippingParams);

			// Przesyłka istnieje - zawsze pokazujemy "Pobierz etykietę" + "Utwórz ponownie".
			// Nie ma kroku "Opłać" (usunięty w v4.2.9): przesyłka z usługą inpost_locker_standard
			// jest przetwarzana i potwierdzana automatycznie przez ShipX; etykieta staje się
			// dostępna gdy InPost ją przygotuje (async). Jeśli status != confirmed, "Pobierz
			// etykietę" może chwilowo zwrócić błąd - wtedy odczekać kilka sekund.
			echo '<form method="post" style="display:inline-block; margin-top:10px; margin-right:10px;">';
			echo '<input type="hidden" name="inpost_action" value="get_label" />';
			echo '<input type="hidden" name="order_id" value="' . (int)$order->order_id . '" />';
			echo HTMLHelper::_('form.token');
			echo '<button type="submit" class="btn btn-small btn-success" style="background:#28a745; color:#fff; padding:8px 15px; border-radius:4px; border:none; cursor:pointer;">';
			echo '📄 ' . Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_DOWNLOAD_LABEL');
			echo '</button>';
			echo '</form>';

			// "Utwórz ponownie" - anuluje obecną przesyłkę (DELETE, best effort) i pozwala
			// utworzyć nową (np. inny rozmiar/paczkomat). Z potwierdzeniem, bo anuluje przesyłkę.
			// UWAGA: przesyłki confirmed InPost nie pozwala anulować (reguła biznesowa) - wtedy
			// cancel się nie powiedzie, ale lokalne ID i tak zostanie wyczyszczone.
			$confirmMsg = addslashes('Utworzenie nowej przesyłki najpierw spróbuje anulować obecną '
				. '(ID: ' . $shipmentId . ') w InPost. Kontynuować?');
			echo '<form method="post" style="display:inline-block; margin-top:10px; margin-right:10px;" '
				. 'onsubmit="return confirm(\'' . $confirmMsg . '\');">';
			echo '<input type="hidden" name="inpost_action" value="recreate_shipment" />';
			echo '<input type="hidden" name="order_id" value="' . (int)$order->order_id . '" />';
			echo '<input type="hidden" name="locker_name" value="' . htmlspecialchars($lockerCode) . '" />';
			echo HTMLHelper::_('form.token');
			echo '<button type="submit" class="btn btn-small btn-warning" style="background:#ffc107; color:#333; padding:8px 15px; border-radius:4px; border:none; cursor:pointer;">';
			echo '🔄 Utwórz ponownie';
			echo '</button>';
			echo '</form>';
			echo '<small style="color:#856404; display:block; margin-top:5px;">Etykieta dostępna, gdy InPost '
				. 'przetworzy przesyłkę (status „confirmed"). „Utwórz ponownie" anuluje tę i pozwala utworzyć '
				. 'nową (np. z innym rozmiarem paczki).</small>';
		} else {
			// Sprawdź czy skonfigurowano API
			$hasApiConfig = !empty($shippingParams->shipx_token) && !empty($shippingParams->shipx_organization_id);
			$isSandbox = ($shippingParams->api_mode ?? 'production') === 'sandbox';
			
			if ($hasApiConfig) {
				echo '<form method="post" style="display:inline-block;">';
				echo '<input type="hidden" name="inpost_action" value="create_shipment" />';
				echo '<input type="hidden" name="order_id" value="' . (int)$order->order_id . '" />';
				if ($isSandbox) {
					// W trybie sandbox pole jest edytowalne: domyślnie realny kod wybrany na mapie
					// (zwykle waliduje się już w sandboxie), a gdyby InPost go odrzucił - można wpisać
					// kod testowy jako furtkę awaryjną.
					echo '<input type="text" name="locker_name" value="' . htmlspecialchars($lockerCode) . '" style="width:100px; padding:5px; margin-right:5px;" placeholder="Kod paczkomatu" />';
					echo '<small style="color:#666; display:block; margin-bottom:5px;">Sandbox: jeśli realny kod zostanie odrzucony, użyj testowego (np. BBI02A, AND01A).</small>';
				} else {
					echo '<input type="hidden" name="locker_name" value="' . htmlspecialchars($lockerCode) . '" />';
				}

				// Wybór rozmiaru paczki dla TEGO zamówienia (domyślnie wg konfiguracji wtyczki)
				$defaultSize = $shippingParams->default_parcel_size ?? 'small';
				$sizeOptions = array(
					'small'  => Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SIZE_SMALL'),
					'medium' => Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SIZE_MEDIUM'),
					'large'  => Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SIZE_LARGE'),
				);
				echo '<label style="display:inline-block; margin-right:5px; color:#333;">'
					. Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SELECT_PARCEL_SIZE') . ': </label>';
				echo '<select name="parcel_size" style="padding:6px; margin-right:8px; border-radius:4px;">';
				foreach ($sizeOptions as $sizeValue => $sizeLabel) {
					$selected = ($sizeValue === $defaultSize) ? ' selected' : '';
					echo '<option value="' . $sizeValue . '"' . $selected . '>' . htmlspecialchars($sizeLabel) . '</option>';
				}
				echo '</select>';

				echo HTMLHelper::_('form.token');
				echo '<button type="submit" class="btn btn-small btn-primary" style="background:#007bff; color:#fff; padding:8px 15px; border-radius:4px; border:none; cursor:pointer;">';
				echo '📦 ' . Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_CREATE_SHIPMENT');
				echo '</button>';
				echo '</form>';
			} else {
				echo '<span style="color:#dc3545;">⚠️ ' . Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_API_NOT_CONFIGURED') . '</span>';
			}
		}
		echo '</div>';
		echo '</div>';
	}
	
	/**
	 * Obsługa akcji AJAX w panelu admina (tworzenie przesyłki, pobieranie etykiety)
	 */
	protected function handleAdminAjaxActions($order)
	{
		$app = Factory::getApplication();
		$input = $app->input;
		
		$action = $input->getString('inpost_action', '');
		$orderId = $input->getInt('order_id', 0);
		
		if (empty($action) || $orderId !== (int)$order->order_id) {
			return;
		}
		
		// Weryfikuj token dla POST
		if (in_array($action, ['create_shipment', 'get_label', 'recreate_shipment'])) {
			Session::checkToken() or die('Invalid Token');
		}
		
		$shippingParams = $this->getShippingParamsForOrder($order);
		
		switch ($action) {
			case 'create_shipment':
				$lockerName = $input->getString('locker_name', '');
				$parcelSize = $input->getCmd('parcel_size', '');
				$this->handleCreateShipment($order, $lockerName, $shippingParams, $parcelSize);
				break;
				
			case 'recreate_shipment':
				$this->handleRecreateShipment($order, $shippingParams);
				break;
				
			case 'get_label':
				$this->handleGetLabel($order, $shippingParams);
				break;
		}
	}
	
	/**
	 * Anuluje starą przesyłkę i wraca do stanu początkowego
	 */
	protected function handleRecreateShipment($order, $shippingParams)
	{
		$app = Factory::getApplication();
		$db = Factory::getContainer()->get(DatabaseInterface::class);

		// Pobierz stare shipment_id
		$oldShipmentId = $this->getShipmentIdForOrder($order->order_id);

		// Anuluj starą przesyłkę (best effort - InPost może odmówić, jeśli już nadana/odebrana)
		$cancelOk = false;
		if (!empty($oldShipmentId)) {
			$cancelOk = $this->cancelShipment($oldShipmentId, $shippingParams);
		}

		// Wyczyść stare ID (niezależnie od wyniku anulowania - lokalnie pozwalamy utworzyć nową)
		$query = $db->getQuery(true)
			->update($db->quoteName('#__hikashop_order'))
			->set($db->quoteName('inpost_shipment_id') . ' = NULL')
			->where($db->quoteName('order_id') . ' = ' . (int)$order->order_id);
		$db->setQuery($query);
		$db->execute();

		if (empty($oldShipmentId)) {
			$app->enqueueMessage('Możesz utworzyć nową przesyłkę.', 'message');
		} elseif ($cancelOk) {
			$app->enqueueMessage('Stara przesyłka (ID: ' . $oldShipmentId . ') została anulowana w InPost. '
				. 'Możesz utworzyć nową.', 'message');
		} else {
			$app->enqueueMessage('Odpięto starą przesyłkę (ID: ' . $oldShipmentId . ') od zamówienia, ale InPost '
				. 'NIE potwierdził jej anulowania (mogła być już nadana/odebrana albo opłacona). Sprawdź ją w '
				. 'Managerze Paczek i w razie potrzeby anuluj ręcznie, żeby nie zapłacić dwa razy. Możesz utworzyć nową.',
				'warning');
		}
		
		// Przekieruj z powrotem na stronę zamówienia
		$redirectUrl = 'index.php?option=com_hikashop&ctrl=order&task=edit&cid=' . (int)$order->order_id;
		$app->redirect(Route::_($redirectUrl, false));
	}
	
	/**
	 * Tworzy przesyłkę w ShipX API
	 */
	protected function handleCreateShipment($order, $lockerName, $shippingParams, $parcelSize = '')
	{
		$app = Factory::getApplication();

		// Rozmiar paczki: preferuj wybrany na tym zamówieniu (formularz), z fallbackiem
		// do domyślnego z konfiguracji wtyczki. Waliduj wobec dozwolonych szablonów InPost.
		$allowedSizes = array('small', 'medium', 'large');
		if (!in_array($parcelSize, $allowedSizes, true)) {
			$parcelSize = $shippingParams->default_parcel_size ?? 'small';
		}
		if (!in_array($parcelSize, $allowedSizes, true)) {
			$parcelSize = 'small';
		}

		// Sposób nadania (ShipX custom_attributes.sending_method) - z konfiguracji, z walidacją.
		// parcel_locker = nadawca zostawia paczkę w paczkomacie; dispatch_order = kurier po odbiór;
		// pop = nadanie w PaczkoPunkcie. Domyślnie parcel_locker (jak oficjalna wtyczka InPost).
		$allowedSending = array('parcel_locker', 'dispatch_order', 'pop');
		$sendingMethod = $shippingParams->sending_method ?? 'parcel_locker';
		if (!in_array($sendingMethod, $allowedSending, true)) {
			$sendingMethod = 'parcel_locker';
		}

		// Zabezpieczenie przed utworzeniem kilku przesyłek dla jednego zamówienia
		// (np. podwójne kliknięcie przycisku albo ponowna próba po błędzie kodu paczkomatu
		// - przycisk "Utwórz przesyłkę" jest widoczny tylko dopóki nie ma zapisanego
		// inpost_shipment_id, ale dwa równoległe żądania mogły już zdążyć wystartować)
		$existingShipmentId = $this->getShipmentIdForOrder($order->order_id);
		if (!empty($existingShipmentId)) {
			$app->enqueueMessage(
				'Przesyłka dla tego zamówienia już istnieje (ID: ' . $existingShipmentId . '). '
				. 'Odśwież stronę zamówienia - jeśli chcesz utworzyć nową, najpierw użyj "Utwórz ponownie".',
				'warning'
			);
			$redirectUrl = 'index.php?option=com_hikashop&ctrl=order&task=edit&cid=' . (int)$order->order_id;
			$app->redirect(Route::_($redirectUrl, false));
			return;
		}

		// Pobierz dane odbiorcy z zamówienia
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->select('a.*, u.user_email')
			->from($db->quoteName('#__hikashop_address', 'a'))
			->leftJoin($db->quoteName('#__hikashop_user', 'u') . ' ON u.user_id = a.address_user_id')
			->where($db->quoteName('a.address_id') . ' = ' . (int)$order->order_shipping_address_id);
		$db->setQuery($query);
		$address = $db->loadObject();
		
		if (!$address) {
			$app->enqueueMessage('Błąd: Nie znaleziono adresu dostawy', 'error');
			return;
		}
		
		// Kod paczkomatu ShipX przyjmuje wielkimi literami (w sandboxie pole jest edytowalne ręcznie)
		$targetPoint = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$lockerName));
		if ($targetPoint === '') {
			$app->enqueueMessage('Błąd: brak kodu paczkomatu dla tego zamówienia.', 'error');
			return;
		}

		$receiver = $this->buildReceiver($address);
		if (empty($receiver['phone'])) {
			$app->enqueueMessage(
				'Błąd: odbiorca nie ma poprawnego numeru telefonu (ShipX wymaga 9 cyfr). '
				. 'Uzupełnij telefon w adresie dostawy zamówienia.',
				'error'
			);
			return;
		}

		// Zgodnie z dokumentacją ShipX (tryb uproszczony) wymagane są tylko:
		// receiver, parcels (tablica) i service. Sender jest opcjonalny - gdy go nie wyślemy,
		// InPost użyje danych organizacji.
		$shipmentData = array(
			'receiver' => $receiver,
			'parcels' => array(
				array(
					'template' => $parcelSize
				)
			),
			'service' => 'inpost_locker_standard',
			'reference' => 'Zamówienie #' . $order->order_id,
			'custom_attributes' => array(
				'target_point' => $targetPoint,
				'sending_method' => $sendingMethod
			)
		);

		$sender = $this->buildSender($shippingParams);
		if ($sender !== null) {
			$shipmentData['sender'] = $sender;
		} else {
			$this->debug('Sender pominięty (niekompletne dane) - ShipX użyje danych organizacji', null, $shippingParams);
		}

		$this->debug('Creating shipment', $shipmentData, $shippingParams);
		
		// Wywołaj API
		$result = $this->callShipXApi(
			'POST',
			'/v1/organizations/' . $shippingParams->shipx_organization_id . '/shipments',
			$shipmentData,
			$shippingParams
		);
		
		if ($result && isset($result->id)) {
			// Sukces - zapisz shipment_id w bazie
			$this->ensureShipmentIdFieldExists();
			
			$query = $db->getQuery(true)
				->update($db->quoteName('#__hikashop_order'))
				->set($db->quoteName('inpost_shipment_id') . ' = ' . $db->quote($result->id))
				->where($db->quoteName('order_id') . ' = ' . (int)$order->order_id);
			$db->setQuery($query);
			$db->execute();

			// Numer nadania (tracking_number) InPost przydziela ASYNCHRONICZNIE - czasem jest już
			// w odpowiedzi POST, czasem pojawia się ~1 s później. Spróbuj pobrać go i zapisać od razu
			// (krótki polling), żeby był widoczny w panelu bez ręcznego odświeżania strony przez admina.
			$trackingNumber = $result->tracking_number ?? null;
			if (empty($trackingNumber)) {
				$trackingNumber = $this->fetchTrackingNumberWithRetry($result->id, $shippingParams);
			}
			if (!empty($trackingNumber)) {
				$this->storeTrackingNumber($order->order_id, $trackingNumber);
			}

			$this->debug('Shipment created successfully', ['shipment_id' => $result->id, 'tracking_number' => $trackingNumber], $shippingParams);

			// Przesyłka utworzona z usługą `inpost_locker_standard` - to komplet.
			// NIE robimy kroku ofert/`/buy` (jak oficjalna wtyczka InPost): przesyłka
			// z ustawioną usługą jest przetwarzana i potwierdzana automatycznie przez ShipX,
			// a etykieta staje się dostępna gdy InPost ją przygotuje (async, czasem kilka-
			// -kilkanaście sekund). Konto InPost obciąża się za przesyłkę niezależnie -
			// nie ma osobnego kroku "opłać".
			$sizeLabel = Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SIZE_' . strtoupper($parcelSize));
			$app->enqueueMessage(
				'Przesyłka InPost utworzona! ID: ' . $result->id . ' (rozmiar: ' . $sizeLabel . '). '
				. 'Etykieta będzie dostępna do pobrania, gdy InPost przetworzy przesyłkę - jeśli '
				. '„Pobierz etykietę" zwróci błąd, odczekaj kilka sekund i spróbuj ponownie.',
				'success'
			);

			// Przekieruj z powrotem na stronę zamówienia
			$redirectUrl = 'index.php?option=com_hikashop&ctrl=order&task=edit&cid=' . (int)$order->order_id;
			$app->redirect(Route::_($redirectUrl, false));
		} else {
			$errorMsg = isset($result->error) ? $result->error : 'Nieznany błąd';
			$errorDesc = isset($result->description) ? $result->description : '';
			$errorDetails = isset($result->details) ? json_encode($result->details) : '';
			
			$this->debug('Shipment creation failed', [
				'error' => $errorMsg,
				'description' => $errorDesc,
				'details' => $errorDetails
			], $shippingParams);
			
			// Przetłumacz typowe błędy na bardziej zrozumiałe komunikaty
			$userMessage = $this->translateShipXError($errorMsg, $errorDesc, $errorDetails);
			$app->enqueueMessage($userMessage, 'error');
		}
	}
	
	/**
	 * Normalizuje telefon do 9 cyfr, jakich wymaga ShipX (bez +48, spacji i myślników).
	 * Zwraca pusty string, gdy numeru nie da się sprowadzić do 9 cyfr.
	 */
	protected function normalizePhone($phone)
	{
		$digits = preg_replace('/\D+/', '', (string)$phone);

		if (strlen($digits) === 11 && strpos($digits, '48') === 0) {
			$digits = substr($digits, 2);   // +48 xxx xxx xxx
		} elseif (strlen($digits) === 13 && strpos($digits, '0048') === 0) {
			$digits = substr($digits, 4);   // 0048 xxx xxx xxx
		} elseif (strlen($digits) === 10 && strpos($digits, '0') === 0) {
			$digits = substr($digits, 1);   // 0 xxx xxx xxx
		}

		return strlen($digits) === 9 ? $digits : '';
	}

	/**
	 * Normalizuje kod pocztowy do formatu NN-NNN wymaganego przez ShipX.
	 */
	protected function normalizePostCode($postCode)
	{
		$digits = preg_replace('/\D+/', '', (string)$postCode);

		return strlen($digits) === 5 ? substr($digits, 0, 2) . '-' . substr($digits, 2) : '';
	}

	/**
	 * Buduje obiekt receiver wg specyfikacji ShipX.
	 *
	 * ShipX zna wyłącznie pola company_name, first_name, last_name, email i phone
	 * (nie ma pola `name`), a pustych stringów nie przepuszcza walidator - dlatego
	 * puste wartości pomijamy zamiast wysyłać.
	 */
	protected function buildReceiver($address)
	{
		$fields = array(
			'company_name' => trim((string)($address->address_company ?? '')),
			'first_name'   => trim((string)($address->address_firstname ?? '')),
			'last_name'    => trim((string)($address->address_lastname ?? '')),
			'email'        => trim((string)($address->user_email ?? '')),
			'phone'        => $this->normalizePhone($address->address_telephone ?? ''),
		);

		return array_filter($fields, function ($value) {
			return $value !== '';
		});
	}

	/**
	 * Buduje obiekt sender wg specyfikacji ShipX albo zwraca null.
	 *
	 * Sender jest opcjonalny: bez kompletu danych lepiej pominąć go w żądaniu,
	 * bo InPost podstawi wtedy dane organizacji. Wysyłanie połowicznego obiektu
	 * (albo pustych stringów) kończy się błędem walidacji.
	 */
	protected function buildSender($shippingParams)
	{
		$street   = trim((string)($shippingParams->sender_street ?? ''));
		$building = trim((string)($shippingParams->sender_building ?? ''));
		$city     = trim((string)($shippingParams->sender_city ?? ''));
		$email    = trim((string)($shippingParams->sender_email ?? ''));
		$postCode = $this->normalizePostCode($shippingParams->sender_postcode ?? '');
		$phone    = $this->normalizePhone($shippingParams->sender_phone ?? '');

		if ($street === '' || $building === '' || $city === '' || $email === '' || $postCode === '' || $phone === '') {
			return null;
		}

		// ShipX oczekuje first_name/last_name, a konfiguracja trzyma jedno pole "nazwa nadawcy"
		$nameParts = preg_split('/\s+/', trim((string)($shippingParams->sender_name ?? '')), 2);

		$sender = array_filter(array(
			'company_name' => trim((string)($shippingParams->sender_company ?? '')),
			'first_name'   => $nameParts[0] ?? '',
			'last_name'    => $nameParts[1] ?? '',
			'email'        => $email,
			'phone'        => $phone,
		), function ($value) {
			return $value !== '';
		});

		$sender['address'] = array(
			'street'          => $street,
			'building_number' => $building,
			'city'            => $city,
			'post_code'       => $postCode,
			'country_code'    => 'PL',
		);

		return $sender;
	}

	/**
	 * Tłumaczy błędy ShipX API na zrozumiałe komunikaty
	 */
	protected function translateShipXError($error, $description, $details)
	{
		// Sprawdź typowe błędy
		if (strpos($details, 'target_point') !== false && strpos($details, 'does_not_exist') !== false) {
			return 'Błąd: Podany kod paczkomatu nie istnieje. Sprawdź czy wpisałeś poprawny kod (np. KRA010, WAW01M). W trybie sandbox używaj kodów testowych (np. BBI02A, AND01A).';
		}
		
		if (strpos($details, 'phone') !== false && strpos($details, 'invalid') !== false) {
			return 'Błąd: Nieprawidłowy numer telefonu odbiorcy lub nadawcy. Numer musi mieć 9 cyfr.';
		}
		
		if (strpos($details, 'email') !== false && strpos($details, 'invalid') !== false) {
			return 'Błąd: Nieprawidłowy adres email odbiorcy lub nadawcy.';
		}
		
		if (strpos($details, 'post_code') !== false) {
			return 'Błąd: Nieprawidłowy kod pocztowy. Użyj formatu XX-XXX (np. 00-001).';
		}
		
		if ($error === 'validation_failed') {
			return 'Błąd walidacji danych: ' . $details;
		}
		
		if ($error === 'forbidden') {
			return 'Błąd autoryzacji: Sprawdź token API i Organization ID w konfiguracji pluginu.';
		}
		
		if ($error === 'unauthorized') {
			return 'Błąd autoryzacji: Token API jest nieprawidłowy lub wygasł.';
		}
		
		if ($error === 'token_invalid') {
			return 'Błąd: Token API jest nieprawidłowy. Sprawdź czy wkleiłeś poprawny token z Managera Paczek InPost (Moje konto → API). Upewnij się że używasz tokenu z właściwego środowiska (Produkcja/Sandbox).';
		}
		
		// Domyślny komunikat
		return 'Błąd tworzenia przesyłki: ' . $error . ($description ? ' - ' . $description : '') . ($details ? ' ' . $details : '');
	}

	/**
	 * Anuluje istniejącą przesyłkę w ShipX (best effort).
	 *
	 * WAŻNE: właściwy endpoint to DELETE /v1/shipments/{id} (potwierdzone z oficjalną
	 * wtyczką InPost dla WooCommerce). Wcześniej wtyczka wołała POST /v1/shipments/{id}/cancel
	 * (endpoint nieistniejący) — anulowanie NIGDY się nie udawało, stąd osierocone przesyłki.
	 * InPost i tak odmawia anulowania przesyłki o statusie `confirmed` (błąd
	 * "Action (cancel) can not be taken on shipment with status (confirmed)") — to reguła
	 * biznesowa, nie da się jej obejść.
	 */
	protected function cancelShipment($shipmentId, $shippingParams)
	{
		$result = $this->callShipXApi(
			'DELETE',
			'/v1/shipments/' . $shipmentId,
			null,
			$shippingParams
		);
		$this->debug('Cancel shipment result', $result, $shippingParams);

		// DELETE zwraca zaktualizowany obiekt przesyłki ze statusem `cancelled` przy sukcesie.
		if (is_object($result) && isset($result->status)) {
			return $result->status === 'cancelled';
		}

		// Niektóre wersje API zwracają 200/204 bez ciała — potraktuj brak błędu jako sukces.
		if (is_object($result) && isset($result->_httpCode)) {
			return $result->_httpCode >= 200 && $result->_httpCode < 300;
		}

		return false;
	}
	
	/**
	 * Pobiera etykietę przesyłki
	 */
	protected function handleGetLabel($order, $shippingParams)
	{
		$shipmentId = $this->getShipmentIdForOrder($order->order_id);

		if (empty($shipmentId)) {
			Factory::getApplication()->enqueueMessage('Brak ID przesyłki', 'error');
			return;
		}
		
		$this->debug('Getting label for shipment', ['shipment_id' => $shipmentId], $shippingParams);
		
		// Pobierz etykietę jako PDF. format=Pdf; type z konfiguracji:
		// normal = A4 (drukarka laserowa/atramentowa), A6 = mała etykieta (drukarka termiczna).
		$labelType = (($shippingParams->label_type ?? 'normal') === 'A6') ? 'A6' : 'normal';
		$labelData = $this->callShipXApi(
			'GET',
			'/v1/shipments/' . $shipmentId . '/label?format=Pdf&type=' . $labelType,
			null,
			$shippingParams,
			true // raw response (PDF)
		);
		
		if ($labelData && substr($labelData, 0, 4) === '%PDF') {
			// Wyczyść wszystkie bufory wyjściowe
			while (ob_get_level()) {
				ob_end_clean();
			}
			// Zwróć PDF do przeglądarki
			header('Content-Type: application/pdf');
			header('Content-Disposition: attachment; filename="inpost_label_' . $shipmentId . '.pdf"');
			header('Content-Length: ' . strlen($labelData));
			header('Cache-Control: private, max-age=0, must-revalidate');
			header('Pragma: public');
			echo $labelData;
			exit;
		} else {
			// Spróbuj zdekodować jako JSON żeby zobaczyć błąd
			$errorData = @json_decode($labelData);
			$errorMsg = 'Błąd pobierania etykiety.';
			if ($errorData && isset($errorData->message)) {
				$errorMsg .= ' ' . $errorData->message;
			} elseif ($errorData && isset($errorData->error)) {
				$errorMsg .= ' ' . $errorData->error;
			}
			$this->debug('Label download failed', ['response' => substr($labelData, 0, 500)], $shippingParams);
			Factory::getApplication()->enqueueMessage($errorMsg . ' InPost może jeszcze przetwarzać przesyłkę - odczekaj kilka sekund i spróbuj ponownie.', 'error');
		}
	}
	
	/**
	 * Tłumaczy techniczny status ShipX na czytelną polską nazwę.
	 * Nazwy statusów wg oficjalnego modułu InPost (ShipX). Dla nieznanego statusu
	 * zwraca surową wartość, żeby nic nie ukrywać.
	 */
	protected function translateShipmentStatus($status)
	{
		$status = (string) $status;
		$map = array(
			'created'                  => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_ST_CREATED',
			'offers_prepared'          => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_ST_OFFERS_PREPARED',
			'offer_selected'           => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_ST_OFFER_SELECTED',
			'confirmed'                => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_ST_CONFIRMED',
			'dispatched_by_sender'     => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_ST_DISPATCHED_BY_SENDER',
			'collected_from_sender'    => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_ST_COLLECTED_FROM_SENDER',
			'taken_by_courier'         => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_ST_TAKEN_BY_COURIER',
			'adopted_at_source_branch' => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_ST_ADOPTED_SOURCE',
			'sent_from_source_branch'  => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_ST_SENT_FROM_SOURCE',
			'out_for_delivery'         => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_ST_OUT_FOR_DELIVERY',
			'ready_to_pickup'          => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_ST_READY_TO_PICKUP',
			'delivered'                => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_ST_DELIVERED',
			'returned_to_sender'       => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_ST_RETURNED',
			'avizo'                    => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_ST_AVIZO',
			'canceled'                 => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_ST_CANCELED',
			'cancelled'                => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_ST_CANCELED',
			'not_found'                => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_ST_NOT_FOUND',
			'unknown'                  => 'PLG_HIKASHOPSHIPPING_INPOST_HIKA_ST_UNKNOWN',
		);
		if (isset($map[$status])) {
			$translated = Text::_($map[$status]);
			// Text::_ zwraca sam klucz, gdy brak tłumaczenia — wtedy pokaż surowy status.
			if (strcasecmp($translated, $map[$status]) !== 0) {
				return $translated;
			}
		}
		return $status;
	}

	/**
	 * Wywołuje ShipX API
	 */
	protected function callShipXApi($method, $endpoint, $data = null, $shippingParams = null, $rawResponse = false)
	{
		$apiMode = $shippingParams->api_mode ?? 'production';
		$baseUrl = ($apiMode === 'sandbox') ? self::SHIPX_API_URL_SANDBOX : self::SHIPX_API_URL;
		$token = $shippingParams->shipx_token ?? '';
		
		$url = $baseUrl . $endpoint;
		
		$this->debug('ShipX API Call', [
			'method' => $method,
			'url' => $url,
			'data' => $data
		], $shippingParams);
		
		$ch = curl_init();
		
		// Dla rawResponse (PDF) użyj Accept: application/pdf
		$acceptHeader = $rawResponse ? 'Accept: application/pdf' : 'Accept: application/json';
		
		$headers = array(
			'Authorization: Bearer ' . $token,
			'Content-Type: application/json',
			$acceptHeader
		);
		
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		
		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			if ($data) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
			}
		} elseif ($method === 'DELETE' || $method === 'PUT') {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
			if ($data) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
			}
		}

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		
		curl_close($ch);
		
		$this->debug('ShipX API Response', [
			'http_code' => $httpCode,
			'error' => $error,
			'response_length' => strlen($response),
			'response_body' => substr($response, 0, 2000)
		], $shippingParams);
		
		if ($error) {
			$this->debug('CURL Error', $error, $shippingParams);
			return null;
		}
		
		if ($rawResponse) {
			return ($httpCode >= 200 && $httpCode < 300) ? $response : null;
		}

		$decoded = json_decode($response);
		// Zachowaj kod HTTP w obiekcie wyniku aby łatwiej diagnozować błędy
		if (is_object($decoded)) {
			$decoded->_httpCode = $httpCode;
			return $decoded;
		}
		
		// Jeśli nie ma JSON-a, zwróć surowe dane z kodem HTTP
		$wrapper = new \stdClass();
		$wrapper->_httpCode = $httpCode;
		$wrapper->_raw = $response;
		return $wrapper;
	}
	
	/**
	 * Pobiera parametry shipping dla zamówienia
	 */
	protected function getShippingParamsForOrder($order)
	{
		$db = Factory::getContainer()->get(DatabaseInterface::class);

		// Wtyczka ma $multiple = true, więc opublikowanych metod inpost_hika może być kilka
		// (np. osobna sandboxowa i produkcyjna). Bierzemy tę, którą faktycznie wybrano
		// w zamówieniu - inaczej trafiamy w losową i wysyłamy przesyłkę na złe środowisko.
		$shippingId = isset($order->order_shipping_id) ? (int)$order->order_shipping_id : 0;
		if ($shippingId > 0) {
			$query = $db->getQuery(true)
				->select($db->quoteName('shipping_params'))
				->from($db->quoteName('#__hikashop_shipping'))
				->where($db->quoteName('shipping_id') . ' = ' . $shippingId)
				->where($db->quoteName('shipping_type') . ' = ' . $db->quote($this->name));
			$db->setQuery($query);
			$result = $db->loadResult();

			if ($result) {
				return unserialize($result);
			}
		}

		// Fallback (np. zamówienie bez zapisanego shipping_id): najstarsza opublikowana
		// metoda - deterministycznie, żeby wynik nie zależał od kolejności zwracanej przez bazę.
		$query = $db->getQuery(true)
			->select($db->quoteName('shipping_params'))
			->from($db->quoteName('#__hikashop_shipping'))
			->where($db->quoteName('shipping_type') . ' = ' . $db->quote($this->name))
			->where($db->quoteName('shipping_published') . ' = 1')
			->order($db->quoteName('shipping_id') . ' ASC')
			->setLimit(1);
		$db->setQuery($query);
		$result = $db->loadResult();

		if ($result) {
			return unserialize($result);
		}

		return new \stdClass();
	}
	
	/**
	 * Pobiera ID przesyłki ShipX zapisane dla zamówienia (lub null, jeśli brak)
	 */
	protected function getShipmentIdForOrder($orderId)
	{
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->select($db->quoteName('inpost_shipment_id'))
			->from($db->quoteName('#__hikashop_order'))
			->where($db->quoteName('order_id') . ' = ' . (int)$orderId);
		$db->setQuery($query);
		return $db->loadResult();
	}

	/**
	 * Pobiera zapisany numer nadania (tracking_number) dla zamówienia (lub null, jeśli brak).
	 * Numer jest cache'owany w bazie, bo InPost przydziela go asynchronicznie po utworzeniu przesyłki.
	 */
	protected function getTrackingNumberForOrder($orderId)
	{
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$columns = $db->getTableColumns('#__hikashop_order');
		if (!isset($columns['inpost_tracking_number'])) {
			return null;
		}
		$query = $db->getQuery(true)
			->select($db->quoteName('inpost_tracking_number'))
			->from($db->quoteName('#__hikashop_order'))
			->where($db->quoteName('order_id') . ' = ' . (int)$orderId);
		$db->setQuery($query);
		return $db->loadResult();
	}

	/**
	 * Zapisuje numer nadania (tracking_number) dla zamówienia.
	 */
	protected function storeTrackingNumber($orderId, $trackingNumber)
	{
		$this->ensureShipmentIdFieldExists();
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->update($db->quoteName('#__hikashop_order'))
			->set($db->quoteName('inpost_tracking_number') . ' = ' . $db->quote($trackingNumber))
			->where($db->quoteName('order_id') . ' = ' . (int)$orderId);
		$db->setQuery($query);
		$db->execute();
	}

	/**
	 * Odpytuje ShipX o numer nadania z krótkim ponawianiem. InPost przydziela tracking_number
	 * asynchronicznie (zwykle w ciągu ~1 s od utworzenia), więc kilka prób z krótką przerwą
	 * pozwala pobrać go od razu po utworzeniu przesyłki - bez ręcznego odświeżania strony.
	 * Zwraca numer nadania lub null, jeśli w danym czasie nie został jeszcze przydzielony.
	 */
	protected function fetchTrackingNumberWithRetry($shipmentId, $shippingParams, $maxAttempts = 4, $delayMs = 1200)
	{
		for ($i = 0; $i < $maxAttempts; $i++) {
			$info = $this->callShipXApi('GET', '/v1/shipments/' . $shipmentId, null, $shippingParams);
			if (is_object($info) && !empty($info->tracking_number)) {
				return $info->tracking_number;
			}
			// Nie śpij po ostatniej próbie.
			if ($i < $maxAttempts - 1) {
				usleep($delayMs * 1000);
			}
		}
		return null;
	}

	/**
	 * Upewnia się że kolumny inpost_shipment_id i inpost_tracking_number istnieją
	 */
	protected function ensureShipmentIdFieldExists()
	{
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$columns = $db->getTableColumns('#__hikashop_order');

		if (!isset($columns['inpost_shipment_id'])) {
			$db->setQuery('ALTER TABLE ' . $db->quoteName('#__hikashop_order') . ' ADD ' . $db->quoteName('inpost_shipment_id') . ' VARCHAR(50) NULL');
			$db->execute();
		}

		if (!isset($columns['inpost_tracking_number'])) {
			$db->setQuery('ALTER TABLE ' . $db->quoteName('#__hikashop_order') . ' ADD ' . $db->quoteName('inpost_tracking_number') . ' VARCHAR(64) NULL');
			$db->execute();
		}
	}

	public function onShippingDisplay(&$order, &$dbrates, &$usable_rates, &$messages)
	{
		$this->ensureOrderFieldExists();

		$app = Factory::getApplication();

		$selectedLocker = $this->findSelectedLocker($order);

		// Synchronizuj - jeśli mamy wartość, zapisz też do sesji
		if ($selectedLocker !== '') {
			$app->setUserState('hikashop.inpost_locker', $selectedLocker);
		}
		
		$shippingDisplay = parent::onShippingDisplay($order, $dbrates, $usable_rates, $messages);
		if (empty($usable_rates))
			return $shippingDisplay;

		foreach ($usable_rates as $key => $rate) {
			if ($rate->shipping_type !== $this->name)
				continue;
			$this->decorateRateWithWidget($rate, $selectedLocker);
			$usable_rates[$key] = $rate;
		}

		return $shippingDisplay;
	}

	public function getShippingDefaultValues(&$element)
	{
		// Domyślna, pełna nazwa usługi i krótki opis czasu doręczenia
		$element->shipping_name = 'InPost Paczkomaty 24/7';
		$element->shipping_description = 'Dostawa 1-2 dni robocze; weekend możliwy zależnie od usługi.';
		$element->shipping_type = $this->name;
		$element->shipping_params = new \stdClass();
		$element->shipping_params->enable_shipx = 0;
		$element->shipping_params->api_mode = 'production';
		// ShipX API
		$element->shipping_params->shipx_token = '';
		$element->shipping_params->shipx_organization_id = '';
		// Dane nadawcy
		$element->shipping_params->sender_name = '';
		$element->shipping_params->sender_company = '';
		$element->shipping_params->sender_email = '';
		$element->shipping_params->sender_phone = '';
		$element->shipping_params->sender_street = '';
		$element->shipping_params->sender_building = '';
		$element->shipping_params->sender_city = '';
		$element->shipping_params->sender_postcode = '';
		$element->shipping_params->default_parcel_size = 'small';
		$element->shipping_params->sending_method = 'parcel_locker';
		// Mapa GeoWidget
		$element->shipping_params->default_lat = '52.2297';
		$element->shipping_params->default_lng = '21.0122';
		$element->shipping_params->default_zoom = '';
		$element->shipping_params->show_parcel_lockers = 1;
		$element->shipping_params->show_pops = 0;
		$element->shipping_params->debug = 0;
	}

	/**
	 * Zwraca ścieżkę pliku debug albo '' gdy nie ma gdzie pisać.
	 *
	 * Joomla 4/5 loguje do administrator/logs, a nie do JPATH_ROOT/logs - stąd wcześniejsze
	 * ostrzeżenia file_put_contents. Bierzemy log_path z konfiguracji, potem standardowe
	 * lokalizacje; jeśli żadna nie jest zapisywalna, milczymy zamiast zasypywać panel błędami.
	 */
	protected function resolveDebugLogPath()
	{
		$candidates = array(
			(string) Factory::getApplication()->get('log_path', ''),
			JPATH_ADMINISTRATOR . '/logs',
			JPATH_ROOT . '/logs',
		);

		foreach ($candidates as $dir) {
			$dir = rtrim(trim($dir), '/\\');

			if ($dir !== '' && is_dir($dir) && is_writable($dir)) {
				return $dir . '/inpost_hika_debug.log';
			}
		}

		return '';
	}

	/**
	 * Logowanie debug do pliku
	 */
	protected function debug($message, $data = null, $shippingParams = null)
	{
		$debugEnabled = false;
		if ($shippingParams && isset($shippingParams->debug)) {
			$debugEnabled = (bool)$shippingParams->debug;
		}
		if (!$debugEnabled) return;

		$logFile = $this->resolveDebugLogPath();
		if ($logFile === '') return;

		$timestamp = date('Y-m-d H:i:s');
		$logMessage = "[{$timestamp}] {$message}";
		if ($data !== null) {
			$logMessage .= " | Data: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
		}
		$logMessage .= "\n";
		
		file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
	}

	public function shippingMethods(&$main)
	{
		$methods = array();
		$methods[$main->shipping_id] = Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_NAME');
		return $methods;
	}

	public function onShippingConfigurationSave(&$element)
	{
		$this->ensureOrderFieldExists();
		parent::onShippingConfigurationSave($element);
	}

	/**
	 * Dodaje JavaScript do ukrywania pól ShipX gdy wyłączone
	 */
	public function onShippingConfiguration(&$element)
	{
		parent::onShippingConfiguration($element);
		
		// JavaScript do ukrywania/pokazywania pól ShipX
		// HikaShop booleanlist używa radiobuttons z wartościami "1" i "0"
		$js = "
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			// Szukaj wszystkich radio buttonów dla enable_shipx
			var radios = document.querySelectorAll('input[type=\"radio\"][name*=\"enable_shipx\"]');
			if (!radios.length) {
				console.log('InPost: enable_shipx radios not found');
				return;
			}
			
			// Lista pól do ukrycia gdy ShipX wyłączony
			var shipxFields = ['api_mode', 'shipx_token', 'shipx_organization_id', 
				'sender_name', 'sender_company', 'sender_email', 'sender_phone',
				'sender_street', 'sender_building', 'sender_city', 'sender_postcode',
				'default_parcel_size'];
			
			function toggleShipxFields() {
				// Znajdź zaznaczony radio
				var enabled = false;
				radios.forEach(function(radio) {
					if (radio.checked && radio.value == '1') {
						enabled = true;
					}
				});
				
				console.log('InPost: ShipX enabled = ' + enabled);
				
				shipxFields.forEach(function(fieldName) {
					// Szukaj pola po nazwie (może być input, select, textarea)
					var field = document.querySelector('[name*=\"' + fieldName + '\"]');
					if (field) {
						var row = field.closest('tr');
						if (row) {
							row.style.display = enabled ? '' : 'none';
						}
					}
				});
			}
			
			// Toggle na start i przy zmianie każdego radio
			toggleShipxFields();
			radios.forEach(function(radio) {
				radio.addEventListener('change', toggleShipxFields);
			});
		});
		</script>
		";
		
		echo $js;
	}

	public function onAfterOrderConfirm(&$order, &$methods, $method_id)
	{
		parent::onAfterOrderConfirm($order, $methods, $method_id);
		
		// Pobierz shipping_params dla debug
		$shippingParamsForDebug = null;
		if (!empty($methods) && isset($methods[$method_id])) {
			$shippingParamsForDebug = $methods[$method_id]->shipping_params ?? null;
		}
		
		$app = Factory::getApplication();
		$db = Factory::getContainer()->get(DatabaseInterface::class);

		$selected = $this->findSelectedLocker($order);

		$this->debug('onAfterOrderConfirm', [
			'order_id' => $order->order_id,
			'method_id' => $method_id,
			'selected_locker' => $selected
		], $shippingParamsForDebug);
		
		if ($selected !== '') {
			$db = Factory::getContainer()->get(DatabaseInterface::class);
			
			// Zapisz paczkomat w kolumnie inpost_locker
			$query = $db->getQuery(true)
				->update($db->quoteName('#__hikashop_order'))
				->set($db->quoteName($this->orderFieldName) . ' = ' . $db->quote($selected))
				->where($db->quoteName('order_id') . ' = ' . (int)$order->order_id);
			$db->setQuery($query);
			$db->execute();
			
			$this->debug('Saved locker to DB', ['locker' => $selected, 'order_id' => $order->order_id], $shippingParamsForDebug);
			
			// Dodaj informację o paczkomacie do shipping_params (widoczne w panelu admina)
			$shippingParams = new \stdClass();
			if (!empty($order->order_shipping_params)) {
				if (is_string($order->order_shipping_params)) {
					$decoded = @unserialize($order->order_shipping_params);
					if (is_object($decoded)) {
						$shippingParams = $decoded;
					}
				} elseif (is_object($order->order_shipping_params)) {
					$shippingParams = $order->order_shipping_params;
				}
			}
			$shippingParams->inpost_locker = $selected;
			
			$query2 = $db->getQuery(true)
				->update($db->quoteName('#__hikashop_order'))
				->set($db->quoteName('order_shipping_params') . ' = ' . $db->quote(serialize($shippingParams)))
				->where($db->quoteName('order_id') . ' = ' . (int)$order->order_id);
			$db->setQuery($query2);
			$db->execute();
			
			$app->setUserState('hikashop.inpost_locker', '');
		}
	}

	public function onBeforeOrderCreate(&$order, &$do)
	{
		// Sprawdź czy wybrano metodę InPost
		if (empty($order->order_shipping_id)) return;
		
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->select('shipping_type')
			->from($db->quoteName('#__hikashop_shipping'))
			->where($db->quoteName('shipping_id') . ' = ' . (int)$order->order_shipping_id);
		$db->setQuery($query);
		$shippingType = $db->loadResult();
		
		if ($shippingType !== $this->name) return;

		$app = Factory::getApplication();
		$selectedLocker = $this->findSelectedLocker($order);

		// Walidacja - punkt musi być wybrany
		if ($selectedLocker === '') {
			$app->enqueueMessage('Proszę wybrać paczkomat lub punkt odbioru InPost', 'error');
			$do = false;
			return;
		}
	}

	protected function decorateRateWithWidget(&$rate, $selectedLocker)
	{
		$rate->custom_html_no_btn = true;
		$rate->custom_html = '';
		
		$this->loadGeoWidgetAssets();
		
		$shippingId = (int)$rate->shipping_id;
		$warehouseId = isset($rate->shipping_warehouse_id) ? (int)$rate->shipping_warehouse_id : 0;
		$widgetId = 'inpost_widget_' . $shippingId;
		
		$currentValue = $selectedLocker !== '' ? $selectedLocker : Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_NOT_SELECTED');
		$buttonLabel = $selectedLocker !== '' ? Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_CHANGE') : Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SELECT');
		
		$inputName = 'checkout[shipping][' . $warehouseId . '][custom][' . $shippingId . '][' . $this->orderFieldName . ']';
		
		$rate->custom_html .= '<div class="inpost-hika-widget" id="' . $widgetId . '">';
		$rate->custom_html .= '<div class="inpost-hika-label">' . Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SELECTED_LABEL') . '</div>';
		$rate->custom_html .= '<div class="inpost-hika-value" id="' . $widgetId . '_value">' . htmlspecialchars($currentValue) . '</div>';
		$rate->custom_html .= '<button type="button" class="btn btn-small inpost-hika-btn" id="' . $widgetId . '_btn">' . $buttonLabel . '</button>';
		$rate->custom_html .= '<input type="hidden" name="' . htmlspecialchars($inputName) . '" id="' . $widgetId . '_input" value="' . htmlspecialchars($selectedLocker) . '" />';
		$rate->custom_html .= '</div>';
		
		$rate->custom_html .= '<style>
			.inpost-hika-widget{margin:10px 0;padding:10px;border:1px dashed #ccc;border-radius:4px;background:#fafafa}
			.inpost-hika-label{font-size:0.85rem;color:#666}
			.inpost-hika-value{font-weight:600;margin-bottom:8px}
			.inpost-hika-btn{background:#ffca28;color:#2f2f2f;border:1px solid #ffca28;cursor:pointer}
			.inpost-hika-btn:hover{background:#ffc107}
			
			/* Fix EasyPack map styles - prevent conflicts with other Leaflet maps */
			.easypack-modal, .easypack-widget {opacity:1 !important;background:#fff !important}
			.easypack-modal .leaflet-container {opacity:1 !important;background:#fff !important}
			.easypack-modal .leaflet-tile-pane {opacity:1 !important}
			.easypack-modal .leaflet-tile {opacity:1 !important}
			.easypack-modal .leaflet-map-pane {opacity:1 !important}
			.easypack-modal .leaflet-layer {opacity:1 !important}
			.easypack-modal .leaflet-control-container {opacity:1 !important}
			.easypack-modal .leaflet-marker-pane {opacity:1 !important}
			.easypack-modal .leaflet-overlay-pane {opacity:1 !important}
			.easypack-modal .leaflet-shadow-pane {opacity:1 !important}
			.easypack-modal .leaflet-popup-pane {opacity:1 !important}
			.easypack-modal * {visibility:visible !important}
		</style>';
		
		// Pobierz parametry konfiguracji
		$geowidgetApi = isset($rate->shipping_params->geowidget_api) ? $rate->shipping_params->geowidget_api : 'old';
		$geowidgetToken = isset($rate->shipping_params->geowidget_token) ? $rate->shipping_params->geowidget_token : '';
		$geowidgetConfig = isset($rate->shipping_params->geowidget_config) ? $rate->shipping_params->geowidget_config : 'parcelCollect';
		$showLockers = isset($rate->shipping_params->show_parcel_lockers) ? (int)$rate->shipping_params->show_parcel_lockers : 1;
		$showPops = isset($rate->shipping_params->show_pops) ? (int)$rate->shipping_params->show_pops : 0;
		
		$this->addWidgetScript($widgetId, $shippingId, $geowidgetApi, $geowidgetToken, $geowidgetConfig, $showLockers, $showPops);
	}

	protected function addWidgetScript($widgetId, $shippingId, $geowidgetApi = 'old', $geowidgetToken = '', $geowidgetConfig = 'parcelCollect', $showLockers = 1, $showPops = 0)
	{
		$doc = Factory::getApplication()->getDocument();
		$changeLabelJs = json_encode(Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_CHANGE'));
		$loadingMsgJs = json_encode(Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_LOADING'));

		// Buduj typy punktów dla starego API
		$types = array();
		if ($showLockers) $types[] = 'parcel_locker';
		if ($showPops) $types[] = 'pop';
		if (empty($types)) $types[] = 'parcel_locker';
		$typesJs = json_encode($types);

		$apiJs = json_encode($geowidgetApi);
		$tokenJs = json_encode($geowidgetToken);
		$configJs = json_encode($geowidgetConfig);
		// Uri::root() zamiast hardkodowanej ścieżki "/" - działa też gdy Joomla jest w podkatalogu
		$siteRootJs = json_encode(rtrim(Uri::root(), '/'));
		
		// Wybierz URL SDK w zależności od trybu API
		if ($geowidgetApi === 'v5_sandbox') {
			$sdkJsV5 = self::GEO_WIDGET_JS_SANDBOX;
			$sdkCssV5 = self::GEO_WIDGET_CSS_SANDBOX;
		} else {
			$sdkJsV5 = self::GEO_WIDGET_JS;
			$sdkCssV5 = self::GEO_WIDGET_CSS;
		}
		
		$script = "
(function(){
	// Zapobiegaj wielokrotnemu uruchomieniu dla tego samego widgetu
	if(window._inpostWidgetInit && window._inpostWidgetInit['{$widgetId}']) return;
	window._inpostWidgetInit = window._inpostWidgetInit || {};
	window._inpostWidgetInit['{$widgetId}'] = true;
	
	var widgetId = '{$widgetId}';
	var geowidgetApi = {$apiJs};
	var geowidgetToken = {$tokenJs};
	var geowidgetConfig = {$configJs};
	var pointTypes = {$typesJs};
	var sdkJsV5 = '{$sdkJsV5}';
	var sdkCssV5 = '{$sdkCssV5}';
	var siteRoot = {$siteRootJs};
	var sdkLoaded = false;
	var sdkLoading = false;
	
	function saveSelection(text){
		var valueEl = document.getElementById(widgetId + '_value');
		var inputEl = document.getElementById(widgetId + '_input');
		var btnEl = document.getElementById(widgetId + '_btn');
		
		if(valueEl) valueEl.textContent = text;
		if(inputEl) inputEl.value = text;
		if(btnEl) btnEl.textContent = {$changeLabelJs};
		
		// Zapisz przez AJAX
		var xhr = new XMLHttpRequest();
		xhr.open('POST', window.location.href, true);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
		xhr.send('inpost_locker_save=' + encodeURIComponent(text));
	}
	
	// Globalny callback dla GeoWidget v5
	window.inpostPointSelected_{$widgetId} = function(point) {
		console.log('InPost v5 point selected:', point);
		if(point){
			var text = point.name;
			if(point.address_details){
				if(point.address_details.street) text += ' - ' + point.address_details.street;
				if(point.address_details.building_number) text += ' ' + point.address_details.building_number;
				if(point.address_details.city) text += ', ' + point.address_details.city;
			}
			closeModal();
			saveSelection(text);
		}
	};
	
	// Obsluga wiadomosci z iframe (stare API)
	window.addEventListener('message', function(e){
		if(e.data && e.data.type === 'inpost_locker_selected'){
			console.log('InPost old API point selected:', e.data.locker);
			closeModal();
			saveSelection(e.data.locker);
		}
	});
	
	function loadSDKv5(callback){
		if(sdkLoaded){
			callback();
			return;
		}
		if(sdkLoading){
			var check = setInterval(function(){
				if(sdkLoaded){
					clearInterval(check);
					callback();
				}
			}, 100);
			return;
		}
		sdkLoading = true;
		
		// Zaladuj CSS
		if(!document.querySelector('link[href*=\"inpost-geowidget.css\"]')){
			var css = document.createElement('link');
			css.rel = 'stylesheet';
			css.href = sdkCssV5;
			document.head.appendChild(css);
		}
		
		// Zaladuj JS
		if(!document.querySelector('script[src*=\"inpost-geowidget.js\"]')){
			var script = document.createElement('script');
			script.src = sdkJsV5;
			script.defer = true;
			script.onload = function(){
				sdkLoaded = true;
				sdkLoading = false;
				callback();
			};
			script.onerror = function(){
				sdkLoading = false;
				alert('Nie mozna zaladowac mapy InPost');
			};
			document.head.appendChild(script);
		} else {
			sdkLoaded = true;
			sdkLoading = false;
			callback();
		}
	}
	
	function closeModal(){
		var overlay = document.getElementById('inpost-modal-overlay');
		if(overlay) overlay.remove();
	}
	
	function openMapOld(){
		// Stare API - iframe z map.html
		closeModal();
		
		var overlay = document.createElement('div');
		overlay.id = 'inpost-modal-overlay';
		overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:99999;display:flex;align-items:center;justify-content:center;';
		
		var modal = document.createElement('div');
		modal.style.cssText = 'background:#fff;width:95%;max-width:1000px;height:85vh;max-height:700px;border-radius:8px;overflow:hidden;position:relative;box-shadow:0 4px 20px rgba(0,0,0,0.3);';
		
		var closeBtn = document.createElement('button');
		closeBtn.innerHTML = '&times;';
		closeBtn.style.cssText = 'position:absolute;top:10px;right:15px;z-index:100;background:#fff;border:2px solid #333;font-size:28px;font-weight:bold;cursor:pointer;width:40px;height:40px;border-radius:50%;box-shadow:0 2px 5px rgba(0,0,0,0.2);color:#333;display:flex;align-items:center;justify-content:center;padding:0;line-height:1;';
		closeBtn.onclick = function(){ closeModal(); };
		
		var iframe = document.createElement('iframe');
		var url = siteRoot + '/plugins/hikashopshipping/inpost_hika/map.html?types=' + pointTypes.join(',');
		iframe.src = url;
		iframe.style.cssText = 'width:100%;height:100%;border:none;';
		
		modal.appendChild(closeBtn);
		modal.appendChild(iframe);
		overlay.appendChild(modal);
		document.body.appendChild(overlay);
		
		overlay.onclick = function(e){
			if(e.target === overlay) closeModal();
		};
		
		function escHandler(e){
			if(e.key === 'Escape'){
				closeModal();
				document.removeEventListener('keydown', escHandler);
			}
		}
		document.addEventListener('keydown', escHandler);
	}
	
	function openMapV5(){
		// Nowe API v5 - inpost-geowidget element w popup
		loadSDKv5(function(){
			closeModal();
			
			var overlay = document.createElement('div');
			overlay.id = 'inpost-modal-overlay';
			overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:99999;display:flex;align-items:center;justify-content:center;';
			
			var modal = document.createElement('div');
			modal.style.cssText = 'background:#fff;width:95%;max-width:1000px;height:85vh;max-height:700px;border-radius:8px;overflow:hidden;position:relative;box-shadow:0 4px 20px rgba(0,0,0,0.3);';
			
			var geowidget = document.createElement('inpost-geowidget');
			geowidget.setAttribute('token', geowidgetToken);
			geowidget.setAttribute('language', 'pl');
			geowidget.setAttribute('config', geowidgetConfig);
			geowidget.setAttribute('onpoint', 'inpostPointSelected_{$widgetId}');
			geowidget.style.cssText = 'display:block;width:100%;height:100%;';
			
			modal.appendChild(geowidget);
			overlay.appendChild(modal);
			document.body.appendChild(overlay);
			
			overlay.onclick = function(e){
				if(e.target === overlay) closeModal();
			};
			
			function escHandler(e){
				if(e.key === 'Escape'){
					closeModal();
					document.removeEventListener('keydown', escHandler);
				}
			}
			document.addEventListener('keydown', escHandler);
		});
	}
	
	function openMap(){
		var btn = document.getElementById(widgetId + '_btn');
		if(btn) btn.textContent = {$loadingMsgJs};

		if(geowidgetApi === 'v5' || geowidgetApi === 'v5_sandbox'){
			openMapV5();
		} else {
			openMapOld();
		}

		if(btn) btn.textContent = {$changeLabelJs};
	}
	
	document.addEventListener('click', function(e){
		var btn = document.getElementById(widgetId + '_btn');
		if(btn && (e.target === btn || btn.contains(e.target))){
			e.preventDefault();
			e.stopPropagation();
			openMap();
		}
	});
	
	if(window.Oby && !window._inpostValidationRegistered){
		window._inpostValidationRegistered = true;
		window._inpostShowingAlert = false;
		
		window.Oby.registerAjax(['checkoutFormSubmit'], function(params){
			if(window._inpostShowingAlert) return false;
			
			var widgets = document.querySelectorAll('.inpost-hika-widget');
			var valid = true;
			
			widgets.forEach(function(widget){
				var input = widget.querySelector('input[type=\"hidden\"]');
				
				var checkedRadio = document.querySelector('input[name*=\"shipping\"][type=\"radio\"]:checked');
				if(!checkedRadio) return;
				
				var radioContainer = checkedRadio.closest('.hikashop_shipping_method, .hikashop_shipping_group, [data-shipping-id], tr, .shipping-method');
				if(radioContainer && radioContainer.contains(widget)){
					if(!input || !input.value || input.value.trim() === ''){
						valid = false;
					}
				}
			});
			
			if(!valid){
				window._inpostShowingAlert = true;
				alert('Prosze wybrac paczkomat lub punkt odbioru InPost');
				setTimeout(function(){ window._inpostShowingAlert = false; }, 500);
				
				if(params && params.element){
					params.element.setAttribute('data-hk-stop', '1');
				}
				return false;
			}
		});
	}
})();
";
		$doc->addScriptDeclaration($script);
	}

	protected function loadGeoWidgetAssets()
	{
		// SDK jest teraz ładowane dynamicznie
	}

	/**
	 * Szuka wybranego paczkomatu we wszystkich znanych źródłach, w kolejności:
	 * order_shipping_params (po potwierdzeniu zamówienia) -> cart_params już
	 * załadowany na obiekcie zamówienia -> cart_params z bazy po cart_id -> sesja.
	 */
	protected function findSelectedLocker($order)
	{
		$selected = '';

		// 1. order_shipping_params (ustawiane po onAfterOrderConfirm)
		if (!empty($order->order_shipping_params)) {
			$shippingParams = $order->order_shipping_params;
			if (is_string($shippingParams)) {
				$shippingParams = @unserialize($shippingParams);
			}
			if (is_object($shippingParams) && !empty($shippingParams->inpost_locker)) {
				$selected = $shippingParams->inpost_locker;
			}
		}

		// 2. cart_params już załadowany jako obiekt na zamówieniu
		if ($selected === '' && !empty($order->cart_params) && is_object($order->cart_params)) {
			$selected = $this->extractLockerFromCartParams($order->cart_params);
		}

		// 3. cart_params z bazy po cart_id
		if ($selected === '' && !empty($order->cart) && !empty($order->cart->cart_id)) {
			$db = Factory::getContainer()->get(DatabaseInterface::class);
			$query = $db->getQuery(true)
				->select($db->quoteName('cart_params'))
				->from($db->quoteName('#__hikashop_cart'))
				->where($db->quoteName('cart_id') . ' = ' . (int)$order->cart->cart_id);
			$db->setQuery($query);
			$cartParamsStr = $db->loadResult();
			if (!empty($cartParamsStr)) {
				$cartParams = @unserialize($cartParamsStr);
				if (is_object($cartParams)) {
					$selected = $this->extractLockerFromCartParams($cartParams);
				}
			}
		}

		// 4. Fallback do sesji użytkownika
		if ($selected === '') {
			$selected = Factory::getApplication()->getUserState('hikashop.inpost_locker', '');
		}

		return $selected;
	}

	/**
	 * Wyciąga wybrany paczkomat z obiektu cart_params: bezpośrednio z pola
	 * inpost_locker albo z shipping->[warehouse_id]->custom->[shipping_id]
	 */
	protected function extractLockerFromCartParams($cartParams)
	{
		if (!empty($cartParams->inpost_locker)) {
			return $cartParams->inpost_locker;
		}

		if (!empty($cartParams->shipping)) {
			foreach ($cartParams->shipping as $warehouseData) {
				if (is_object($warehouseData) && !empty($warehouseData->custom)) {
					foreach ($warehouseData->custom as $customData) {
						if (is_object($customData) && !empty($customData->{$this->orderFieldName})) {
							return $customData->{$this->orderFieldName};
						}
					}
				}
			}
		}

		return '';
	}

	protected function ensureOrderFieldExists()
	{
		static $ensured = false;
		if ($ensured) return;
		
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		
		$query = $db->getQuery(true)
			->select($db->quoteName(array('field_id', 'field_realname', 'field_display')))
			->from($db->quoteName('#__hikashop_field'))
			->where($db->quoteName('field_namekey') . ' = ' . $db->quote($this->orderFieldName));
		$db->setQuery($query);
		$existingField = $db->loadObject();

		if (!$existingField) {
			// WAŻNE: nazwy kolumn MUSZĄ zgadzać się ze schematem `#__hikashop_field`.
			// Kolumna „pole widoczne w komponencie na froncie" to `field_frontcomp`, NIE
			// `field_frontend` — takiej kolumny HikaShop nie ma. Ustawienie jej powodowało
			// błąd MySQL #1054 przy insertObject → CAŁY insert się nie wykonywał i pole nigdy
			// nie powstawało (paczkomat widać było tylko przez nasz własny box, nie przez pole).
			// `field_value` i `field_default` są NOT NULL bez wartości domyślnej → muszą być ustawione.
			$field = new \stdClass();
			$field->field_table = 'order';
			$field->field_realname = Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_FIELD_LABEL');
			$field->field_namekey = $this->orderFieldName;
			$field->field_type = 'text';
			$field->field_value = '';
			$field->field_default = '';
			$field->field_published = 1;
			$field->field_ordering = 99;
			$field->field_required = 0;
			$field->field_frontcomp = 0;
			$field->field_backend = 1;
			// Wartości DOMYŚLNE tylko przy zakładaniu pola. Paczkomat pokazuje się w szczegółach
			// zamówienia ("Dodatkowe informacje" = field_backend=1) oraz jako kolumna na LIŚCIE
			// zamówień (field_backend_listing=1). To tylko domyślne ustawienie startowe - później
			// administrator może dowolnie odznaczyć te opcje w edytorze pól HikaShop i wtyczka
			// NIE będzie ich przywracać (patrz gałąź "else" niżej).
			$field->field_backend_listing = 1;
			$field->field_core = 0;
			$field->field_access = 'all';
			$field->field_display = ';front_order=1;invoice=0;mail_order_notif=1;';
			try {
				$db->insertObject('#__hikashop_field', $field);
			} catch (\Exception $e) {
				// Pole jest wygodą (paczkomat i tak pokazuje nasz box) - nie wywalaj widoku
				// zamówienia, gdyby schemat #__hikashop_field różnił się w danej wersji HikaShopa.
				$this->debug('insertObject pola inpost_locker nieudany', $e->getMessage());
			}
		} else {
			// Pole już istnieje - NIE ruszamy ustawień WYŚWIETLANIA (checkboxy w edytorze pól:
			// "Listing" = field_backend_listing, "Backend" = field_backend, publikacja). Administrator
			// ma prawo skonfigurować je ręcznie. Wcześniejsze wersje wymuszały te flagi przy KAŻDYM
			// wyświetleniu zamówienia, przez co ręczne zmiany "same się cofały" (np. odznaczony
			// "Listing" wracał do zaznaczenia/odznaczenia). Uzupełniamy WYŁĄCZNIE wartości puste
			// (podpis, strefy front-endu) - to nie koliduje z checkboxami wyświetlania.
			$update = new \stdClass();
			$update->field_id = $existingField->field_id;
			$needsUpdate = false;

			if (trim((string) $existingField->field_realname) === '') {
				$update->field_realname = Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_FIELD_LABEL');
				$needsUpdate = true;
			}
			if (trim((string) $existingField->field_display, '; ') === '') {
				$update->field_display = ';front_order=1;invoice=0;mail_order_notif=1;';
				$needsUpdate = true;
			}

			if ($needsUpdate) {
				$db->updateObject('#__hikashop_field', $update, 'field_id');
			}
		}
		
		$columns = $db->getTableColumns('#__hikashop_order');
		if (!isset($columns[$this->orderFieldName])) {
			$db->setQuery('ALTER TABLE ' . $db->quoteName('#__hikashop_order') . ' ADD ' . $db->quoteName($this->orderFieldName) . ' TEXT NULL');
			$db->execute();
		}
		
		$ensured = true;
	}
}
