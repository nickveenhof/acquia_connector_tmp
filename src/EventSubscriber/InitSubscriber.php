<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\EventSubscriber\MaintenanceModeSubscriber.
 */

namespace Drupal\acquia_connector\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Path;
use Drupal\acquia_connector\Subscription;
use Drupal\acquia_connector\Controller;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Init (i.e., hook_init()) subscriber that displays a message asking you to join
 * the Acquia network if you haven't already.
 */
class InitSubscriber implements EventSubscriberInterface {

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
    if (($now - $last) > ($interval * 60)) {
      $platform = Controller\SpiController::getPlatform();

      // acquia_spi_data_store_set() replacement.
      $expire = REQUEST_TIME + (60*60*24);
      $this->cache->set('acquia.spi.platform', $platform, $expire);
      $this->state->set('acquia_connector.boot_last', $now);
    }

    $config = $this->configFactory->get('acquia_connector.settings');
    if ($config->get('hide_signup_messages')) {
      return;
    }

    // Check that we're not on one of our own config pages, all of which are prefixed
    // with admin/config/system/acquia-connector.
    $current_path = \Drupal::Request()->attributes->get('_system_path');
    if (\Drupal::service('path.matcher')->matchPath($current_path,'admin/config/system/acquia-connector/*')) {
      return;
    }

    // @todo: Check that there's no form submission in progress.

    // Check that the user has 'administer site configuration' permission.
    if (!\Drupal::currentUser()->hasPermission('administer site configuration')) {
      return;
    }

    $credentials = new Subscription();
    // Check that there are no Acquia credentials currently set up.
    if ($credentials->hasCredentials()) {
      return;
    }

    // @todo: Check that we're not serving a file.

    // ...display annoying signup message.
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = array('onKernelRequest');
    return $events;
  }

}

