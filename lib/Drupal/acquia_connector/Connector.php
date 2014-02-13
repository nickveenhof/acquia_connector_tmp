<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\Connector.
 */

namespace Drupal\acquia_connector;

class Connector {

  public static function getSubscription() {
    return new Subscription();
  }

}
