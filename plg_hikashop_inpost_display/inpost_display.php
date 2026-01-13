<?php
/**
 * @package     HikaShop InPost Display Plugin
 * @version     2.0.0
 * @copyright   (C) 2026
 * @license     GNU/GPLv3
 * 
 * Legacy entry point for Joomla 4/5/6 compatibility
 */

\defined('_JEXEC') or die('Restricted access');

use Pablop76\Plugin\Hikashop\InpostDisplay\Extension\InpostDisplay;

// For HikaShop compatibility - create legacy class alias
if (!class_exists('plgHikashopInpost_display')) {
    class_alias(InpostDisplay::class, 'plgHikashopInpost_display');
}
