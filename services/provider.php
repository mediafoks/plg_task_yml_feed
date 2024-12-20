<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Task.YmlFeed
 *
 * @copyright   (C) 2024 Sergey Kuznetsov. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\Task\YmlFeed\Extension\YmlFeed;

return new class() implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   2.0.3
     */
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new YmlFeed(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('task', 'ymlfeed'),
                    JPATH_ROOT . '/media/',
                    'https://' . Uri::getInstance()->getHost()
                );
                $plugin->setApplication(Factory::getApplication());
                return $plugin;
            }
        );
    }
};
