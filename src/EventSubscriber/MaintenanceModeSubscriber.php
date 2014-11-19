<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\EventSubscriber\MaintenanceModeSubscriber.
 */

namespace Drupal\acquia_connector\EventSubscriber;

use Drupal\acquia_connector\Controller;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Maintenance mode subscriber show connector status even when in maintenance.
 */
class MaintenanceModeSubscriber implements EventSubscriberInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The state factory.
   *
   * @var \Drupal\Core\KeyValueStore\StateInterface
   */
  protected $state;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  public function __construct(ConfigFactoryInterface $config_factory, StateInterface $state, CacheBackendInterface $cache) {
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->cache = $cache;
  }

  /**
   * Forces a site out of maintenance mode if we are on the canary URL.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The event to process.
   */
  public function onKernelRequestMaintenance(GetResponseEvent $event) {
    $request = $event->getRequest();
    $site_status = $request->attributes->get('_maintenance');
    $path = $request->attributes->get('_system_path');

    if (($site_status == MENU_SITE_OFFLINE) && ($path == '/system/acquia-connector-status')) {
      $request->attributes->set('_maintenance', MENU_SITE_ONLINE);
    }
  }

  /**
   * @param GetResponseEvent $event
   */
  public function onKernelRequest(GetResponseEvent $event) {
    // Store server information for SPI in case data is being sent from PHP CLI.
    if (PHP_SAPI == 'cli') {
      return;
    }

    $config = $this->configFactory->get('acquia_connector.settings');
    // Get the last time we processed data.
    $last = $this->state->get('acquia_connector.boot_last', 0);
    // 60 minute interval for storing the global variable.
    $interval = $config->get('cron_interval', 60);
    // Determine if the required interval has passed.
    $now = REQUEST_TIME;

    if ((($now - $last) > ($interval * 60))) {
      $platform = Controller\SpiController::getPlatform();

      // acquia_spi_data_store_set() replacement.
      $expire = REQUEST_TIME + (60*60*24);
      $this->cache->set('acquia.spi.platform', $platform, $expire);
      $this->state->set('acquia_connector.boot_last', $now);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = array('onKernelRequestMaintenance', 200);
    $events[KernelEvents::REQUEST][] = array('onKernelRequest', 20);
    return $events;
  }

}

