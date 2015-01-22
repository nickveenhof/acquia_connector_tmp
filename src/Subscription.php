<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\Subscription.
 */

namespace Drupal\acquia_connector;

class Subscription {

  /**
   * XML-RPC errors defined by the Acquia Network.
   */
  const NOT_FOUND = 1000;
  const KEY_MISMATCH = 1100;
  const EXPIRED = 1200;
  const REPLAY_ATTACK = 1300;
  const KEY_NOT_FOUND = 1400;
  const MESSAGE_FUTURE = 1500;
  const MESSAGE_EXPIRED = 1600;
  const MESSAGE_INVALID = 1700;
  const VALIDATION_ERROR = 1800;
  const PROVISION_ERROR = 9000;

  /**
   * Subscription message lifetime defined by the Acquia Network.
   */
  const MESSAGE_LIFETIME = 900; // 15 * 60.

  /**
   * Get subscription status from the Acquia Network, and store the result.
   *
   * This check also sends a heartbeat to the Acquia Network unless
   * $params['no_heartbeat'] == 1.
   *
   * @return FALSE, integer (error number), or subscription data.
   * D7: acquia_agent_check_subscription
   */
  public function update($params = array()) {
    $config = \Drupal::config('acquia_connector.settings');
    $current_subscription = $config->get('subscription_data');
    $subscription = FALSE;

    if (!$this->hasCredentials()) {
      // If there is not an identifier or key, delete any old subscription data.
      $config->clear('subscription_data')->save();
    }
    else {
      // Get our subscription data
      try {
        $subscription = \Drupal::service('acquia_connector.client')->getSubscription($config->get('identifier'), $config->get('key'), $params);
      }
      catch (RequestException $e) {
        switch ($e->getCode()) {
          case static::NOT_FOUND:
          case static::EXPIRED:
            // Fall through since these values are stored and used by
            // acquia_search_acquia_subscription_status()
            break;
          default:
            // Likely server error (503) or connection timeout (-110) so leave
            // current subscription in place. _acquia_agent_request() logged an
            // error message.
            $subscription = $current_subscription;
        }
      }
      if ($subscription) {
        $config->set('subscription_data', $subscription)->save();
        // @todo hook signature has changed, doesn't pass $active variable anymore.
        \Drupal::moduleHandler()->invokeAll('acquia_subscription_status', [$subscription]);
      }
    }

    return $subscription;
  }

  /**
   * Helper function to check if an identifer and key exist.
   * d7: acquia_agent_has_credentials().
   */
  public function hasCredentials() {
    $config = \Drupal::config('acquia_connector.settings');
    return $config->get('identifier') && $config->get('key');
  }

  /**
   * Helper function to check if the site has an active subscription.
   */
  public function isActive() {
    $active = FALSE;
    // Subscription cannot be active if we have no credentials.
    if($this->hasCredentials()){
      $config = \Drupal::config('acquia_connector.settings');
      $subscription = $config->get('subscription_data');

      // Make sure we have data at least once per day.
      if (isset($subscription['timestamp']) && (time() - $subscription['timestamp'] > 60*60*24)) {
        //'no_heartbeat' => 1
        try {
          $subscription = \Drupal::service('acquia_connector.client')
            ->getSubscription($config->get('identifier'), $config->get('key'), array());
        }
        catch (RequestException $e) {}
      }
      $active = !empty($subscription['active']);
    }
    return $active;
  }
}
