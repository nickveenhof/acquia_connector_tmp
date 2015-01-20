<?php

/**
 * @file
 * Definition of Drupal\acquia_connector\Tests\ConnectorTest.
 */

namespace Drupal\acquia_connector\Tests;

use Drupal\simpletest\WebTestBase;
use Drupal\acquia_connector\Subscription;
use GuzzleHttp\Subscriber\History;

/**
 * Tests the functionality of the Acquia Connector module.
 */
class ConnectorTest extends WebTestBase{
  protected $strictConfigSchema = FALSE;

  protected $acqtest_email = 'TEST_networkuser@example.com';
  protected $acqtest_pass = 'TEST_password';
  protected $acqtest_id =  'TEST_AcquiaConnectorTestID';
  protected $acqtest_key = 'TEST_AcquiaConnectorTestKey';
  protected $acqtest_expired_id = 'TEST_AcquiaConnectorTestIDExp';
  protected $acqtest_expired_key = 'TEST_AcquiaConnectorTestKeyExp';
  protected $acqtest_503_id = 'TEST_AcquiaConnectorTestID503';
  protected $acqtest_503_key = 'TEST_AcquiaConnectorTestKey503';

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('acquia_connector', 'toolbar', 'devel', 'acquia_connector_test');

  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return array(
      'name' => 'Acquia Connector UI & Connection',
      'description' => 'Test Acquia Connector UI and connecting to Acquia Insight.',
      'group' => 'Acquia',
    );
  }

  /**
   *{@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    // Create and log in our privileged user.
    $this->privileged_user = $this->drupalCreateUser(array(
      'administer site configuration',
      'access administration pages',
      'access toolbar',
    ));
    $this->drupalLogin($this->privileged_user);
    // Create a user that has a Network subscription.
    $this->network_user = $this->drupalCreateUser();
    $this->network_user->mail = $this->acqtest_email;
    $this->network_user->pass = $this->acqtest_pass;
    $this->network_user->save();
    //$this->drupalLogin($this->network_user);
    //Setup variables.
    $this->cloud_free_url = 'https://www.acquia.com/acquia-cloud-free';
    $this->setup_path = 'admin/config/system/acquia-connector/setup';
    $this->credentials_path = 'admin/config/system/acquia-connector/credentials';
    $this->settings_path = 'admin/config/system/acquia-connector';

    //nspi.dev
    /*\Drupal::config('acquia_connector.settings')->set('spi.server', 'http://nspi.acquia.dev')->save();
    \Drupal::config('acquia_connector.settings')->set('spi.ssl_verify', FALSE)->save();
    \Drupal::config('acquia_connector.settings')->set('spi.ssl_override', TRUE)->save();*/

    //local
    \Drupal::config('acquia_connector.settings')->set('network_address', 'http://drupal-alerts.local:8083/')->save();
    \Drupal::config('acquia_connector.settings')->set('spi.server', 'http://drupal-alerts.local:8083/')->save();
    \Drupal::config('acquia_connector.settings')->set('spi.ssl_verify', FALSE)->save();
    \Drupal::config('acquia_connector.settings')->set('spi.ssl_override', TRUE)->save();
  }

  /**
   * Helper function for storing UI strings.
   */
  private function acquiaConnectorStrings($id) {
    switch ($id) {
      case 'free':
        return 'Sign up for Acquia Cloud Free, a free Drupal sandbox to experiment with new features, test your code quality, and apply continuous integration best practices.';
      case 'get-connected':
        return 'If you have an Acquia Network subscription, connect now. Otherwise, you can turn this message off by disabling the Acquia Network modules.';
      case 'enter-email':
        return 'Enter the email address you use to login to the Acquia Network';
      case 'enter-password':
        return 'Enter your Acquia Network password';
      case 'account-not-found':
        return 'Account not found'; //@todo
      case 'id-key':
        return 'Enter your identifier and key from your subscriptions overview or log in to connect your site to the Acquia Network.';
      case 'enter-key':
        return 'Network key';
      case 'subscription-not-found':
        return 'Error: Subscription not found (1000)'; //@todo
      case 'saved':
        return 'The Acquia configuration options have been saved.';
      case 'subscription':
        return 'Subscription: ' . $this->acqtest_id; // Assumes subscription name is same as id. @todo
      case 'migrate':
        return 'Transfer a fully-functional copy of your site to Acquia Cloud.';
      case 'migrate-hosting-404':
        return 'Error: Hosting not available under your subscription. Upgrade your subscription to continue with import.';
      case 'migrate-select-environments':
        return 'Select environment for migration';
      case 'migrate-files-label':
        return 'Migrate files directory';
      case 'menu-active':
        return 'Subscription active (expires 2023/10/8)';
      case 'menu-inactive':
        return 'Subscription not active';
    }
  }


  public function testAcquiaConnectorGetConnected() {
    // Check for call to get connected.
    $this->drupalGet('admin');
    $this->assertText($this->acquiaConnectorStrings('free'), 'The explanation of services text exists');
    $this->assertLinkByHref($this->cloud_free_url, 0, 'Link to Acquia.com Cloud Services exists');
    $this->assertText($this->acquiaConnectorStrings('get-connected'), 'The call-to-action to connect text exists');
    $this->assertLink('connect now', 0, 'The "connect now" link exists');

    // Check connection setup page. --------------------------------------------------------------
    $this->drupalGet($this->setup_path);
    $this->assertText($this->acquiaConnectorStrings('enter-email'), 'The email address field label exists');
    $this->assertText($this->acquiaConnectorStrings('enter-password'), 'The password field label exists');
    $this->assertLinkByHref($this->cloud_free_url, 0, 'Link to Acquia.com free signup exists');

    // Check errors on automatic setup page.
    $edit_fields = array(
      'email' => $this->randomString(),
      'pass' => $this->randomString(),
    );
    $submit_button = 'Next';
    $this->drupalPostForm($this->setup_path, $edit_fields, $submit_button);
    $this->assertText($this->acquiaConnectorStrings('account-not-found'), 'Account not found for random automatic setup attempt');
    $this->assertText($this->acquiaConnectorStrings('menu-inactive'), 'Subscription not active menu message appears');

    // Check manual connection. ------------------------------------------------------------------
    $this->drupalGet($this->credentials_path);
    $this->assertText($this->acquiaConnectorStrings('id-key'), 'The network key and id description exists');
    $this->assertText($this->acquiaConnectorStrings('enter-key'), 'The network key field label exists');
    $this->assertLinkByHref($this->cloud_free_url, 0, 'Link to Acquia.com free signup exists');

    // Check errors on connection page.
    $edit_fields = array(
      'acquia_identifier' => $this->randomString(),
      'acquia_key' => $this->randomString(),
    );
    $submit_button = 'Connect';
    $this->drupalPostForm($this->credentials_path, $edit_fields, $submit_button);
    $this->assertText($this->acquiaConnectorStrings('subscription-not-found'), 'Subscription not found for random credentials');
    $this->assertText($this->acquiaConnectorStrings('menu-inactive'), 'Subscription not active menu message appears');

    // Connect site on key and id.
    $edit_fields = array(
      'acquia_identifier' => $this->acqtest_id,
      'acquia_key' => $this->acqtest_key,
    );
    $submit_button = 'Connect';
    $this->drupalPostForm($this->credentials_path, $edit_fields, $submit_button);
    $this->drupalGet($this->settings_path);
    $this->assertText($this->acquiaConnectorStrings('subscription'), 'Subscription connected with key and identifier');
    $this->assertLinkByHref($this->setup_path, 0, 'Link to change subscription exists');
    $this->assertText($this->acquiaConnectorStrings('migrate'), 'Acquia Cloud Migrate description exists');

    // Connect via automatic setup.
    \Drupal::config('acquia_connector.settings')->clear('identifier')->save();
    \Drupal::config('acquia_connector.settings')->clear('key')->save();
    $edit_fields = array(
      'email' => $this->acqtest_email,
      'pass' => $this->acqtest_pass,
    );
    $submit_button = 'Next';
    $this->drupalPostForm($this->setup_path, $edit_fields, $submit_button);
    $this->drupalGet($this->setup_path);
    $this->drupalGet($this->settings_path);
    $this->assertText($this->acquiaConnectorStrings('subscription'), 'Subscription connected with credentials');
    // Confirm menu reports active subscription.
    $this->drupalGet('admin');
    $this->assertText($this->acquiaConnectorStrings('menu-active'), 'Subscription active menu message appears');
    // Test dynamic banner.
    $edit_fields = array(
      'acquia_dynamic_banner' => TRUE,
    );
    $submit_button = 'Save configuration';
    $this->drupalPostForm($this->settings_path, $edit_fields, $submit_button);
    $this->assertFieldChecked('edit-acquia-dynamic-banner', '"Receive updates from Acquia" option stays saved');
  }

  /**
   * Test Agent subscription methods.
   */
  public function testAcquiaConnectorSubscription(){
    // Starts as inactive.
    $subscription = new Subscription();
    $is_active = $subscription->isActive();
    $this->assertFalse($is_active, 'Subscription is not currently active.');
    // Confirm HTTP request count is 0 because without credentials no request
    // should have been made.
    $this->assertIdentical(\Drupal::state()->get('acquia_connector_test_request_count', 0), 0);
    $check_subscription  = $subscription->update();
    $this->assertFalse($check_subscription, 'Subscription is currently false.');
    // Confirm HTTP request count is still 0.
    $this->assertIdentical(\Drupal::state()->get('acquia_connector_test_request_count', 0), 0);

    // Fail a connection.
    $random_id = $this->randomString();
    $edit_fields = array(
      'acquia_identifier' => $random_id,
      'acquia_key' => $this->randomString(),
    );
    $submit_button = 'Connect';
    $this->drupalPostForm($this->credentials_path, $edit_fields, $submit_button);

    // Confirm HTTP request count is 1.
    $this->assertIdentical(\Drupal::state()->get('acquia_connector_test_request_count', 0), 1, 'Made 1 HTTP request in attempt to connect subscription.');
    $is_active = $subscription->isActive();
    $this->assertFalse($is_active, 'Subscription is not active after failed attempt to connect.');
    $this->assertIdentical(\Drupal::state()->get('acquia_connector_test_request_count', 0), 1, 'Still have made only 1 HTTP request');
    $check_subscription  = $subscription->update();
    $this->assertFalse($check_subscription, 'Subscription is false after failed attempt to connect.');
    $this->assertIdentical(\Drupal::state()->get('acquia_connector_test_request_count', 0), 1, 'Still have made only 1 HTTP request');
    // Test default from acquia_agent_settings().
    $stored = \Drupal::config('acquia_connector.settings');
    $current_subscription = $stored->get('subscription_data');
    // Not identical since acquia_agent_has_credentials() causes stored to be
    // deleted.
    $this->assertNotIdentical($check_subscription, $current_subscription, 'Stored subscription data not same before connected subscription.');
    $this->assertTrue($current_subscription['active'] === FALSE, 'Default is inactive.');

    // Reset HTTP request counter;
    \Drupal::state()->set('acquia_connector_test_request_count', 0);

    // Connect.
    $edit_fields = array(
      'acquia_identifier' => $this->acqtest_id,
      'acquia_key' => $this->acqtest_key,
    );
    $this->drupalPostForm($this->credentials_path, $edit_fields, $submit_button);
    // HTTP requests should now be 3 (acquia.agent.subscription.name and
    //acquia.agent.subscription and acquia.agent.validate.
    $this->assertIdentical(\Drupal::state()->get('acquia_connector_test_request_count', 0), 3, '3 HTTP requests were made during first connection.');
    $is_active = $subscription->isActive();
    $this->assertTrue($is_active, 'Subscription is active after successful connection.');
    $check_subscription  = $subscription->update();
    $this->assertTrue(is_array($check_subscription), 'Subscription is array after successful connection.');

    // Now stored subscription data should match.
    $stored = \Drupal::config('acquia_connector.settings');
    $current_subscription = $stored->get('subscription_data');
    $this->assertIdentical($check_subscription, $current_subscription, 'Stored expected subscription data.');
    $this->assertIdentical(\Drupal::state()->get('acquia_connector_test_request_count', 0), 4, '1 additional HTTP request made via acquia_agent_check_subscription().');
    $this->drupalGet('/');
    $this->drupalGet('admin');
    $this->assertIdentical(\Drupal::state()->get('acquia_connector_test_request_count', 0), 4, 'No extra requests made during visits to other pages.');

    // Reset HTTP request counter;
    \Drupal::state()->set('acquia_connector_test_request_count', 0);
    // Connect on expired subscription.
    $edit_fields = array(
      'acquia_identifier' => $this->acqtest_expired_id,
      'acquia_key' => $this->acqtest_expired_key,
    );
    $this->drupalPostForm($this->credentials_path, $edit_fields, $submit_button);
    $this->assertIdentical(\Drupal::state()->get('acquia_connector_test_request_count', 0), 3, '3 HTTP requests were made during expired connection attempt.');
    $is_active = $subscription->isActive();
    $this->assertFalse($is_active, 'Subscription is not active after connection with expired subscription.');
    $this->assertIdentical(\Drupal::state()->get('acquia_connector_test_request_count', 0), 3, 'No additional HTTP requests made via acquia_agent_subscription_is_active().');
    $this->drupalGet('/');
    $this->drupalGet('admin');
    $this->assertIdentical(\Drupal::state()->get('acquia_connector_test_request_count', 0), 3, 'No HTTP requests made during visits to other pages.');

    // Stored subscription data will now be the expired integer.
    $check_subscription  = $subscription->update();
    $this->assertIdentical($check_subscription, 1200, 'Subscription is expired after connection with expired subscription.');
    $this->assertIdentical(\Drupal::state()->get('acquia_connector_test_request_count', 0), 4, '1 additional request made via acquia_agent_check_subscription().');
    $stored = \Drupal::config('acquia_connector.settings');
    $current_subscription = $stored->get('subscription_data');
    $this->assertIdentical($check_subscription, $current_subscription, 'Stored expected subscription data.');

    // Reset HTTP request counter;
    \Drupal::state()->set('acquia_connector_test_request_count', 0);
    // Connect on subscription that will trigger a 503 response..
    $edit_fields = array(
      'acquia_identifier' => $this->acqtest_503_id,
      'acquia_key' => $this->acqtest_503_key,
    );
    $this->drupalPostForm($this->credentials_path, $edit_fields, $submit_button);
    $is_active = $subscription->isActive();
    $this->assertTrue($is_active, 'Subscription is active after successful connection.');
    // Hold onto subcription data for comparison.
    $stored = \Drupal::config('acquia_connector.settings');
    $current_subscription = $stored->get('subscription_data');
    // Make another request which will trigger 503 server error.
    $check_subscription  = $subscription->update();
    $this->assertNotIdentical($check_subscription, '503', 'Subscription is not storing 503.');
    $this->assertTrue(is_array($check_subscription), 'Storing subscription array data.');
    $this->assertIdentical($current_subscription, $check_subscription, 'Subscription data is the same.');
    $this->assertIdentical(\Drupal::state()->get('acquia_connector_test_request_count', 0), 4, 'Have made 4 HTTP requests so far.');
    $this->verbose(print_R($current_subscription, TRUE));
    $this->verbose(print_R($check_subscription, TRUE));
  }

}