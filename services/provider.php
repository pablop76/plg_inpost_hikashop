<?php
/**
 * @package     HikaShop InPost Paczkomaty Shipping Plugin
 * @version     4.2.3
 * @copyright   (C) 2026
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Pablop76\Plugin\HikashopShipping\InpostHika\Extension\InpostHika;

return new class implements ServiceProviderInterface
{
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     */
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                // HikaShop definiuje hikashopShippingPlugin leniwie: dopiero pierwsze
                // załadowanie jego helper.php rejestruje spl_autoload_register dla tej
                // klasy. W kontekstach, gdzie HikaShop jeszcze nie "wystartował" w tym
                // requeście (np. podczas aktualizacji wtyczki w Menedżerze Rozszerzeń),
                // ta klasa bazowa nie jest jeszcze dostępna - trzeba ją dociągnąć ręcznie,
                // zanim autoloader Joomli spróbuje załadować InpostHika.php (który ją
                // rozszerza), inaczej PHP zgłosi "Class hikashopShippingPlugin not found".
                if (!class_exists('hikashopShippingPlugin', false)) {
                    $helperPath = JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php';
                    if (is_file($helperPath)) {
                        require_once $helperPath;
                    }
                }

                $dispatcher = $container->get(DispatcherInterface::class);
                $config = (array) PluginHelper::getPlugin('hikashopshipping', 'inpost_hika');

                $plugin = new InpostHika(
                    $dispatcher,
                    $config
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
