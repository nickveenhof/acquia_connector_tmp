<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\Form\SetupForm.
 */

namespace Drupal\acquia_connector\Form;

use Drupal\acquia_connector\Migration;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Class SetupForm.
 */
class MigrateForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'acquia_connector_migrate_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, array &$form_state) {
    $config = $this->config('acquia_connector.settings');
    $identifier = $config->get('identifier');
    $key = $config->get('key');
    $data = acquia_agent_call('acquia.agent.cloud.migration.environments', array('identifier' => $identifier), $identifier, $key, $config->get('network_address'));

    $error = NULL;
    if ($errno = xmlrpc_errno()) {
      acquia_agent_report_xmlrpc_error();
      return $this->redirect('acquia_connector.settings');
    }
    elseif (!$data || !isset($data['result'])) {
      $error = $this->t('Server error, please submit again.');
    }
    else {
      // Response is in $data['result'].
      $result = $data['result'];
      if (!empty($result['is_error'])) {
        $error = $this->t('Server error, unable to retrieve environments for migration');
      }
      elseif (!empty($result['body']['error'])) {
        $error = $result['body']['error'];
      }
      elseif (empty($result['body']['environments'])) {
        $error = $this->t('Server error, unable to retrieve environments for migration');
      }
    }

    if ($error) {
      drupal_set_message($error, 'error');
      return $this->redirect('acquia_connector.settings');
    }

    foreach ($result['body']['environments'] as $stage => $env) {
      $result['body']['environments'][$stage]['secret'] = base64_decode($env['secret']);
    }

    $form['envs'] = array(
      '#type' => 'value',
      '#value' => $result['body']['environments']
    );

    $envs = array();
    foreach (array_keys($result['body']['environments']) as $env) {
      $envs[$env] = Unicode::ucfirst($env);
    }

    if (count($result['body']['environments']) > 1) {
      $form['environment'] = array(
        '#type' => 'select',
        '#title' => $this->t('Select environment for migration'),
        '#options' => $envs,
        '#description' => $this->t('Select which environment your site should be migrated to. Only environments that are running trunk or branch can be overwritten by migration. Environments running a tag are not included.'),
      );
    }
    else {
      $form['environment'] = array(
        '#markup' => $this->t('Available environment for migration: %env', array('%env' => array_pop($envs))),
      );
    }
    $form['migrate_files'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Migrate files directory'),
      '#description' => $this->t('Include files directory and all files in migration. If you are experiencing migration errors it is recommended you do not send the files directory.'),
      '#default_value' => $config->get('migrate_files'),
    );
    $form['reduce_db_size'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Reduce database export size'),
      '#description' => $this->t('Reduce the database export size by excluding the data from cache, session, and watchdog tables. If you are experiencing migration errors this is recommended. Table structure will still be exported.'),
      '#default_value' => 0,
    );

    $form['actions'] = array('#type' => 'actions');
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Migrate'),
    );
    $form['actions']['cancel'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#submit' => array($this, 'submitMigrateCancel'),
    );

    return $form;
  }

  /**
   * Submit handler for Migrate button on settings form.
   */
  public function submitMigrateCancel($form, &$form_state) {
    $form_state['redirect'] = new Url('acquia_connector.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, array &$form_state) {
    module_load_include('inc', 'acquia_agent', 'acquia_agent.migrate');

    // Sanity check.
    if (empty($form_state['values']['envs'])) {
      return;
    }

    $migrate_files = isset($form_state['values']['migrate_files']) ? $form_state['values']['migrate_files'] : TRUE;

    $this->config('acquia_connector.settings')->set('acquia_migrate_files', $migrate_files)->save();

    $reduce_db_size = !empty($form_state['values']['reduce_db_size']) ? $form_state['values']['reduce_db_size'] : FALSE;

    if (count($form_state['values']['envs']) > 1) {
      // Use selected environment.
      $env = $form_state['values']['envs'][$form_state['values']['environment']];
      $site_name = $form_state['values']['environment'];
    }
    else {
      $env = array_pop($form_state['values']['envs']);
      $site_name = $env;
    }

    // Prepare for migration.
    $migration_class = new Migration();
    $migration = $migration_class->prepare($env);
    $migration['site_name'] = $site_name;
    if ($reduce_db_size) {
      $migration['no_data_tables'] = array('cache', 'cache_menu', 'cache_page', 'cache_field', 'sessions', 'watchdog');
    }

    if (isset($migration['error']) && $migration['error'] !== FALSE) {
      drupal_set_message($this->t('Unable to begin migration. @error', array('@error' => $migration['error'])), 'error');
      $form_state['redirect'] = new Url('acquia_connector.settings');
    }
    else {
      $batch = array(
        'title' => $this->t('Acquia Cloud Migrate'),
        'operations' => array(
          array(array($migration_class, 'batchTest'), array($migration)),
          array(array($migration_class, 'batchDb'), array($migration)),
          array(array($migration_class, 'batchTar'), array($migration)),
          array(array($migration_class, 'batchTransmit'), array($migration)),
        ),
        'init_message' => $this->t('Preparing for migration'),
        'progress_message' => $this->t('Completed @current of @total steps.'),
        'finished' => array($migration_class, 'batchFinished'),
      );

      batch_set($batch);
    }
  }

  /**
   * Returns a redirect response object for the specified route.
   *
   * @param string $route_name
   *   The name of the route to which to redirect.
   * @param array $route_parameters
   *   Parameters for the route.
   * @param int $status
   *   The HTTP redirect status code for the redirect. The default is 302 Found.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response object that may be returned by the controller.
   */
  protected function redirect($route_name, array $route_parameters = array(), $status = 302) {
    $url = $this->urlGenerator()->generate($route_name, $route_parameters, TRUE);
    return new RedirectResponse($url, $status);
  }

}
