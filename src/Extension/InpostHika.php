<?php
/**
 * @package     HikaShop InPost Paczkomaty Shipping Plugin
 * @version     4.0.0
 * @copyright   (C) 2026
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Pablop76\Plugin\HikashopShipping\InpostHika\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die('Restricted access');

class InpostHika extends \hikashopShippingPlugin
{
	// GeoWidget SDK - zawsze produkcja (mapa nie wymaga autoryzacji)
	const GEO_WIDGET_JS = 'https://geowidget.easypack24.net/js/sdk-for-javascript.js';
	const GEO_WIDGET_CSS = 'https://geowidget.easypack24.net/css/easypack.css';
	
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
		// Mapa GeoWidget
		'map_type' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_MAP_TYPE', 'list', array(
			'osm' => 'OpenStreetMap',
			'google' => 'Google Maps'
		)),
		'google_api_key' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_GOOGLE_KEY', 'input', ''),
		'default_lat' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_DEFAULT_LAT', 'input', '52.2297'),
		'default_lng' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_DEFAULT_LNG', 'input', '21.0122'),
		'default_zoom' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_DEFAULT_ZOOM', 'input', ''),
		'show_parcel_lockers' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SHOW_LOCKERS', 'boolean', '1'),
		'show_pops' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SHOW_POPS', 'boolean', '0'),
		'debug' => array('PLG_HIKASHOPSHIPPING_INPOST_HIKA_DEBUG', 'boolean', '0')
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
		
		// Upewnij się że kolumna shipment_id istnieje (tylko nie w emailu)
		if ($isAdmin) {
			$this->ensureShipmentIdFieldExists();
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
		
		if (empty($locker)) {
			return;
		}
		
		// Pobierz shipping_params dla konfiguracji ShipX
		$shippingParams = $this->getShippingParamsForOrder($order);
		
		// Pobierz shipment_id z bazy (jeśli już utworzono przesyłkę)
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->select($db->quoteName('inpost_shipment_id'))
			->from($db->quoteName('#__hikashop_order'))
			->where($db->quoteName('order_id') . ' = ' . (int)$order->order_id);
		$db->setQuery($query);
		$shipmentId = $db->loadResult();
		
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
		
		// Pobierz tylko kod paczkomatu (pierwszy element przed " - ")
		$lockerName = $locker;
		if (strpos($locker, ' - ') !== false) {
			$lockerName = trim(explode(' - ', $locker)[0]);
		}
		// Dodatkowo wyczyść z niepotrzebnych znaków - zostaw tylko litery i cyfry
		$lockerCode = preg_replace('/[^A-Z0-9]/i', '', $lockerName);
		
		// Sekcja ShipX API (tylko admin)
		echo '<div id="inpost_shipx_admin" style="background:#e3f2fd; border:2px solid #2196f3; padding:15px; margin:15px 0; border-radius:8px; font-size:14px;">';
		echo '<strong style="color:#1565c0;">🚚 InPost ShipX (Admin):</strong> <small>(kod: ' . htmlspecialchars($lockerCode) . ')</small><br>';
		
		if (!empty($shipmentId)) {
			// Przesyłka już utworzona - sprawdź jej status
			$shippingParams = $this->getShippingParamsForOrder($order);
			$shipmentInfo = $this->callShipXApi('GET', '/v1/shipments/' . $shipmentId, null, $shippingParams);
			$shipmentStatus = $shipmentInfo->status ?? 'unknown';
			$isConfirmed = ($shipmentStatus === 'confirmed');
			
			echo '<span style="color:#28a745; font-weight:bold;">✅ ' . Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SHIPMENT_CREATED') . ': ' . htmlspecialchars($shipmentId) . '</span>';
			echo ' <span style="color:#666;">(status: ' . htmlspecialchars($shipmentStatus) . ')</span><br>';
			
			if ($isConfirmed) {
				// Przesyłka opłacona - pokaż przycisk pobierania etykiety
				echo '<form method="post" style="display:inline-block; margin-top:10px;">';
				echo '<input type="hidden" name="inpost_action" value="get_label" />';
				echo '<input type="hidden" name="order_id" value="' . (int)$order->order_id . '" />';
				echo HTMLHelper::_('form.token');
				echo '<button type="submit" class="btn btn-small btn-success" style="background:#28a745; color:#fff; padding:8px 15px; border-radius:4px; border:none; cursor:pointer;">';
				echo '📄 ' . Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_DOWNLOAD_LABEL');
				echo '</button>';
				echo '</form>';
			} else {
				// Przesyłka nieopłacona - pokaż przycisk ponownego utworzenia
				echo '<form method="post" style="display:inline-block; margin-top:10px; margin-right:10px;">';
				echo '<input type="hidden" name="inpost_action" value="recreate_shipment" />';
				echo '<input type="hidden" name="order_id" value="' . (int)$order->order_id . '" />';
				echo '<input type="hidden" name="locker_name" value="' . htmlspecialchars($lockerCode) . '" />';
				echo HTMLHelper::_('form.token');
				echo '<button type="submit" class="btn btn-small btn-warning" style="background:#ffc107; color:#333; padding:8px 15px; border-radius:4px; border:none; cursor:pointer;">';
				echo '🔄 Utwórz ponownie';
				echo '</button>';
				echo '</form>';
				echo '<small style="color:#856404; display:block; margin-top:5px;">Przesyłka nieopłacona - doładuj konto InPost i utwórz ponownie.</small>';
			}
		} else {
			// Sprawdź czy skonfigurowano API
			$hasApiConfig = !empty($shippingParams->shipx_token) && !empty($shippingParams->shipx_organization_id);
			$isSandbox = ($shippingParams->api_mode ?? 'production') === 'sandbox';
			
			if ($hasApiConfig) {
				echo '<form method="post" style="display:inline-block;">';
				echo '<input type="hidden" name="inpost_action" value="create_shipment" />';
				echo '<input type="hidden" name="order_id" value="' . (int)$order->order_id . '" />';
				if ($isSandbox) {
					// W trybie sandbox pozwól wpisać kod ręcznie (testowe paczkomaty)
					echo '<input type="text" name="locker_name" value="' . htmlspecialchars($lockerCode) . '" style="width:100px; padding:5px; margin-right:5px;" placeholder="Kod paczkomatu" />';
					echo '<small style="color:#666; display:block; margin-bottom:5px;">Sandbox: użyj np. BBI02A, AND01A</small>';
				} else {
					echo '<input type="hidden" name="locker_name" value="' . htmlspecialchars($lockerCode) . '" />';
				}
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
				$this->handleCreateShipment($order, $lockerName, $shippingParams);
				break;
				
			case 'recreate_shipment':
				$lockerName = $input->getString('locker_name', '');
				$this->handleRecreateShipment($order, $lockerName, $shippingParams);
				break;
				
			case 'get_label':
				$this->handleGetLabel($order, $shippingParams);
				break;
				
			case 'buy_shipment':
				$this->handleBuyShipment($order, $shippingParams);
				break;
		}
	}
	
	/**
	 * Opłaca istniejącą przesyłkę
	 */
	protected function handleBuyShipment($order, $shippingParams)
	{
		$app = Factory::getApplication();
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		
		$query = $db->getQuery(true)
			->select($db->quoteName('inpost_shipment_id'))
			->from($db->quoteName('#__hikashop_order'))
			->where($db->quoteName('order_id') . ' = ' . (int)$order->order_id);
		$db->setQuery($query);
		$shipmentId = $db->loadResult();
		
		if (empty($shipmentId)) {
			$app->enqueueMessage('Brak ID przesyłki do opłacenia', 'error');
			return;
		}
		
		$buyResult = $this->buyShipmentOffer($shipmentId, $shippingParams);

		// Wyciągnij kod HTTP i komunikat błędu jeśli zwróciło API ShipX
		$httpCode = is_object($buyResult) && isset($buyResult->_httpCode) ? (int)$buyResult->_httpCode : null;
		$apiError = null;
		if (is_object($buyResult)) {
			$apiError = $buyResult->error ?? $buyResult->message ?? null;
			if ($apiError && isset($buyResult->description)) {
				$apiError .= ' - ' . $buyResult->description;
			}
		}

		if ($buyResult && isset($buyResult->status) && $buyResult->status === 'confirmed') {
			$app->enqueueMessage('Przesyłka InPost opłacona! ID: ' . $shipmentId, 'success');
		} elseif ($buyResult && isset($buyResult->_no_offer)) {
			// Brak dostępnej oferty - prawdopodobnie brak środków na koncie InPost lub oferta wygasła
			$cancelOk = $this->cancelShipment($shipmentId, $shippingParams);
			$query = $db->getQuery(true)
				->update($db->quoteName('#__hikashop_order'))
				->set($db->quoteName('inpost_shipment_id') . ' = NULL')
				->where($db->quoteName('order_id') . ' = ' . (int)$order->order_id);
			$db->setQuery($query);
			$db->execute();

			$app->enqueueMessage(
				'Nie można opłacić przesyłki. Sprawdź stan konta i weryfikację w Managerze Paczek InPost, a następnie spróbuj ponownie.',
				'error'
			);
		} elseif ($apiError || $httpCode) {
			$app->enqueueMessage('Nie udało się opłacić przesyłki. ' . ($httpCode ? 'HTTP ' . $httpCode . ': ' : '') . ($apiError ?: 'Brak szczegółów błędu.') . ' Sprawdź w Managerze Paczek.', 'error');
		} else {
			$app->enqueueMessage('Nie udało się opłacić przesyłki. Sprawdź w Managerze Paczek.', 'error');
		}
		
		// Przekieruj z powrotem na stronę zamówienia
		$redirectUrl = 'index.php?option=com_hikashop&ctrl=order&task=edit&cid=' . (int)$order->order_id;
		$app->redirect(Route::_($redirectUrl, false));
	}
	
	/**
	 * Anuluje starą przesyłkę i wraca do stanu początkowego
	 */
	protected function handleRecreateShipment($order, $lockerName, $shippingParams)
	{
		$app = Factory::getApplication();
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		
		// Pobierz stare shipment_id
		$query = $db->getQuery(true)
			->select($db->quoteName('inpost_shipment_id'))
			->from($db->quoteName('#__hikashop_order'))
			->where($db->quoteName('order_id') . ' = ' . (int)$order->order_id);
		$db->setQuery($query);
		$oldShipmentId = $db->loadResult();
		
		// Anuluj starą przesyłkę (best effort)
		if (!empty($oldShipmentId)) {
			$this->cancelShipment($oldShipmentId, $shippingParams);
		}
		
		// Wyczyść stare ID
		$query = $db->getQuery(true)
			->update($db->quoteName('#__hikashop_order'))
			->set($db->quoteName('inpost_shipment_id') . ' = NULL')
			->where($db->quoteName('order_id') . ' = ' . (int)$order->order_id);
		$db->setQuery($query);
		$db->execute();
		
		$app->enqueueMessage('Stara przesyłka została usunięta. Możesz utworzyć nową.', 'message');
		
		// Przekieruj z powrotem na stronę zamówienia
		$redirectUrl = 'index.php?option=com_hikashop&ctrl=order&task=edit&cid=' . (int)$order->order_id;
		$app->redirect(Route::_($redirectUrl, false));
	}
	
	/**
	 * Tworzy przesyłkę w ShipX API
	 */
	protected function handleCreateShipment($order, $lockerName, $shippingParams)
	{
		$app = Factory::getApplication();
		
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
		
		// Przygotuj dane przesyłki dla API
		$shipmentData = array(
			'receiver' => array(
				'name' => trim($address->address_firstname . ' ' . $address->address_lastname),
				'company_name' => $address->address_company ?? '',
				'first_name' => $address->address_firstname,
				'last_name' => $address->address_lastname,
				'email' => $address->user_email ?? '',
				'phone' => $address->address_telephone ?? ''
			),
			'sender' => array(
				'name' => $shippingParams->sender_name ?? '',
				'company_name' => $shippingParams->sender_company ?? '',
				'email' => $shippingParams->sender_email ?? '',
				'phone' => $shippingParams->sender_phone ?? '',
				'address' => array(
					'street' => $shippingParams->sender_street ?? '',
					'building_number' => $shippingParams->sender_building ?? '',
					'city' => $shippingParams->sender_city ?? '',
					'post_code' => $shippingParams->sender_postcode ?? '',
					'country_code' => 'PL'
				)
			),
			'parcels' => array(
				array(
					'template' => $shippingParams->default_parcel_size ?? 'small'
				)
			),
			'service' => 'inpost_locker_standard',
			'reference' => 'Zamówienie #' . $order->order_id,
			'custom_attributes' => array(
				'target_point' => $lockerName,
				'sending_method' => 'dispatch_order'
			)
		);
		
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
			
			$this->debug('Shipment created successfully', ['shipment_id' => $result->id], $shippingParams);
			
			// Zawsze próbuj opłacić przesyłkę
			$buyResult = $this->buyShipmentOffer($result->id, $shippingParams);
			
			if ($buyResult && isset($buyResult->status) && $buyResult->status === 'confirmed') {
				$app->enqueueMessage('Przesyłka InPost utworzona i opłacona! ID: ' . $result->id, 'success');
			} elseif ($buyResult && isset($buyResult->_no_offer)) {
				// Brak środków - usuń ID przesyłki
				$query = $db->getQuery(true)
					->update($db->quoteName('#__hikashop_order'))
					->set($db->quoteName('inpost_shipment_id') . ' = NULL')
					->where($db->quoteName('order_id') . ' = ' . (int)$order->order_id);
				$db->setQuery($query);
				$db->execute();
				
				$app->enqueueMessage('Nie można utworzyć przesyłki - brak środków na koncie InPost. Doładuj konto w Managerze Paczek i spróbuj ponownie.', 'error');
			} else {
				$app->enqueueMessage('Przesyłka InPost utworzona! ID: ' . $result->id . ' (wymaga opłacenia w Managerze Paczek)', 'warning');
			}
			
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
	 * Kupuje/potwierdza przesyłkę (aktywuje etykietę)
	 */
	protected function buyShipmentOffer($shipmentId, $shippingParams)
	{
		// Najpierw pobierz dane przesyłki (zawiera offers)
		$shipment = $this->callShipXApi(
			'GET',
			'/v1/shipments/' . $shipmentId,
			null,
			$shippingParams
		);
		
		$this->debug('Get shipment for buy', $shipment, $shippingParams);
		
		// Sprawdź czy przesyłka jest już opłacona (sandbox automatycznie opłaca)
		if ($shipment && $shipment->status === 'confirmed') {
			$this->debug('Shipment already confirmed (paid)', null, $shippingParams);
			return $shipment;
		}
		
		// Znajdź offer_id do kupienia
		$offerId = null;
		if ($shipment && !empty($shipment->offers)) {
			foreach ($shipment->offers as $offer) {
				if ($offer->status === 'available' || $offer->status === 'offer_selected') {
					$offerId = $offer->id;
					break;
				}
			}
		}
		
		if (!$offerId) {
			$this->debug('No available offer_id found', null, $shippingParams);
			if (is_object($shipment)) {
				$shipment->_no_offer = true;
			}
			return $shipment;
		}
		
		// Kup z offer_id
		$buyResult = $this->callShipXApi(
			'POST',
			'/v1/shipments/' . $shipmentId . '/buy',
			array('offer_id' => $offerId),
			$shippingParams
		);
		
		$this->debug('Buy shipment result', $buyResult, $shippingParams);
		return $buyResult;
	}

	/**
	 * Anuluje istniejącą przesyłkę w ShipX (best effort)
	 */
	protected function cancelShipment($shipmentId, $shippingParams)
	{
		$result = $this->callShipXApi(
			'POST',
			'/v1/shipments/' . $shipmentId . '/cancel',
			null,
			$shippingParams
		);
		$this->debug('Cancel shipment result', $result, $shippingParams);
		return $result && isset($result->status) ? $result->status === 'cancelled' : false;
	}
	
	/**
	 * Pobiera etykietę przesyłki
	 */
	protected function handleGetLabel($order, $shippingParams)
	{
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->select($db->quoteName('inpost_shipment_id'))
			->from($db->quoteName('#__hikashop_order'))
			->where($db->quoteName('order_id') . ' = ' . (int)$order->order_id);
		$db->setQuery($query);
		$shipmentId = $db->loadResult();
		
		if (empty($shipmentId)) {
			Factory::getApplication()->enqueueMessage('Brak ID przesyłki', 'error');
			return;
		}
		
		$this->debug('Getting label for shipment', ['shipment_id' => $shipmentId], $shippingParams);
		
		// Pobierz etykietę jako PDF
		$labelData = $this->callShipXApi(
			'GET',
			'/v1/shipments/' . $shipmentId . '/label?format=pdf&type=normal',
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
			Factory::getApplication()->enqueueMessage($errorMsg . ' Przesyłka może nie być jeszcze opłacona.', 'error');
		}
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
		$query = $db->getQuery(true)
			->select($db->quoteName('shipping_params'))
			->from($db->quoteName('#__hikashop_shipping'))
			->where($db->quoteName('shipping_type') . ' = ' . $db->quote($this->name))
			->where($db->quoteName('shipping_published') . ' = 1')
			->setLimit(1);
		$db->setQuery($query);
		$result = $db->loadResult();
		
		if ($result) {
			return unserialize($result);
		}
		
		return new \stdClass();
	}
	
	/**
	 * Upewnia się że kolumna inpost_shipment_id istnieje
	 */
	protected function ensureShipmentIdFieldExists()
	{
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$columns = $db->getTableColumns('#__hikashop_order');
		
		if (!isset($columns['inpost_shipment_id'])) {
			$db->setQuery('ALTER TABLE ' . $db->quoteName('#__hikashop_order') . ' ADD ' . $db->quoteName('inpost_shipment_id') . ' VARCHAR(50) NULL');
			$db->execute();
		}
	}

	public function onShippingDisplay(&$order, &$dbrates, &$usable_rates, &$messages)
	{
		$this->ensureOrderFieldExists();
		
		$app = Factory::getApplication();
		$selectedLocker = $app->getUserState('hikashop.inpost_locker', '');
		
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
		$element->shipping_name = Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_NAME');
		$element->shipping_description = '';
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
		// Mapa GeoWidget
		$element->shipping_params->map_type = 'osm';
		$element->shipping_params->google_api_key = '';
		$element->shipping_params->default_lat = '52.2297';
		$element->shipping_params->default_lng = '21.0122';
		$element->shipping_params->default_zoom = '';
		$element->shipping_params->show_parcel_lockers = 1;
		$element->shipping_params->show_pops = 0;
		$element->shipping_params->debug = 0;
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
		
		$logFile = JPATH_ROOT . '/logs/inpost_hika_debug.log';
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
		$selected = $app->getUserState('hikashop.inpost_locker', '');
		
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
					$shippingParams = unserialize($order->order_shipping_params);
				} else {
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
		
		// Sprawdź czy punkt został wybrany
		$app = Factory::getApplication();
		$selectedLocker = $app->getUserState('hikashop.inpost_locker', '');
		
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
		$apiMode = isset($rate->shipping_params->api_mode) ? $rate->shipping_params->api_mode : 'production';
		$showLockers = isset($rate->shipping_params->show_parcel_lockers) ? (int)$rate->shipping_params->show_parcel_lockers : 1;
		$showPops = isset($rate->shipping_params->show_pops) ? (int)$rate->shipping_params->show_pops : 0;
		$mapType = isset($rate->shipping_params->map_type) ? $rate->shipping_params->map_type : 'osm';
		$googleApiKey = isset($rate->shipping_params->google_api_key) ? $rate->shipping_params->google_api_key : '';
		$defaultLat = isset($rate->shipping_params->default_lat) ? (float)$rate->shipping_params->default_lat : 52.2297;
		$defaultLng = isset($rate->shipping_params->default_lng) ? (float)$rate->shipping_params->default_lng : 21.0122;
		$defaultZoom = isset($rate->shipping_params->default_zoom) ? (int)$rate->shipping_params->default_zoom : 14;
		
		$this->addWidgetScript($widgetId, $shippingId, $showLockers, $showPops, $mapType, $googleApiKey, $defaultLat, $defaultLng, $defaultZoom, $apiMode);
	}

	protected function addWidgetScript($widgetId, $shippingId, $showLockers = 1, $showPops = 0, $mapType = 'osm', $googleApiKey = '', $defaultLat = 52.2297, $defaultLng = 21.0122, $defaultZoom = 10, $apiMode = 'production')
	{
		$doc = Factory::getApplication()->getDocument();
		$changeLabel = addslashes(Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_CHANGE'));
		$loadingMsg = addslashes(Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_LOADING'));
		
		// Buduj listę typów punktów na podstawie konfiguracji
		$types = array();
		if ($showLockers) $types[] = 'parcel_locker';
		if ($showPops) $types[] = 'pop';
		if (empty($types)) $types[] = 'parcel_locker';
		$typesJs = json_encode($types);
		
		// Sanityzacja parametrów mapy
		$mapType = in_array($mapType, array('osm', 'google')) ? $mapType : 'osm';
		$searchType = $mapType;
		$googleApiKeyJs = addslashes($googleApiKey);
		
		// GeoWidget SDK - zawsze produkcja
		$sdkJs = self::GEO_WIDGET_JS;
		$sdkCss = self::GEO_WIDGET_CSS;
		
		// Domyślny zoom zależny od typu mapy jeśli nie ustawiony
		if (empty($defaultZoom) || $defaultZoom == 0) {
			$defaultZoom = ($mapType === 'google') ? 6 : 13;
		}
		
		$script = "
(function(){
	var widgetId = '{$widgetId}';
	var SDK_JS = '{$sdkJs}';
	var SDK_CSS = '{$sdkCss}';
	var pointTypes = {$typesJs};
	var mapType = '{$mapType}';
	var searchType = '{$searchType}';
	var googleApiKey = '{$googleApiKeyJs}';
	var defaultLat = {$defaultLat};
	var defaultLng = {$defaultLng};
	var defaultZoom = {$defaultZoom};
	var pendingOpen = false;
	
	// Dodaj CSS od razu
	if(!document.querySelector('link[href*=\"geowidget\"]')){
		var link = document.createElement('link');
		link.rel = 'stylesheet';
		link.href = SDK_CSS;
		document.head.appendChild(link);
	}
	
	// Załaduj SDK
	function loadSDK(callback){
		if(window._inpostSDKLoaded && window._inpostInitDone){
			callback();
			return;
		}
		
		if(window._inpostSDKLoading){
			window._inpostCallbacks = window._inpostCallbacks || [];
			window._inpostCallbacks.push(callback);
			return;
		}
		
		window._inpostSDKLoading = true;
		window._inpostCallbacks = [callback];
		
		var script = document.createElement('script');
		script.src = SDK_JS;
		script.onload = function(){
			window._inpostSDKLoaded = true;
			initEasyPack();
			
			var cbs = window._inpostCallbacks || [];
			window._inpostCallbacks = [];
			cbs.forEach(function(cb){ cb(); });
		};
		document.body.appendChild(script);
	}
	
	function initEasyPack(){
		if(!window.easyPack || window._inpostInitDone) return;
		window._inpostInitDone = true;
		
		var config = {
			defaultLocale: 'pl',
			mapType: mapType,
			searchType: searchType,
			points: {
				types: pointTypes,
				functions: ['parcel_collect']
			},
			map: {
				initialTypes: pointTypes,
				useGeolocation: true,
				initialZoom: defaultZoom,
				typeFiltering: false,
				filtersInColumn: false,
				defaultLocation: [defaultLat, defaultLng]
			},
			filters: false,
			closeToMeButton: true
		};
		
		if(mapType === 'google' && googleApiKey) {
			config.apiKey = googleApiKey;
		}
		
		easyPack.init(config);
	}
	
	function openMap(){
		var btn = document.getElementById(widgetId + '_btn');
		if(btn) btn.textContent = '{$loadingMsg}';
		
		loadSDK(function(){
			if(btn) btn.textContent = '{$changeLabel}';
			
			if(!window.easyPack || !window._inpostInitDone){
				alert('Błąd ładowania mapy');
				return;
			}
			
			easyPack.modalMap(function(point, modal){
				if(!point) return;
				
				var text = point.name;
				if(point.address && point.address.line1) text += ' - ' + point.address.line1;
				if(point.address && point.address.line2) text += ' - ' + point.address.line2;
				
				var valueEl = document.getElementById(widgetId + '_value');
				var inputEl = document.getElementById(widgetId + '_input');
				var btnEl = document.getElementById(widgetId + '_btn');
				
				if(valueEl) valueEl.textContent = text;
				if(inputEl) inputEl.value = text;
				if(btnEl) btnEl.textContent = '{$changeLabel}';
				
				var xhr = new XMLHttpRequest();
				xhr.open('POST', window.location.href, true);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				xhr.send('inpost_locker_save=' + encodeURIComponent(text));
				
				if(modal && typeof modal.closeModal === 'function') modal.closeModal();
			}, {width:1000, height:650});
		});
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
				alert('Proszę wybrać paczkomat lub punkt odbioru InPost');
				setTimeout(function(){ window._inpostShowingAlert = false; }, 500);
				
				if(params && params.element){
					params.element.setAttribute('data-hk-stop', '1');
				}
				return false;
			}
		});
	}
	
	setTimeout(function(){ loadSDK(function(){}); }, 100);
})();
";
		$doc->addScriptDeclaration($script);
	}

	protected function loadGeoWidgetAssets()
	{
		// SDK jest teraz ładowane dynamicznie przy pierwszym kliknięciu przycisku
	}

	protected function ensureOrderFieldExists()
	{
		static $ensured = false;
		if ($ensured) return;
		
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		
		$query = $db->getQuery(true)
			->select('field_id')
			->from($db->quoteName('#__hikashop_field'))
			->where($db->quoteName('field_namekey') . ' = ' . $db->quote($this->orderFieldName));
		$db->setQuery($query);
		
		if (!$db->loadResult()) {
			$field = new \stdClass();
			$field->field_table = 'order';
			$field->field_realname = Text::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_FIELD_LABEL');
			$field->field_namekey = $this->orderFieldName;
			$field->field_type = 'text';
			$field->field_published = 1;
			$field->field_ordering = 99;
			$field->field_required = 0;
			$field->field_frontend = 1;
			$field->field_backend = 1;
			$field->field_core = 0;
			$field->field_access = 'all';
			$field->field_display = ';front_order=1;invoice=0;mail_order_notif=1;';
			$db->insertObject('#__hikashop_field', $field);
		}
		
		$columns = $db->getTableColumns('#__hikashop_order');
		if (!isset($columns[$this->orderFieldName])) {
			$db->setQuery('ALTER TABLE ' . $db->quoteName('#__hikashop_order') . ' ADD ' . $db->quoteName($this->orderFieldName) . ' TEXT NULL');
			$db->execute();
		}
		
		$ensured = true;
	}
}
