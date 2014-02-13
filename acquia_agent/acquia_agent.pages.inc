<?php

/**
 * @file
 *   Acquia Agent configuration page.
 */

/**
 * Hash a password according to Drupal's password_crypt and settings from remote.
 */
function _acquia_agent_hash_password_crypt($algorithm, $pass, $setting, $extra_md5 = FALSE) {
  // Server may state that password needs to be hashed with MD5 first.
  if ($extra_md5) {
    $pass = md5($pass);
  }
  // Include core's password.inc because it has _password_crypt().
  require_once DRUPAL_ROOT . '/includes/password.inc';
  $hash = _password_crypt($algorithm, $pass, $setting);
  // Match the hash stored on the server which has prefix character 'U' if
  // password was first hashed with MD5.
  if ($extra_md5) {
    $hash = 'U' . $hash;
  }
  return $hash;
}

/**
 * Migrate site to Acquia Cloud
 */
function acquia_agent_migrate_page() {
  $identifier = acquia_agent_settings('acquia_identifier');
  $key = acquia_agent_settings('acquia_key');
  if ($identifier && $key) {
    $acquia_network_address = acquia_agent_settings('acquia_network_address');
    if (acquia_agent_valid_credentials($identifier, $key, $acquia_network_address)) {
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
    drupal_set_message(t('There was an error in communicating with Acquia.com. @err', array('@err' => $error)), 'error');
  }
  drupal_goto('admin/config/system/acquia-agent');
}

/**
 * Migration start form
 */
function acquia_agent_migrate_form($form, &$form_state) {
  $identifier = acquia_agent_settings('acquia_identifier');
  $key = acquia_agent_settings('acquia_key');
  $data = acquia_agent_call('acquia.agent.cloud.migration.environments', array('identifier' => $identifier), $identifier, $key, variable_get('acquia_network_address', 'https://rpc.acquia.com'));

  $error = NULL;
  if ($errno = xmlrpc_errno()) {
    acquia_agent_report_xmlrpc_error();
    drupal_goto('admin/config/system/acquia-agent');
  }
  elseif (!$data || !isset($data['result'])) {
    $error = t("Server error, please submit again.");
  }
  else {
    // Response is in $data['result'].
    $result = $data['result'];
    if (!empty($result['is_error'])) {
      $error = t('Server error, unable to retrieve environments for migration');
    }
    elseif (!empty($result['body']['error'])) {
      $error = $result['body']['error'];
    }
    elseif (empty($result['body']['environments'])) {
      $error = t('Server error, unable to retrieve environments for migration');
    }
  }

  if ($error) {
    drupal_set_message($error, 'error');
    drupal_goto('admin/config/system/acquia-agent');
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
    $envs[$env] = drupal_ucfirst($env);
  }

  if (count($result['body']['environments']) > 1) {
    $form['environment'] = array(
      '#type' => 'select',
      '#title' => t('Select environment for migration'),
      '#options' => $envs,
      '#description' => t('Select which environment your site should be migrated to.
        Only environments that are running trunk or branch can
        be overwritten by migration. Environments running a tag are not included.')
    );
  }
  else {
    $form['environment'] = array(
      '#markup' => t('Available environment for migration: %env', array('%env' => array_pop($envs))),
    );
  }
  $form['migrate_files'] = array(
    '#type' => 'checkbox',
    '#title' => t('Migrate files directory'),
    '#description' => t('Include files directory and all files in migration. If you are experiencing migration errors it is recommended you do not send the files directory.'),
    '#default_value' => variable_get('acquia_migrate_files', 1),
  );
  $form['reduce_db_size'] = array(
    '#type' => 'checkbox',
    '#title' => t('Reduce database export size'),
    '#description' => t('Reduce the database export size by excluding the data from cache, session, and watchdog tables. If you are experiencing migration errors this is recommended. Table structure will still be exported.'),
    '#default_value' => 0,
  );

  $form['actions'] = array('#type' => 'actions');
  $form['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Migrate'),
  );
  $form['actions']['cancel'] = array(
    '#type' => 'submit',
    '#value' => t('Cancel'),
    '#submit' => array('acquia_agent_migrate_cancel_submit'),
  );

  return $form;
}

/**
 * Main page function
 */
function acquia_agent_settings_page($arg = NULL) {
  $banner = '';
  $identifier = acquia_agent_settings('acquia_identifier');
  $key = acquia_agent_settings('acquia_key');
  $subscription = acquia_agent_settings('acquia_subscription_name');
  $path = drupal_get_path('module', 'acquia_agent');

  drupal_add_css($path . '/css/acquia_connector.css');

  if (empty($identifier) && empty($key) && $arg != 'setup') {
    drupal_set_title(t('Get an Acquia Cloud Free subscription'));
    return drupal_get_form('acquia_agent_an_start_form', $banner);
  }
  if (empty($identifier) && empty($key)) {
    return drupal_get_form('acquia_agent_automatic_setup_form');
  }
  else {
    if (empty($subscription)) {
      // Subscription name isn't set but key and id are is likely because
      // user has updated from Acquia Connector 2.1. Need to clear menu cache and
      // set subscription name.
      _acquia_agent_setup_subscription_name();
    }
    return drupal_get_form('acquia_agent_settings_form', $banner);
  }
}

/**
 * Set subscription name and clear cache. Requires key and id to be set.
 */
function _acquia_agent_setup_subscription_name() {
  $identifier = acquia_agent_settings('acquia_identifier');
  $key = acquia_agent_settings('acquia_key');

  // Get subscription name.
  $body = array('identifier' => $identifier);
  $data = acquia_agent_call('acquia.agent.subscription.name', $body, $identifier, $key, variable_get('acquia_network_address', 'https://rpc.acquia.com'));

  if ($errno = xmlrpc_errno()) {
    $error = TRUE;
  }
  elseif (!$data || !isset($data['result'])) {
    $error = TRUE;
  }
  $result = $data['result'];
  if (!empty($result['is_error'])) {
    $error = TRUE;
  }
  elseif (isset($result['body']['error'])) {
    $error = TRUE;
  }
  elseif (empty($result['body']['subscription'])) {
    $error = TRUE;
  }
  else {
    variable_set('acquia_subscription_name', $result['body']['subscription']['site_name']);
    // Rebuild menu since there are new callbacks.
    menu_rebuild();
  }
  if (isset($error)) {
    drupal_set_message(t('Unable to automatically set subscription name, please reconnect to your subscription.'), 'error');
    drupal_goto('admin/config/system/acquia-agent/setup');
  }
}

/**
 * Submit handler for Acquia Cloud migrate button.
 */
function acquia_agent_migrate_form_submit($form, &$form_state) {
  module_load_include('inc', 'acquia_agent', 'acquia_agent.migrate');

  if (empty($form_state['values']['envs'])) { // Sanity check.
    return;
  }

  $migrate_files = isset($form_state['values']['migrate_files']) ? $form_state['values']['migrate_files'] : 1;
  variable_set('acquia_migrate_files', $migrate_files);
  $reduce_db_size = !empty($form_state['values']['reduce_db_size']) ? $form_state['values']['reduce_db_size'] : 0;
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
  $migration = acquia_migrate_prepare($env);
  $migration['site_name'] = $site_name;
  if ($reduce_db_size) {
    $migration['no_data_tables'] = array('cache', 'cache_menu', 'cache_page', 'cache_field', 'sessions', 'watchdog');
  }

  if (isset($migration['error']) && $migration['error'] !== FALSE) {
    drupal_set_message(t('Unable to begin migration. @error', array('@error' => $migration['error'])), 'error');
    $form_state['redirect'] = array('admin/config/system/acquia-agent');
  }
  else {
    $batch = array(
      'title' => t('Acquia Cloud Migrate'),
      'operations' => array(
        array('acquia_migrate_batch_test', array($migration)),
        array('acquia_migrate_batch_db', array($migration)),
        array('acquia_migrate_batch_tar', array($migration)),
        array('acquia_migrate_batch_transmit', array($migration)),
      ),
      'init_message' => t('Preparing for migration'),
      'progress_message' => t('Completed @current of @total steps.'),
      'finished' => 'acquia_migrate_batch_finished',
      'file' => drupal_get_path('module', 'acquia_agent') . '/acquia_agent.migrate.inc',
    );
    batch_set($batch);
  }
}

function acquia_agent_migrate_cleanup_submit($form, &$form_state) {
  module_load_include('inc', 'acquia_agent', 'acquia_agent.migrate');
  $migration = variable_get('acquia_agent_cloud_migration', array());
  _acquia_migrate_cleanup($migration);
  drupal_set_message(t('Temporary files removed'));
  $form_state['redirect'] = array('admin/config/system/acquia-agent');
}
