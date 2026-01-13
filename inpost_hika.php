<?php
/**
 * @package     HikaShop InPost Paczkomaty Shipping Plugin
 * @version     2.0.0
 * @copyright   (C) 2026
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or die('Restricted access');

class plgHikashopshippingInpost_hika extends hikashopShippingPlugin {
	// Produkcja
	const GEO_WIDGET_JS = 'https://geowidget.easypack24.net/js/sdk-for-javascript.js';
	const GEO_WIDGET_CSS = 'https://geowidget.easypack24.net/css/easypack.css';
	// Sandbox (testowe)
	const GEO_WIDGET_JS_SANDBOX = 'https://sandbox-easy-geowidget-sdk.easypack24.net/js/sdk-for-javascript.js';
	const GEO_WIDGET_CSS_SANDBOX = 'https://sandbox-easy-geowidget-sdk.easypack24.net/css/easypack.css';

	var $multiple = true;
	var $name = 'inpost_hika';
	var $doc_form = 'inpost_hika';

	protected $orderFieldName = 'inpost_locker';

	// Definicja pól konfiguracyjnych dla HikaShop
	var $pluginConfig = array(
		'api_mode' => array('API_MODE', 'list', array(
			'production' => 'Produkcja',
			'sandbox' => 'Sandbox (testowe)'
		)),
		'map_type' => array('MAP_TYPE', 'list', array(
			'osm' => 'OpenStreetMap',
			'google' => 'Google Maps'
		)),
		'google_api_key' => array('GOOGLE_API_KEY', 'input'),
		'default_lat' => array('DEFAULT_LAT', 'input'),
		'default_lng' => array('DEFAULT_LNG', 'input'),
		'default_zoom' => array('DEFAULT_ZOOM', 'input'),
		'show_parcel_lockers' => array('SHOW_PARCEL_LOCKERS', 'boolean', '1'),
		'show_pops' => array('SHOW_POPS', 'boolean', '0'),
		'debug' => array('INPOST_DEBUG', 'boolean', '0')
	);

	public function __construct(&$subject, $config) {
		parent::__construct($subject, $config);
		$this->loadLanguage();
		
		$app = JFactory::getApplication();
		$input = $app->input;
		
		// Obsługa AJAX zapisu wyboru paczkomatu (frontend)
		$lockerSave = $input->post->getString('inpost_locker_save', '');
		if($lockerSave !== '') {
			$app->setUserState('hikashop.inpost_locker', $lockerSave);
		}
	}

	/**
	 * Wyświetla informację o paczkomacie po liście produktów w zamówieniu
	 * Event z Display API HikaShop
	 */
	public function onAfterOrderProductsListingDisplay(&$order, $mail) {
		// Tylko w panelu admina
		$app = JFactory::getApplication();
		if(!$app->isClient('administrator')) {
			return;
		}
		
		// Sprawdź czy zamówienie ma metodę InPost
		if(empty($order->order_shipping_method) || $order->order_shipping_method !== $this->name) {
			return;
		}
		
		// Pobierz paczkomat z bazy (bo może nie być w obiekcie $order)
		$locker = '';
		if(!empty($order->inpost_locker)) {
			$locker = $order->inpost_locker;
		} else {
			// Pobierz z bazy danych
			$db = JFactory::getDbo();
			$query = $db->getQuery(true)
				->select($db->quoteName('inpost_locker'))
				->from($db->quoteName('#__hikashop_order'))
				->where($db->quoteName('order_id') . ' = ' . (int)$order->order_id);
			$db->setQuery($query);
			$locker = $db->loadResult();
		}
		
		if(empty($locker)) {
			return;
		}
		
		$label = JText::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_FIELD_LABEL');
		
		// Wyświetl HTML bezpośrednio (echo) - to jest wywoływane w trakcie renderowania
		echo '<div id="inpost_locker_info" style="background:#fff3cd; border:2px solid #ffc107; padding:15px; margin:15px 0; border-radius:8px; font-size:14px;">';
		echo '<strong style="color:#856404; font-size:16px;">📦 ' . htmlspecialchars($label) . ':</strong><br>';
		echo '<span style="color:#333; font-size:15px; font-weight:bold;">' . htmlspecialchars($locker) . '</span>';
		echo '</div>';
	}

	public function onShippingDisplay(&$order, &$dbrates, &$usable_rates, &$messages) {
		$this->ensureOrderFieldExists();
		
		$app = JFactory::getApplication();
		$selectedLocker = $app->getUserState('hikashop.inpost_locker', '');
		
		$shippingDisplay = parent::onShippingDisplay($order, $dbrates, $usable_rates, $messages);
		if(empty($usable_rates))
			return $shippingDisplay;

		foreach($usable_rates as $key => $rate) {
			if($rate->shipping_type !== $this->name)
				continue;
			$this->decorateRateWithWidget($rate, $selectedLocker);
			$usable_rates[$key] = $rate;
		}

		return $shippingDisplay;
	}

	public function getShippingDefaultValues(&$element) {
		$element->shipping_name = JText::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_NAME');
		$element->shipping_description = '';
		$element->shipping_type = $this->name;
		$element->shipping_params = new stdClass();
		$element->shipping_params->api_mode = 'production';
		$element->shipping_params->map_type = 'osm';
		$element->shipping_params->google_api_key = '';
		$element->shipping_params->default_lat = '52.2297';
		$element->shipping_params->default_lng = '21.0122';
		$element->shipping_params->default_zoom = ''; // pusty = automatyczny (OSM:13, Google:6)
		$element->shipping_params->show_parcel_lockers = 1;
		$element->shipping_params->show_pops = 0;
		$element->shipping_params->debug = 0;
	}

	/**
	 * Logowanie debug do pliku
	 */
	protected function debug($message, $data = null, $shippingParams = null) {
		$debugEnabled = false;
		if($shippingParams && isset($shippingParams->debug)) {
			$debugEnabled = (bool)$shippingParams->debug;
		}
		if(!$debugEnabled) return;
		
		$logFile = JPATH_ROOT . '/logs/inpost_hika_debug.log';
		$timestamp = date('Y-m-d H:i:s');
		$logMessage = "[{$timestamp}] {$message}";
		if($data !== null) {
			$logMessage .= " | Data: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
		}
		$logMessage .= "\n";
		
		file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
	}

	public function shippingMethods(&$main) {
		$methods = array();
		$methods[$main->shipping_id] = JText::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_NAME');
		return $methods;
	}

	public function onShippingConfigurationSave(&$element) {
		$this->ensureOrderFieldExists();
		parent::onShippingConfigurationSave($element);
	}

	public function onAfterOrderConfirm(&$order, &$methods, $method_id) {
		parent::onAfterOrderConfirm($order, $methods, $method_id);
		
		// Pobierz shipping_params dla debug
		$shippingParamsForDebug = null;
		if(!empty($methods) && isset($methods[$method_id])) {
			$shippingParamsForDebug = $methods[$method_id]->shipping_params ?? null;
		}
		
		$app = JFactory::getApplication();
		$selected = $app->getUserState('hikashop.inpost_locker', '');
		
		$this->debug('onAfterOrderConfirm', [
			'order_id' => $order->order_id,
			'method_id' => $method_id,
			'selected_locker' => $selected
		], $shippingParamsForDebug);
		
		if($selected !== '') {
			$db = JFactory::getDbo();
			
			// Zapisz paczkomat w kolumnie inpost_locker
			$query = $db->getQuery(true)
				->update($db->quoteName('#__hikashop_order'))
				->set($db->quoteName($this->orderFieldName) . ' = ' . $db->quote($selected))
				->where($db->quoteName('order_id') . ' = ' . (int)$order->order_id);
			$db->setQuery($query);
			$db->execute();
			
			$this->debug('Saved locker to DB', ['locker' => $selected, 'order_id' => $order->order_id], $shippingParamsForDebug);
			
			// Dodaj informację o paczkomacie do shipping_params (widoczne w panelu admina)
			$shippingParams = new stdClass();
			if(!empty($order->order_shipping_params)) {
				if(is_string($order->order_shipping_params)) {
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

	public function onBeforeOrderCreate(&$order, &$do) {
		// Sprawdź czy wybrano metodę InPost
		if(empty($order->order_shipping_id)) return;
		
		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
			->select('shipping_type')
			->from($db->quoteName('#__hikashop_shipping'))
			->where($db->quoteName('shipping_id') . ' = ' . (int)$order->order_shipping_id);
		$db->setQuery($query);
		$shippingType = $db->loadResult();
		
		if($shippingType !== $this->name) return;
		
		// Sprawdź czy punkt został wybrany
		$app = JFactory::getApplication();
		$selectedLocker = $app->getUserState('hikashop.inpost_locker', '');
		
		// Walidacja - punkt musi być wybrany
		if($selectedLocker === '') {
			$app->enqueueMessage('Proszę wybrać paczkomat lub punkt odbioru InPost', 'error');
			$do = false;
			return;
		}
	}

	protected function decorateRateWithWidget(&$rate, $selectedLocker) {
		$rate->custom_html_no_btn = true;
		$rate->custom_html = '';
		
		$this->loadGeoWidgetAssets();
		
		$shippingId = (int)$rate->shipping_id;
		$warehouseId = isset($rate->shipping_warehouse_id) ? (int)$rate->shipping_warehouse_id : 0;
		$widgetId = 'inpost_widget_' . $shippingId;
		
		$currentValue = $selectedLocker !== '' ? $selectedLocker : JText::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_NOT_SELECTED');
		$buttonLabel = $selectedLocker !== '' ? JText::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_CHANGE') : JText::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SELECT');
		
		$inputName = 'checkout[shipping][' . $warehouseId . '][custom][' . $shippingId . '][' . $this->orderFieldName . ']';
		
		$rate->custom_html .= '<div class="inpost-hika-widget" id="' . $widgetId . '">';
		$rate->custom_html .= '<div class="inpost-hika-label">' . JText::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_SELECTED_LABEL') . '</div>';
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

	protected function addWidgetScript($widgetId, $shippingId, $showLockers = 1, $showPops = 0, $mapType = 'osm', $googleApiKey = '', $defaultLat = 52.2297, $defaultLng = 21.0122, $defaultZoom = 10, $apiMode = 'production') {
		$doc = JFactory::getDocument();
		$changeLabel = addslashes(JText::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_CHANGE'));
		$loadingMsg = addslashes(JText::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_LOADING'));
		
		// Buduj listę typów punktów na podstawie konfiguracji
		$types = array();
		if($showLockers) $types[] = 'parcel_locker';
		if($showPops) $types[] = 'pop';
		if(empty($types)) $types[] = 'parcel_locker'; // domyślnie paczkomaty
		$typesJs = json_encode($types);
		
		// Sanityzacja parametrów mapy
		$mapType = in_array($mapType, array('osm', 'google')) ? $mapType : 'osm';
		$searchType = $mapType; // searchType musi być zgodny z mapType
		$googleApiKeyJs = addslashes($googleApiKey);
		
		// Wybierz URL SDK w zależności od trybu API
		$sdkJs = ($apiMode === 'sandbox') ? self::GEO_WIDGET_JS_SANDBOX : self::GEO_WIDGET_JS;
		$sdkCss = ($apiMode === 'sandbox') ? self::GEO_WIDGET_CSS_SANDBOX : self::GEO_WIDGET_CSS;
		
		// Domyślny zoom zależny od typu mapy jeśli nie ustawiony
		if(empty($defaultZoom) || $defaultZoom == 0) {
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
	if(!document.querySelector('link[href*=\"geowidget\"]') && !document.querySelector('link[href*=\"sandbox-easy-geowidget\"]')){
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
			// SDK się ładuje - dodaj callback do kolejki
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
			
			// Wywołaj wszystkie callbacki
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
		
		// Dodaj klucz Google API jeśli używamy Google Maps
		if(mapType === 'google' && googleApiKey) {
			config.apiKey = googleApiKey;
		}
		
		easyPack.init(config);
	}
	
	function openMap(){
		// Pokaż że się ładuje
		var btn = document.getElementById(widgetId + '_btn');
		if(btn) btn.textContent = '{$loadingMsg}';
		
		loadSDK(function(){
			// Przywróć tekst przycisku
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
				
				// Zapisz wybór przez AJAX
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
	
	// HikaShop checkout event - rejestruj tylko raz globalnie
	if(window.Oby && !window._inpostValidationRegistered){
		window._inpostValidationRegistered = true;
		window._inpostShowingAlert = false;
		
		window.Oby.registerAjax(['checkoutFormSubmit'], function(params){
			if(window._inpostShowingAlert) return false;
			
			// Sprawdź wszystkie widgety InPost na stronie
			var widgets = document.querySelectorAll('.inpost-hika-widget');
			var valid = true;
			
			widgets.forEach(function(widget){
				var input = widget.querySelector('input[type=\"hidden\"]');
				
				// Sprawdź czy widget jest w kontenerze z zaznaczonym radio
				var checkedRadio = document.querySelector('input[name*=\"shipping\"][type=\"radio\"]:checked');
				if(!checkedRadio) return;
				
				var radioContainer = checkedRadio.closest('.hikashop_shipping_method, .hikashop_shipping_group, [data-shipping-id], tr, .shipping-method');
				if(radioContainer && radioContainer.contains(widget)){
					// InPost jest wybrany
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
	
	// Preload SDK w tle
	setTimeout(function(){ loadSDK(function(){}); }, 100);
})();
";
		$doc->addScriptDeclaration($script);
	}

	protected function loadGeoWidgetAssets() {
		// SDK jest teraz ładowane dynamicznie przy pierwszym kliknięciu przycisku
		// To pozwala uniknąć konfliktów z innymi modułami Leaflet
	}

	protected function ensureOrderFieldExists() {
		static $ensured = false;
		if($ensured) return;
		
		$db = JFactory::getDbo();
		
		$query = $db->getQuery(true)
			->select('field_id')
			->from($db->quoteName('#__hikashop_field'))
			->where($db->quoteName('field_namekey') . ' = ' . $db->quote($this->orderFieldName));
		$db->setQuery($query);
		
		if(!$db->loadResult()) {
			$field = new stdClass();
			$field->field_table = 'order';
			$field->field_realname = JText::_('PLG_HIKASHOPSHIPPING_INPOST_HIKA_FIELD_LABEL');
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
		if(!isset($columns[$this->orderFieldName])) {
			$db->setQuery('ALTER TABLE ' . $db->quoteName('#__hikashop_order') . ' ADD ' . $db->quoteName($this->orderFieldName) . ' TEXT NULL');
			$db->execute();
		}
		
		$ensured = true;
	}
}
