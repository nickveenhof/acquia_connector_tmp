<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\Form\CredentialForm.
 */

namespace Drupal\acquia_connector\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Class CredentialForm.
 */
class CredentialForm extends FormBase {

 /**
  * {@inheritdoc}
  */
  public function getFormId() {
    return 'acquia_connector_settings_credentials';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('acquia_connector.settings');

    $form['#prefix'] = $this->t('Enter your <a href="@net">identifier and key</a> from your subscriptions overview or <a href="@url">log in</a> to connect your site to the Acquia Network.', array('@net' => Url::fromUri('https://insight.acquia.com/subscriptions')->getUri(), '@url' => \Drupal::url('acquia_connector.setup')));
    $form['acquia_identifier'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Identifier'),
      '#default_value' => $config->get('identifier'),
      '#required' => TRUE,
    );
    $form['acquia_key'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Network key'),
      '#default_value' => $config->get('key'),
      '#required' => TRUE,
    );
    $form['actions'] = array('#type' => 'actions');
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Connect'),
    );
    $form['actions']['signup'] = array(
      '#markup' => $this->t('Need a subscription? <a href="@url">Get one</a>.', array('@url' => Url::fromUri('https://www.acquia.com/acquia-cloud-free')->getUri())),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('acquia_connector.settings');

    // Trim all input to get rid of possible whitespace pasted from the website.
    foreach ($form_state['values'] as $key => $value) {
      $form_state['values'][$key] = trim($value);
    }
    $identifier = $form_state['values']['acquia_identifier'];
    $key = $form_state['values']['acquia_key'];
    // Validate credentials and get subscription name.
    $body = array('identifier' => $identifier);
    $data = acquia_agent_call('acquia.agent.subscription.name', $body, $identifier, $key, $config->get('network_address'));

    $error = NULL;
    if ($errno = xmlrpc_errno()) {
      acquia_agent_report_xmlrpc_error();
      // Set form error to prevent switching to the next page.
      $this->setFormError('', $form_state);
    }
    elseif (!$data || !isset($data['result'])) {
      $this->setFormError('', $form_state, $this->t('Server error, please submit again.'));
    }
    $result = $data['result'];
    if (!empty($result['is_error'])) {
      $this->setFormError('', $form_state, $this->t('Server error, please submit again.'));
    }
    elseif (isset($result['body']['error'])) {
      $this->setFormError('', $form_state, $result['body']['error']);
    }
    elseif (empty($result['body']['subscription'])) {
      $this->setFormError('acquia_identifier', $form_state, $this->t('No subscriptions were found.'));
    }
    else {
      // Store subscription.
      $form_state['sub'] = $result['body']['subscription'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('acquia_connector.settings');

    $config->set('key', $form_state['values']['acquia_key'])
      ->set('identifier', $form_state['values']['acquia_identifier'])
      ->set('subscription_name', $form_state['sub']['site_name'])
      ->save();

    // Check subscription and send a heartbeat to Acquia Network via XML-RPC.
    // Our status gets updated locally via the return data.
    $active = acquia_agent_check_subscription();

    // Redirect to the path without the suffix.
    $form_state['redirect'] = new Url('acquia_connector.settings');

    cache_clear_all();

    if ($active && count($active) > 1) {
      drupal_set_message($this->t('<h3>Connection successful!</h3>You are now connected to the Acquia Network.'));
    }
  }

}
