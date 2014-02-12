<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\Controller\SettingsController.
 */

namespace Drupal\acquia_connector\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class SettingsController.
 */
class SettingsController extends ControllerBase {

  /**
   * Main page function
   */
  public function settingsPage(Request $request) {
    $config = $this->config('acquia_connector.settings');
    $identifier = $config->get('identifier');
    $key = $config->get('key');
    $subscription = $config->get('subscription_name');

    $path = drupal_get_path('module', 'acquia_agent');

    drupal_add_css($path . '/css/acquia_agent.css');

    $form_builder = $this->formBuilder();
    $form_builder->setRequest($request);

    if (empty($identifier) && empty($key)) {
      if ($arg != 'setup') {
        $start_controller = new AnStartController();
        return $start_controller->info();
      }
      else {
        return $form_builder->getForm('Drupal\acquia_connector\Form\SetupForm');
      }
    }
    else {
      if (empty($subscription)) {
        // Subscription name isn't set but key and id are is likely because
        // user has updated from Acquia Connector 2.1. Need to clear menu cache and
        // set subscription name.
        _acquia_agent_setup_subscription_name();
      }

      return $form_builder->getForm('Drupal\acquia_connector\Form\SettingsForm');
    }
  }

}
