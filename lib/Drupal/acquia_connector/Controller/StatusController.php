<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\Form\Controller\StatusController.
 */

namespace Drupal\acquia_connector\Controller;

use Drupal\Core\Access\AccessInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Utility\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class StatusController.
 */
class StatusController extends ControllerBase {

  /**
   *
   */
  public function json() {
    // We don't want this page cached.
    drupal_page_is_cacheable(FALSE);

    $performance_config = $this->config('system.performance');

    $data = array(
      'version' => '1.0',
      'data' => array(
        'maintenance_mode' => (bool) $this->state()->get('system.maintenance_mode', FALSE),
        'cache' => $performance_config->get('cache.page.use_internal'),
        'block_cache' => FALSE,
      ),
    );

    return new JsonResponse($data);
  }

  /**
   *
   */
  public function access(Request $request) {
    $query = $request->query->all();
    $connector_config = $this->config('acquia_connector.settings');

    // If we don't have all the query params, leave now.
    if (!isset($query['key'], $query['nonce'])) {
      return AccessInterface::KILL;
    }

    $sub_data = $connector_config->get('subscription_data');
    $sub_uuid = $this->getIdFromSub($sub_data);

    if (!empty($sub_uuid)) {
      $expected_hash = hash('sha1', "{$sub_uuid}:{$query['nonce']}");

      // If the generated hash matches the hash from $_GET['key'], we're good.
      if ($query['key'] === $expected_hash) {
        return AccessInterface::ALLOW;
      }
    }

    // Log the request if validation failed and debug is enabled.
    if ($connector_config->get('debug')) {
      $info = array(
        'sub_data' => $sub_data,
        'sub_uuid_from_data' => $sub_uuid,
        'expected_hash' => $expected_hash,
        'get' => $query,
        'server' => $request->server->all(),
        'request' => $request->request->all(),
      );

      watchdog('acquia_agent', 'Site status request: @data', array('@data' => var_export($info, TRUE)));
    }

    return AccessInterface::KILL;
  }

  /**
   * Gets the subscription UUID from subscription data.
   *
   * @param array $sub_data
   *   An array of subscription data
   *   @see acquia_agent_settings('acquia_subscription_data')
   *
   * @return string
   *   The UUID taken from the subscription data.
   */
  protected function getIdFromSub($sub_data) {
    if (!empty($sub_data['uuid'])) {
      return $sub_data['uuid'];
    }

    // Otherwise, get this form the sub url.
    $url = Url::parse($sub_data['href']);
    $parts = explode('/', $url['path']);
    // Remove '/dashboard'.
    array_pop($parts);
    return end($parts);
  }

}
