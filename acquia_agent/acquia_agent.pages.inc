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
