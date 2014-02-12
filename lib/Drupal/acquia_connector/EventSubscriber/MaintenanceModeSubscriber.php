<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\EventSubscriber\MaintenanceModeSubscriber.
 */

namespace Drupal\acquia_connector\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Maintenance mode subscriber show connector status even when in maintenance.
 */
class MaintenanceModeSubscriber implements EventSubscriberInterface {

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
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = array('onKernelRequestMaintenance', 200);
    return $events;
  }

}

