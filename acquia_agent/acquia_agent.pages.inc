<?php

/**
 * @file
 *   Acquia Agent configuration page.
 */

/**
 * Helper function. Creates an authenticator for XML-RPC calls
 *
 * @param array $body array of values being sent to remote server
 * @param string $pass 
 */
function _acquia_agent_create_authenticator($body, $pass = NULL) {
  $auth = array();
  $auth['time'] = REQUEST_TIME;
  $auth['nonce'] = base64_encode(hash('sha256', drupal_random_bytes(55), TRUE));;
  if (isset($pass)) {
    $auth['hash'] = _acquia_agent_hmac($pass, $auth['time'], $auth['nonce'], $body);
  }
  else {
    // rpc.acquia.com XML-RPC interface requires this parameter to be a string.
    // Just pass a dummy value.
    $auth['hash'] = 'x';
  }
  return $auth;
}

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
 * Helper function that creates a new AN subscription via xmlrpc call. It stores id/key
 * if success and sets page error otherwise
 */
function _acquia_agent_call_provision_freetrial($body, $authenticator, $pass) {
  $md5_pass = md5($pass);
  $values = array('body' => $body, 'authenticator' => $authenticator);
  $result = xmlrpc(acquia_agent_network_address(), array('acquia.agent.provision.freetrial' => array($values)));
  if ($errno = xmlrpc_errno()) {
    drupal_set_message(t('Error getting free trial: @message (@errno)', array('@message' => xmlrpc_error_msg(), '@errno' => xmlrpc_errno())), 'error');
    watchdog('acquia agent', '@message (@errno): %server - %method - <pre>@data</pre>', array('@message' => xmlrpc_error_msg(), '@errno' => xmlrpc_errno(), '%server' => acquia_agent_network_address(), '%method' => 'acquia.agent.provision.freetrial', '@data' => print_r($values, TRUE)), WATCHDOG_ERROR);
    $result = FALSE;
  }
  elseif (!empty($result['body']['nid'])) {
    if (!empty($result['body']['hashkey']) && !empty($md5_pass)) {
      // We have a key XOR'd with a hash.
      $hash = str_pad(_acquia_agent_hmac(
        $md5_pass,
        $authenticator['time'],
        $authenticator['nonce'],
        $result['body']['identifier']), 64, chr(0x00));
      // Repeat the XOR and remove trailing NUL bytes.
      $key = rtrim(base64_decode($result['body']['hashkey']) ^ $hash);
      // Strip off the padding marker character.
      $key = substr($key, 0, -1);
    }
    else {
      $key = $result['body']['key'];
    }
    variable_set('acquia_key', $key);
    variable_set('acquia_identifier', $result['body']['identifier']);
    drupal_set_message(t('The Acquia configuration options have been saved.'));
    // Check subscription and send a heartbeat to Acquia Network via XML-RPC.
    // Our status gets updated locally via the return data.
    acquia_agent_check_subscription();
    cache_clear_all();
  }
  return $result;
}

/**
 *  Main 30 day trial form function
 */
function acquia_agent_an_start_form($form, &$form_state, $banner) {
  $header = acquia_agent_an_info_header();

  $form = array(
    '#prefix' =>  '<div class="an-start-form">',
    'header'  => array('#markup' => $header),
    'banner'  => array('#markup' => $banner),
    '#theme'  => 'acquia_agent_banner_form',
    '#suffix' => '</div>',
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
  $path = drupal_get_path('module', 'acquia_agent');
  $dynamic_banner = variable_get('acquia_dynamic_banner', FALSE);
  if ($dynamic_banner) {
    drupal_add_js(array('acquia_network' => array('id' => $identifier ? $identifier : FALSE)), 'setting');
    drupal_add_js(array('acquia_network' => array('url' => variable_get('acquia_banner_service', 'https://insight.acquia.com/system/acquia-banner'))), 'setting');
    $src = variable_get('acquia_banner_serve', 'http://www.acquia.com/acquia_banner.js');
    $banner = "<script type='text/javascript' src='" . htmlentities($src) . "'></script>";
  }
  else {
    $banner = theme('image', array('path' => $path . '/images/action.png'));
    $banner = '<a href="http://www.acquia.com/trial" target="_blank">' . $banner . '</a>';
  }
  drupal_add_css($path . '/css/acquia_agent.css');

  if (empty($identifier) && empty($key) && $arg == 'setup') {
    return drupal_get_form('acquia_agent_automatic_setup_form');
  }
  elseif (($identifier && $key) || $arg == 'connection') {
    // @todo blank banner when subscription is active and dynamic banner is off
    return acquia_agent_settings_form_page($banner);
  }
  else {
    drupal_set_title(t('Get a free 30 day trial of the Acquia Network'));
    return drupal_get_form('acquia_agent_an_start_form', $banner);
  }
}

/**
 * Menu callback for settings page.
 */
function acquia_agent_settings_form_page($banner = NULL) {
  $identifier = acquia_agent_settings('acquia_identifier');
  $key = acquia_agent_settings('acquia_key');

  $output =  '';
  // Check $_POST so we don't send extra XML-RPC requests during form submission.
  if ($identifier && $key && empty($_POST)) {
    // Check our connection to the Acquia Network and validity of the crenditials.
    $acquia_network_address = acquia_agent_settings('acquia_network_address');
    if (acquia_agent_valid_credentials($identifier, $key, $acquia_network_address)) {
      $subscription = acquia_agent_settings('acquia_subscription_data');
    }
    else {
      $error_message = acquia_agent_connection_error_message();
      drupal_set_message($error_message, 'error', FALSE);
    }
  }
  return drupal_get_form('acquia_agent_settings_form', $banner);
}

function acquia_agent_automatic_setup_form($form, &$form_state) {
  if (isset($form_state['choose'])) {
    return _acquia_agent_automatic_setup_form_choose($form_state);
  }
  else {
    return _acquia_agent_automatic_setup_form_start($form_state);
  }
}

function _acquia_agent_automatic_setup_form_start(&$form_state) {
  global $base_url;
  $form = array(
    '#prefix' =>  t('To setup the connection to your Acquia Network subscription enter your Acquia.com account email address and password.'),
    'email' => array(
      '#type' => 'textfield',
      '#title' => t('Acquia Network email'),
      '#description' => t('Your Acquia.com email address. The value will not be stored locally.'),
      '#required' => TRUE,
    ),
    'pass' => array(
      '#type' => 'password',
      '#title' => t('Acquia.com password'),
      '#description' => t('Your Acquia.com account password. The value will not be stored locally and will be sent securely to acquia.com. <a href="!url" target="_blank">Forgot password?</a>', array('!url' => url('http://acquia.com/user/password'))),
      '#size' => 32,
      '#required' => TRUE,
    ),
    '#suffix' =>  t('<a href="!url">If you prefer to manually enter your Acquia subscription information you may do so on the connection page</a>.', array('!url' => url('admin/config/system/acquia-agent/connection'))),
    'continue' => array(
      '#type' => 'submit',
      '#value' => t('Next'),
    ),
  );
  return $form;
}

function _acquia_agent_automatic_setup_form_choose(&$form_state) {
  $options = array();
  foreach ($form_state['subscriptions'] as $credentials) {
    $options[] = $credentials['name'];
  }
  $form = array(
    '#prefix' =>  t('You have multiple subscriptions available. Please choose the subscription you would like to finish connecting.'),
    'subscription' => array(
      '#type' => 'select',
      '#title' => t('Available subscriptions'),
      '#options' => $options,
      '#description' => t('Choose from your available descriptions.'),
      '#required' => TRUE,
    ),
    'continue' => array(
      '#type' => 'submit',
      '#value' => t('Submit'),
    ),
  );
  return $form;
}

function acquia_agent_automatic_setup_form_validate($form, &$form_state) {
  if (!isset($form_state['choose'])) {
    // Validate e-mail address and get account hash settings.
    $data = array(
      'email' => $form_state['values']['email'],
    );
    $result = xmlrpc(acquia_agent_network_address(), array('acquia.agent.communication.settings' => array($data)));

    if ($errno = xmlrpc_errno()) {
      acquia_agent_report_xmlrpc_error();
      // Set form error to prevent switching to the next page.
      form_set_error('');
    }
    elseif (!$result) {
      // Email doesn't exist
      form_set_error('email', t("Email address not found on the Acquia Network."));
    }
    else {
      // Build hashed password from account password settings for further 
      // XML-RPC communications with acquia.com.
      $pass = _acquia_agent_hash_password_crypt($result['algorithm'], $form_state['values']['pass'], $result['hash_setting'], $result['extra_md5']);
      $form_state['pass'] = $pass;
    }
  }
}

function acquia_agent_automatic_setup_form_submit($form, &$form_state) {
  if (isset($form_state['choose']) && isset($form_state['subscriptions'][$form_state['values']['subscription']])) {
    $sub = $form_state['subscriptions'][$form_state['values']['subscription']];
    variable_set('acquia_key', $sub['key']);
    variable_set('acquia_identifier', $sub['identifier']);
  }
  else {
    _acquia_agent_automatic_start_submit($form_state);
  }
}

function _acquia_agent_automatic_start_submit(&$form_state) {
  // Make hashed password signed request to Acquia Network for subscriptions.
  $body = array(
    'email' => $form_state['values']['email'],
  );
  // acquia.com authenticator uses hash of client-supplied password hashed with
  // remote settings so that the hash can match. pass was hashed in
  // _acquia_agent_setup_form_validate().
  $authenticator = _acquia_agent_create_authenticator($body, $form_state['pass']);
  $data = array('body' => $body, 'authenticator' => $authenticator);
  $result = xmlrpc(acquia_agent_network_address(), array('acquia.agent.subscription.credentials' => array($data)));

  if ($errno = xmlrpc_errno()) {
    acquia_agent_report_xmlrpc_error();
    // Set form error to prevent switching to the next page.
    form_set_error('');
  }
  elseif (!$result) {
    // Email doesn't exist
    form_set_error('email', t("Server error, please submit again."));
  }
  elseif ($result['is_error']) {
    form_set_error('email', t("Server error, please submit again."));
  }
  elseif (!isset($result['body']['subscription'])) {
    form_set_error('host', t('No subscriptions were found.'));
  }
  elseif (count($result['body']['subscription']) > 1) {
    // Multistep form for choosing from available subscriptions.
    $form_state['choose'] = TRUE;
    $form_state['subscriptions'] = $result['body']['subscription'];
    $form_state['rebuild'] = TRUE; // Force rebuild with next step.
  }
  else {
    // One subscription so set id/key pair.
    $sub = $result['body']['subscription'][0];
    variable_set('acquia_key', $sub['key']);
    variable_set('acquia_identifier', $sub['identifier']);
  }
}

/**
 * Settings form builder function.
 */
function acquia_agent_settings_form($form, &$form_state, $banner) {
  $identifier = acquia_agent_settings('acquia_identifier');
  $key = acquia_agent_settings('acquia_key');

  // Help for copying the identifier and key.
  $copy_help = t('Copy from <a href="@subscriptions-overview">your subscriptions overview on the Acquia Network</a>.', array('@subscriptions-overview' => 'http://network.acquia.com/network/dashboard/subscription'));

  $form['cs'] = array(
    '#type' => 'fieldset',
    '#title' => t('Acquia Network keys'),
    '#collapsible' => FALSE,
    '#collapsed' => FALSE,
  );

  $form['cs']['acquia_identifier'] = array(
    '#type' => 'textfield',
    '#title' => t('Identifier'),
    '#default_value' => $identifier,
    '#description' => $copy_help,
    '#required' => TRUE,
  );
  $form['cs']['acquia_key'] = array(
    '#type' => 'textfield',
    '#title' => t('Key'),
    '#default_value' => $key,
    '#description' => $copy_help,
    '#required' => TRUE,
  );

  $form['cs']['buttons']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Save keys'),
    '#validate' => array('acquia_agent_set_validate'),
    '#submit' => array('acquia_agent_set_submit'),
  );
  $form['cs']['buttons']['delete'] = array(
    '#type' => 'submit',
    '#value' => t('Clear keys'),
    '#access' => (!empty($identifier) || !empty($key)),
    '#submit' => array('acquia_agent_delete_submit'),
  );
  $ssl_available = (in_array('ssl', stream_get_transports(), TRUE) && !defined('ACQUIA_DEVELOPMENT_NOSSL'));

  $form['connection'] = array(
    '#type' => 'fieldset',
    '#title' => t('Connector settings'),
    '#collapsible' => FALSE,
  );
  $form['connection']['acquia_dynamic_banner'] = array(
    '#type' => 'checkbox',
    '#title' => t('Receive updates'),
    '#default_value' => variable_get('acquia_dynamic_banner', FALSE),
    '#description' => t('Receive dynamic updates on this page from Acquia.com about your subscription and new features.'),
  );
  $form['connection']['acquia_agent_verify_peer'] = array(
    '#type' => 'radios',
    '#title' => t('Enhanced SSL security'),
    '#default_value' => (int) (variable_get('acquia_agent_verify_peer', 0) && $ssl_available),
    '#options' => array(0 => t('Disabled'), 1 => t('Enabled')),
    '#description' => t('If enabled the Acquia Connector will attempt to verify Acquia server identities before sending data.  May cause communication to fail, however, depending on your local configuration.'),
    '#disabled' => !$ssl_available,
  );
  if ($ssl_available) {
    $form['connection']['acquia_agent_verify_peer']['#description'] .= ' <div class="messages ok">'. t('PHP has SSL support and may support this feature.') .'</div>';
  }
  else {
    $form['connection']['acquia_agent_verify_peer']['#description'] .= ' <div class="messages error">'. t('Your installation of PHP does not have SSL support. Please enable the SSL extension or compile PHP with SSL, see: <a href="http://php.net/manual/en/book.openssl.php" target="_blank">http://php.net/manual/en/book.openssl.php</a>.') .'</div>';
  }
  $form['connection']['buttons']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Save configuration'),
    '#submit' => array('acquia_agent_settings_submit'),
  );
  if (!empty($identifier) && !empty($key)) {
    $form['migrate'] = array(
      '#type' => 'fieldset',
      '#title' => t('Migrate'),
      '#collapsible' => FALSE,
    );
    $form['migrate']['submit'] = array(
      '#type' => 'submit',
      '#value' => t('Migrate'),
      '#submit' => array('acquia_agent_migrate_submit'),
    );
  }

  $form['banner'] = array('#markup' => $banner);
  $form['#theme'] = 'acquia_agent_banner_form';
  return $form;
}

/**
 * Submit acquia_agent_settings ssl setting.
 */
function acquia_agent_settings_submit($form, &$form_state) {
  variable_set('acquia_agent_verify_peer', $form_state['values']['acquia_agent_verify_peer']);
  variable_set('acquia_dynamic_banner', $form_state['values']['acquia_dynamic_banner']);
  drupal_set_message(t('The configuration options have been saved.'));
}

/**
 * Submit handler for Acquia Cloud migrate button.
 */
function acquia_agent_migrate_submit($form, &$form_state) {
  module_load_include('inc', 'acquia_agent', 'acquia_agent.migrate');
  // Prepare for migration
  $migration = acquia_migration_prepare();
  if (isset($migration['error']) && $migration['error'] == TRUE) {
    // @todo
    drupal_set_message('error');
  }
  else {
    $batch = array(
      'title' => t('Acquia Cloud Migration'),
      'operations' => array(
        //array('acquia_migrate_batch_db', array($migration)),
        //array('acquia_migrate_batch_tar', array($migration)),
        array('acquia_migrate_batch_transmit', array($migration)),
      ),
      'finished' => 'acquia_migrate_batch_finished',
      'file' => drupal_get_path('module', 'acquia_agent') . '/acquia_agent.migrate.inc',
    );
    batch_set($batch);
  }
}

/**
 * Validate acquia_agent_settings form submissions.
 */
function acquia_agent_set_validate($form, &$form_state) {
  // Trim all input to get rid of possible whitespace pasted from the website.
  foreach ($form_state['values'] as $key => $value) {
    $form_state['values'][$key] = trim($value);
  }
  $identifier = $form_state['values']['acquia_identifier'];
  $key = $form_state['values']['acquia_key'];

  // Don't make the XML-RPC call with empty values.
  if (!empty($identifier) && !empty($key)) {
    if (!acquia_agent_valid_credentials($identifier, $key)) {
      $error_message = acquia_agent_connection_error_message();
      form_error($form, $error_message);
    }
  }
}

/**
 * Save acquia_agent_settings form submissions.
 */
function acquia_agent_set_submit($form, &$form_state) {
  variable_set('acquia_key', $form_state['values']['acquia_key']);
  variable_set('acquia_identifier', $form_state['values']['acquia_identifier']);
  drupal_set_message(t('The Acquia configuration options have been saved.'));
  // Check subscription and send a heartbeat to Acquia Network via XML-RPC.
  // Our status gets updated locally via the return data.
  $active = acquia_agent_check_subscription();
  // Redirect to the path without the suffix.
  $form_state['redirect'] = 'admin/config/system/acquia-agent';
  cache_clear_all();
}

/**
 * Delete acquia_agent settings.
 */
function acquia_agent_delete_submit($form, &$form_state) {
  variable_del('acquia_key');
  variable_del('acquia_identifier');
  variable_del('acquia_network_address');
  variable_del('acquia_subscription_data');
  drupal_set_message(t('Your Acquia configuration has been deleted.'));
  // Redirect to the path with the suffix.
  $form_state['redirect'] = 'admin/config/system/acquia-agent/connection';
  cache_clear_all();

  // Notify everybody that the subscription status may have changed.
  module_invoke_all('acquia_subscription_status', FALSE);
}

function theme_acquia_agent_banner_form($variables) {
  $form = $variables['form'];
  if (empty($form['banner'])) {
    return drupal_render_children($form);
  }
  $banner = drupal_render($form['banner']);
  $output = '<div id="an-pg-container"><div id="an-pg-form">';
  $output .= drupal_render_children($form);
  $output .= "\n</div>\n";
  $output .= '<div class="an-pg-banner" id="' . $form['#id'] . '-banner">';
  $output .= $banner;
  $output .= "\n</div>\n</div>\n";
  return $output;
}

function acquia_agent_an_info_header() {
  $path = drupal_get_path('module', 'acquia_agent');

  $l_opt = array('attributes' => array('target' => '_blank'));
  $output  = '<div class="an-wrapper">';
  $output .= '<h2 id="an-info-header">' . t('Acquia Network', array('@acquia-network' => 'http://acquia.com/products-services/acquia-network')) . '</h2>';
  $output .= '<p class="an-slogan">' . t('A suite of products and services to create & maintain killer web experiences built on Drupal') . '</p>';
  $output .= '<div id="an-info-box">';
  $output .=   '<div class="cell with-arrow an-left">';
  $output .=     '<h2 class="cell-title"><i>' . t('Answers you need') . '</i></h2>';
  $output .=     '<a href="http://library.acquia.com/" target="_blank">' . theme('image', array('path' => $path . '/images/icon-library.png')) . '</a>';
  $output .=     '<p class="cell-p">' . t("Tap the collective knowledge of Acquia’s technical support team & partners.") . '</p>';
  $output .=   '</div>';
  $output .=   '<div class="cell with-arrow an-center">';
  $output .=     '<h2 class="cell-title"><i>' . t('Tools to extend your site') . '</i></h2>';
  $output .=     '<a href="http://www.acquia.com/products-services/acquia-network/cloud-services" target="_blank">' . theme('image', array('path' => $path . '/images/icon-tools.png')) . '</a>';
  $output .=     '<p class="cell-p">' . t('Enhance and extend your site with an array of <a href="@services" target="_blank">services</a> from Acquia & our partners.', array('@services' => 'http://www.acquia.com/products-services/acquia-network/cloud-services')) . '</p>';
  $output .=   '</div>';
  $output .=   '<div class="cell an-right">';
  $output .=     '<h2 class="cell-title"><i>' . t('Support when you want it') . '</i></h2>';
  $output .=     '<a href="http://www.acquia.com/drupal-support" target="_blank">' . theme('image', array('path' => $path . '/images/icon-support.png')) . '</a>';
  $output .=     '<p class="cell-p">' . t("Experienced Drupalists are available to support you whenever you need it.") . '</p>';
  $output .=   '</div>';
  $output .=  '</div>';
  $output .= '</div>';
  return $output;
}
