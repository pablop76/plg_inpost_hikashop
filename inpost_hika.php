<?php
/**
 * @package     HikaShop InPost Paczkomaty Shipping Plugin
 * @version     4.0.0
 * @copyright   (C) 2026
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 * 
 * Legacy entry point for Joomla 4/5/6 compatibility
 */

\defined('_JEXEC') or die('Restricted access');

// Load the namespaced class
use Pablop76\Plugin\HikashopShipping\InpostHika\Extension\InpostHika;

// For HikaShop compatibility - create legacy class alias
if (!class_exists('plgHikashopshippingInpost_hika')) {
    class_alias(InpostHika::class, 'plgHikashopshippingInpost_hika');
}
