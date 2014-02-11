<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\Form\SetupForm.
 */

namespace Drupal\acquia_connector\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Url;

/**
 * Class SetupForm.
 */
class SetupForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'acquia_agent_automatic_setup_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, array &$form_state) {
    if (empty($form_state['choose'])) {
      return $this->setupForm($form_state);
    }
    else {
      return $this->chooseForm($form_state);
    }
  }

  /**
   * @param $form_state
   *
   * @return array
   */
  protected function setupForm(&$form_state) {
    $form = array(
      '#prefix' => $this->t('Log in or <a href="!url">configure manually</a> to connect your site to the Acquia Network.', array('!url' => url('admin/config/system/acquia-agent/credentials'))),
      'email' => array(
        '#type' => 'textfield',
        '#title' => $this->t('Enter the email address you use to login to the Acquia Network:'),
        '#required' => TRUE,
      ),
      'pass' => array(
        '#type' => 'password',
        '#title' => $this->t('Enter your Acquia Network password:'),
        '#description' => t('Your password will not be stored locally and will be sent securely to Acquia.com. <a href="!url" target="_blank">Forgot password?</a>', array('!url' => url('https://accounts.acquia.com/user/password'))),
        '#size' => 32,
        '#required' => TRUE,
      ),
      'actions' => array(
        '#type' => 'actions',
        'continue' => array(
          '#type' => 'submit',
          '#value' => $this->t('Next'),
        ),
        'signup' => array(
          '#markup' => $this->t('Need a subscription? <a href="!url">Get one</a>.', array('!url' => url('https://www.acquia.com/acquia-cloud-free'))),
        ),
      ),
    );
    return $form;
  }

  /**
   * @param $form_state
   *
   * @return array
   */
  protected function chooseForm(&$form_state) {
    $options = array();
    foreach ($form_state['subscriptions'] as $credentials) {
      $options[] = $credentials['name'];
    }
    asort($options);

    $form = array(
      '#prefix' => $this->t('You have multiple subscriptions available.'),
      'subscription' => array(
        '#type' => 'select',
        '#title' => $this->t('Available subscriptions'),
        '#options' => $options,
        '#description' => $this->t('Choose from your available subscriptions.'),
        '#required' => TRUE,
      ),
      'continue' => array(
        '#type' => 'submit',
        '#value' => $this->t('Submit'),
      ),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, array &$form_state) {
    if (!isset($form_state['choose'])) {
      $config = $this->config('acquia_connector.settings');

      // Validate e-mail address and get account hash settings.
      $body = array(
        'email' => $form_state['values']['email'],
      );
      $authenticator = _acquia_agent_create_authenticator($body);
      $data = array('body' => $body, 'authenticator' => $authenticator);
      // Does not use acquia_agent_call() because Network identifier and key are not available.
      $server = $config->get('network_address');
      $result = xmlrpc(acquia_agent_network_address($server), array('acquia.agent.communication.settings' => array($data)));

      if ($errno = xmlrpc_errno() !== NULL) {
        acquia_agent_report_xmlrpc_error();
        // Set form error to prevent switching to the next page.
        $this->setFormError('', $form_state);
      }
      elseif (!$result) {
        // Email doesn't exist.
        $this->setFormError('email', $form_state, $this->t('Account not found on the Acquia Network.'));
      }
      else {
        // Build hashed password from account password settings for further
        // XML-RPC communications with acquia.com.
        $pass = _acquia_agent_hash_password_crypt($result['algorithm'], $form_state['values']['pass'], $result['hash_setting'], $result['extra_md5']);
        $form_state['pass'] = $pass;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, array &$form_state) {
    if (isset($form_state['choose']) && isset($form_state['subscriptions'][$form_state['values']['subscription']])) {
      $config = $this->config('acquia_connector.settings');

      $sub = $form_state['subscriptions'][$form_state['values']['subscription']];
      $config->set('acquia_key', $sub['key'])
        ->set('acquia_identifier', $sub['identifier'])
        ->set('acquia_subscription_name', $sub['name'])
        ->save();
    }
    else {
      $this->automaticStartSubmit($form_state);
    }

    // Don't set message or redirect if multistep.
    if (!$this->errorHandler()->getErrors($form_state) && empty($form_state['rebuild'])) {
      // Check subscription and send a heartbeat to Acquia Network via XML-RPC.
      // Our status gets updated locally via the return data.
      $active = acquia_agent_check_subscription();

      // Redirect to the path without the suffix.
      $form_state['redirect'] = new Url('acquia_connector.settings');

      // @todo What is this trying to clear in particular?
      cache_clear_all();

      if ($active && count($active) > 1) {
        drupal_set_message($this->t('<h3>Connection successful!</h3>You are now connected to the Acquia Network.'));
      }
    }
  }

  /**
   * @param $form_state
   */
  protected function automaticStartSubmit(&$form_state) {
    $config = $this->config('acquia_connector.settings');

    // Make hashed password signed request to Acquia Network for subscriptions.
    $body = array(
      'email' => $form_state['values']['email'],
    );
    // acquia.com authenticator uses hash of client-supplied password hashed with
    // remote settings so that the hash can match. pass was hashed in
    // _acquia_agent_setup_form_validate().
    $authenticator = _acquia_agent_create_authenticator($body, $form_state['pass']);
    $data = array('body' => $body, 'authenticator' => $authenticator);
    // Does not use acquia_agent_call() because Network identifier and key are not available.
    $server = $config->get('network_address');
    $result = xmlrpc(acquia_agent_network_address($server), array('acquia.agent.subscription.credentials' => array($data)));

    if ($errno = xmlrpc_errno()) {
      acquia_agent_report_xmlrpc_error();
      // Set form error to prevent switching to the next page.
      $this->setFormError('', $form_state);
    }
    elseif (!$result) {
      // Email doesn't exist
      $this->setFormError('email', $form_state, $this->t('Server error, please submit again.'));
    }
    elseif ($result['is_error']) {
      $this->setFormError('email', $form_state, $this->t('Server error, please submit again.'));
    }
    elseif (empty($result['body']['subscription'])) {
      $this->setFormError('email', $form_state, $this->t('No subscriptions were found for your account.'));
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

      $config->set('acquia_key', $sub['key'])
        ->set('acquia_identifier', $sub['identifier'])
        ->set('acquia_subscription_name', $sub['name'])
        ->save();
    }
  }

}
