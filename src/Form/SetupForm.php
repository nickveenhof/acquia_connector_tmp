<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\Form\SetupForm.
 */

namespace Drupal\acquia_connector\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\acquia_connector\Client;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\acquia_connector\Subscription;

/**
 * Class SetupForm.
 */
class SetupForm extends ConfigFormBase {

  /**
   * The Acquia client.
   *
   * @var \Drupal\acquia_connector\Client
   */
  protected $client;

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   */
  public function __construct(ConfigFactoryInterface $config_factory, Client $client) {
    $this->configFactory = $config_factory;
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('acquia_connector.client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'acquia_connector_automatic_setup_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
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
      '#prefix' => $this->t('Log in or <a href="!url">configure manually</a> to connect your site to the Acquia Network.', array('!url' => url('admin/config/system/acquia-connector/credentials'))),
      'email' => array(
        '#type' => 'textfield',
        '#title' => $this->t('Enter the email address you use to login to the Acquia Network:'),
        '#required' => TRUE,
      ),
      'pass' => array(
        '#type' => 'password',
        '#title' => $this->t('Enter your Acquia Network password:'),
        '#description' => $this->t('Your password will not be stored locally and will be sent securely to Acquia.com. <a href="!url" target="_blank">Forgot password?</a>', array('!url' => url('https://accounts.acquia.com/user/password'))),
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
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (!isset($form_state['choose'])) {
      $response = $this->client->getSubscriptionCredentials($form_state['values']['email'], $form_state['values']['pass']);

      if (!empty($response['error'])) {
        // Set form error to prevent switching to the next page.
        $form_state->setErrorByName('email', $response['message']);
      }
      elseif (empty($response)) {
        // Email doesn't exist.
        $form_state->setErrorByName('', $this->t('Can\'t connect to the Acquia Network.'));
      }
      else {
        $form_state['response'] = $response;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if (isset($form_state['choose']) && isset($form_state['subscriptions'][$form_state['values']['subscription']])) {
      $config = $this->config('acquia_connector.settings');

      $sub = $form_state['subscriptions'][$form_state['values']['subscription']];
      $config->set('key', $sub['key'])
        ->set('identifier', $sub['identifier'])
        ->set('subscription_name', $sub['name'])
        ->save();
    }
    else {
      $this->automaticStartSubmit($form_state);
    }

    // Don't set message or redirect if multistep.
    if (!$this->errorHandler()->getErrors($form_state) && empty($form_state['rebuild'])) {
      // Check subscription and send a heartbeat to Acquia Network via XML-RPC.
      // Our status gets updated locally via the return data.

      $subscription_class = new Subscription();
      $subscription = $subscription_class->update();

      // Redirect to the path without the suffix.
      $form_state['redirect_route'] = new Url('acquia_connector.settings');

      if ($subscription['active']) {
        drupal_set_message($this->t('<h3>Connection successful!</h3>You are now connected to the Acquia Network.'));
      }
    }
  }

  /**
   * @param $form_state
   */
  protected function automaticStartSubmit(&$form_state) {
    $config = $this->config('acquia_connector.settings');

    if (empty($form_state['response']['subscription'])) {
      $this->setFormError('email', $form_state, $this->t('No subscriptions were found for your account.'));
    }
    elseif (count($form_state['response']['subscription']) > 1) {
      // Multistep form for choosing from available subscriptions.
      $form_state['choose'] = TRUE;
      $form_state['subscriptions'] = $form_state['response']['subscription'];
      // Force rebuild with next step.
      $form_state['rebuild'] = TRUE;
    }
    else {
      // One subscription so set id/key pair.
      $sub = $form_state['response']['subscription'][0];
      $config->set('key', $sub['key'])
        ->set('identifier', $sub['identifier'])
        ->set('subscription_name', $sub['name'])
        ->save();
    }
  }

}
