<?php
/**
 * @package     HikaShop InPost Paczkomaty Shipping Plugin
 * @version     4.2.23
 * @copyright   (C) 2026
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 *
 * Skrypt instalacyjny: porządkuje serwer aktualizacji wtyczki.
 */

\defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * HikaShop przy każdej swojej instalacji i aktualizacji szuka „własnego” serwera
 * aktualizacji zapytaniem: adres zawiera słowo „hikashop” i typ to „extension”.
 * Pierwszemu trafieniu nadpisuje nazwę i adres swoimi i dopina do niego komponent
 * HikaShop. W sklepach, w których własny serwer HikaShopa ma inny typ (starsze
 * instalacje), pierwszym trafieniem był serwer tej wtyczki, bo jej repozytorium ma
 * „hikashop” w nazwie. Joomla przestawała wtedy pytać o aktualizacje wtyczki i nowe
 * wersje nie pojawiały się w „Aktualizacje → Rozszerzenia”.
 *
 * Od 4.2.23 adres serwera aktualizacji w manifeście nie zawiera tego słowa dosłownie,
 * więc HikaShop go nie znajdzie. Ten skrypt porządkuje stan zastany: jeśli wtyczka jest
 * przypisana do serwera, do którego HikaShop dopiął już swój komponent, odpina ją od
 * niego. Joomla zakłada wtedy wtyczce nowy, własny serwer, a przejęty wiersz zostaje
 * HikaShopowi. Dzięki temu HikaShop nadal znajduje „swój” wiersz i nie sięga po serwer
 * aktualizacji kolejnej wtyczki.
 */
class PlgHikashopshippingInpost_hikaInstallerScript
{
    /**
     * Wywoływane przed instalacją i aktualizacją, czyli zanim Joomla zarejestruje
     * serwer aktualizacji z manifestu.
     *
     * @param   string  $type    install, update, discover_install albo uninstall
     * @param   object  $parent  Adapter instalatora
     *
     * @return  boolean
     */
    public function preflight($type, $parent)
    {
        if ($type !== 'uninstall') {
            $this->detachFromSharedUpdateSite();
        }

        return true;
    }

    /**
     * Odpina wtyczkę od serwera aktualizacji współdzielonego z komponentem HikaShop.
     *
     * Usuwa wyłącznie powiązanie wtyczki z takim serwerem. Samego serwera ani powiązania
     * HikaShopa nie rusza.
     *
     * @return  integer  Liczba usuniętych powiązań
     */
    public function detachFromSharedUpdateSite()
    {
        $usuniete = 0;

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $query = $db->getQuery(true)
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('hikashopshipping'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('inpost_hika'));
            $db->setQuery($query);
            $wtyczka = (int) $db->loadResult();

            if ($wtyczka <= 0) {
                return 0;
            }

            $query = $db->getQuery(true)
                ->select($db->quoteName('update_site_id'))
                ->from($db->quoteName('#__update_sites_extensions'))
                ->where($db->quoteName('extension_id') . ' = ' . $wtyczka);
            $db->setQuery($query);

            foreach (array_map('intval', (array) $db->loadColumn()) as $serwer) {
                $query = $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__update_sites_extensions', 'p'))
                    ->join('INNER', $db->quoteName('#__extensions', 'e') . ' ON ' . $db->quoteName('e.extension_id') . ' = ' . $db->quoteName('p.extension_id'))
                    ->where($db->quoteName('p.update_site_id') . ' = ' . $serwer)
                    ->where($db->quoteName('e.type') . ' = ' . $db->quote('component'))
                    ->where($db->quoteName('e.element') . ' = ' . $db->quote('com_hikashop'));
                $db->setQuery($query);

                if ((int) $db->loadResult() === 0) {
                    continue;
                }

                $query = $db->getQuery(true)
                    ->delete($db->quoteName('#__update_sites_extensions'))
                    ->where($db->quoteName('update_site_id') . ' = ' . $serwer)
                    ->where($db->quoteName('extension_id') . ' = ' . $wtyczka);
                $db->setQuery($query);
                $db->execute();

                $usuniete++;
            }
        } catch (\Throwable $e) {
            // Porządkowanie serwera aktualizacji nie może zatrzymać instalacji wtyczki.
        }

        return $usuniete;
    }
}
