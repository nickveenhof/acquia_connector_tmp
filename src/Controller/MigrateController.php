<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\Controller\MigrateController.
 */

namespace Drupal\acquia_connector\Controller;

use Drupal\acquia_connector\Migration;
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
      if (\Drupal::service('acquia_connector.client')->validateCredentials($identifier, $key)) {
        $form_builder = $this->formBuilder()->getForm('');
        $form_builder->setRequest($request);

        return $form_builder->getForm('\Drupal\acquia_connector\Form\MigrateForm');
      }
      else {
        $error = acquia_agent_connection_error_message();
      }
    }
    else {
      $error = $this->t('Missing Acquia Network credentials. Please enter your Acquia Network Identifier and Key.');
    }

    // If there was an error.
    if (!empty($error)) {
      drupal_set_message($this->t('There was an error in communicating with Acquia.com. @err', array('@err' => $error)), 'error');
    }

    $this->redirect('acquia_connector.settings');
  }

  /**
   * Menu callback for checking client upload.
   */
  public function migrateCheck() {
    $return = array('compatible' => TRUE);

    $migrate = new Migration();
    $env = $migrate->checkEnv();

    if (empty($env) || $env['error'] !== FALSE) {
      $return['compatible'] = FALSE;
      $return['message'] = $env['error'];
    }

    return new JsonResponse($return);
  }

}
