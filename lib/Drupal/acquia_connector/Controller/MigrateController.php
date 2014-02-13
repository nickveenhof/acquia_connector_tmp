<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\Controller\MigrateController.
 */

namespace Drupal\acquia_connector\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class MigrateController.
 */
class MigrateController extends ControllerBase {

  /**
   *
   */
  public function migratePage(Request $request) {
    $config = $this->config('acquia_connector.settings');
    $identifier = $config->get('identifier');
    $key = $config->get('key');

    if (!empty($identifier) && !empty($key)) {
      if (acquia_agent_valid_credentials($identifier, $key, $config->get('network_address'))) {
        $form_builder = $this->formBuilder()->getForm('');
        $form_builder->setRequest($request);

        return drupal_get_form('acquia_agent_migrate_form');
      }
      else {
        $error = acquia_agent_connection_error_message();
      }
    }
    else {
      $error = 'Missing Acquia Network credentials. Please enter your Acquia Network Identifier and Key.';
    }

    // If there was an error.
    if (isset($error)) {
      drupal_set_message($this->t('There was an error in communicating with Acquia.com. @err', array('@err' => $error)), 'error');
    }

    $this->redirect('acquia_connector.settings');
  }

  /**
   * Menu callback for checking client upload.
   */
  public function migrateCheck() {
    $return = array('compatible' => TRUE);
    $env = _acquia_migrate_check_env();

    if (empty($env) || $env['error'] !== FALSE) {
      $return['compatible'] = FALSE;
      $return['message'] = $env['error'];
    }

    return new JsonResponse($return);
  }

}
