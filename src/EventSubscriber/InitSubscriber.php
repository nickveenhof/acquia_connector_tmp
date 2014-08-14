<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\EventSubscriber\MaintenanceModeSubscriber.
 */

namespace Drupal\acquia_connector\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\PathMatcher;

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
    if ($config->get('hide_signup_messages')) {
      return;
    }

    // Check that we're not on one of our own config pages, all of which are prefixed
    // with admin/config/system/acquia-connector.
    if ((new PathMatcher($this->configFactory))->matchPath(current_path(), 'admin/config/system/acquia-connector/*')) {
      return;
    }

    // @todo: Check that there's no form submission in progress.

    // Check that the user has 'administer site configuration' permission.
    if (!\Drupal::currentUser()->hasPermission('administer site configuration')) {
      return;
    }

    // Check that there are no Acquia credentials currently set up.
    if (acquia_agent_has_credentials()) {
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

