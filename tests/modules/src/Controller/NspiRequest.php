<?php
namespace Drupal\acquia_connector_test\Controller;

use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;


class NspiRequest implements EventSubscriberInterface {

  public function __construct() {}

  /**
   * @param GetResponseEvent $event
   */
  public function onKernelRequest(GetResponseEvent $event) {
    $system_path = \Drupal::Request()->attributes->get('_system_path');
    $patch = explode("/", $system_path);
    if((isset($patch['0']) && $patch['0'] == 'agent-api') || (isset($patch['0']) && $patch['0'] == 'spi-api') || (isset($patch['0']) && $patch['0'] == 'spi_def')|| (isset($patch['0']) && $patch['0'] == 'agent-migrate-api')){
      $requests =  \Drupal::state()->get('acquia_connector_test_request_count', 0);
      $requests++;
      \Drupal::state()->set('acquia_connector_test_request_count', $requests);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = array('onKernelRequest');
    return $events;
  }

}

