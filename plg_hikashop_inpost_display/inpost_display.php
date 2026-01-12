<?php
/**
 * @package     HikaShop InPost Display Plugin
 * @version     1.0.0
 * @author      Custom
 * @license     GNU/GPLv3
 */
defined('_JEXEC') or die('Restricted access');

class plgHikashopInpost_display extends JPlugin {
	
	/**
	 * Dodaje informację o paczkomacie do listy zamówień
	 */
	public function onAfterOrderListing(&$rows, &$extrafields, &$pageInfo) {
		if(empty($rows)) return;
		
		$db = JFactory::getDbo();
		$orderIds = array();
		
		foreach($rows as $row) {
			if(!empty($row->order_shipping_method) && $row->order_shipping_method === 'inpost_hika') {
				$orderIds[] = (int)$row->order_id;
			}
		}
		
		if(empty($orderIds)) return;
		
		// Pobierz paczkomaty dla zamówień
		$query = $db->getQuery(true)
			->select($db->quoteName(array('order_id', 'inpost_locker')))
			->from($db->quoteName('#__hikashop_order'))
			->where($db->quoteName('order_id') . ' IN (' . implode(',', $orderIds) . ')')
			->where($db->quoteName('inpost_locker') . ' IS NOT NULL')
			->where($db->quoteName('inpost_locker') . ' != ' . $db->quote(''));
		$db->setQuery($query);
		$lockers = $db->loadObjectList('order_id');
		
		// Dodaj extrafield dla paczkomatu
		if(!empty($lockers)) {
			$extrafields['inpost_locker'] = (object)array(
				'name' => 'Paczkomat',
				'value' => 'inpost_locker_display'
			);
			
			foreach($rows as &$row) {
				if(isset($lockers[$row->order_id])) {
					$row->inpost_locker_display = '<span style="color:#856404;font-size:0.85em;">📦 ' . htmlspecialchars($lockers[$row->order_id]->inpost_locker) . '</span>';
				} else {
					$row->inpost_locker_display = '';
				}
			}
		}
	}
	
	/**
	 * Modyfikuje zapytanie SQL żeby pobrać kolumnę inpost_locker
	 */
	public function onBeforeOrderListing($paramBase, &$extrafilters, &$pageInfo, &$filters, &$tables, &$searchMap, &$select) {
		$select .= ', b.inpost_locker';
	}
}
