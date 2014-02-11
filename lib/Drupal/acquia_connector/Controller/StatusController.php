<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\Form\Controller\StatusController.
 */

namespace Drupal\acquia_connector\Controller;

use Drupal\Core\Access\AccessInterface;
use Drupal\Core\Controller\ControllerBase;
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

    $data = array(
      'version' => '1.0',
      'data' => array(
        'maintenance_mode' => (bool) $this->state()->get('system.maintenance_mode', FALSE),
        'cache' => FALSE,
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
    $config = $this->config('acquia_connector.settings');

    // If we don't have all the query params, leave now.
    if (!isset($query['key'], $query['nonce'])) {
      return AccessInterface::KILL;
    }

    $sub_data = $config->get('subscription_data');
    $sub_uuid = _acquia_agent_get_id_from_sub($sub_data);

    if (!empty($sub_uuid)) {
      $expected_hash = hash('sha1', "{$sub_uuid}:{$query['nonce']}");

      // If the generated hash matches the hash from $_GET['key'], we're good.
      if ($query['key'] === $expected_hash) {
        return AccessInterface::ALLOW;
      }
    }

    // Log the request if validation failed and debug is enabled.
    if ($config->get('debug')) {
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

}
