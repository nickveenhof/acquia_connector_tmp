<?php

/**
 * @file
 * Definition of Drupal\acquia_connector\Tests\AcquiaConnectorSpiTest.
 */

namespace Drupal\acquia_connector\Tests;

use Drupal\simpletest\WebTestBase;

/**
 * Tests the functionality of the Acquia SPI module.
 */
class AcquiaConnectorSpiTest extends WebTestBase{
  protected $strictConfigSchema = FALSE;
  protected $privileged_user;
  protected $setup_path;
  protected $credentials_path;
  protected $settings_path;
  protected $status_report_url;
  protected $acqtest_email = 'TEST_networkuser@example.com';
  protected $acqtest_pass = 'TEST_password';
  protected $acqtest_id =  'TEST_AcquiaConnectorTestID';
  protected $acqtest_key = 'TEST_AcquiaConnectorTestKey';
  protected $acqtest_expired_id = 'TEST_AcquiaConnectorTestIDExp';
  protected $acqtest_expired_key = 'TEST_AcquiaConnectorTestKeyExp';
  protected $acqtest_503_id = 'TEST_AcquiaConnectorTestID503';
  protected $acqtest_503_key = 'TEST_AcquiaConnectorTestKey503';
  protected $acqtest_error_id = 'TEST_AcquiaConnectorTestIDErr';
  protected $acqtest_error_key = 'TEST_AcquiaConnectorTestKeyErr';

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('acquia_connector', 'toolbar', 'devel', 'acquia_connector_test', 'node'); //@todo devel node(function getQuantum() 1101 line)

  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return array(
      'name' => 'Acquia SPI ',
      'description' => 'Test sending Acquia SPI data.',
      'group' => 'Acquia',
    );
  }

  public function setUp() {
    parent::setUp();
    //base url
    global $base_url;
    // Enable any modules required for the test
    // Create and log in our privileged user.
    $this->privileged_user = $this->drupalCreateUser(array(
      'administer site configuration',
      'access administration pages',
    ));
    $this->drupalLogin($this->privileged_user);

    // Setup variables.
    $this->credentials_path = 'admin/config/system/acquia-connector/credentials';
    $this->settings_path = 'admin/config/system/acquia-connector';
    $this->status_report_url = 'admin/reports/status';

    //local env
    \Drupal::config('acquia_connector.settings')->set('network_address', 'http://drupal-alerts.local:8083/')->save();
    \Drupal::config('acquia_connector.settings')->set('spi.server', 'http://drupal-alerts.local:8083/')->save();
    \Drupal::config('acquia_connector.settings')->set('spi.ssl_verify', FALSE)->save();
    \Drupal::config('acquia_connector.settings')->set('spi.ssl_override', TRUE)->save();
  }


  /**
   * Helper function for storing UI strings.
   */
  private function acquiaSPIStrings($id) {
    switch ($id) {
      case 'spi-status-text':
        return 'SPI data will be sent once every 30 minutes once cron is called';
      case 'spi-not-sent';
        return 'SPI data has not been sent';
      case 'spi-send-text';
        return 'manually send SPI data';
      case 'spi-data-sent':
        return 'SPI data sent';
      case 'spi-data-sent-error':
        return 'Error sending SPI data. Consult the logs for more information.';
      case 'spi-new-def':
        return 'There are new checks that will be performed on your site by the Acquia Connector';
    }
  }

  public function testAcquiaSPIUI() {
    $this->drupalGet($this->status_report_url);
    $this->assertNoText($this->acquiaSPIStrings('spi-status-text'), 'SPI send option does not exist when site is not connected');
    // Connect site on key and id that will error.
    $edit_fields = array(
      'acquia_identifier' => $this->acqtest_error_id,
      'acquia_key' => $this->acqtest_error_key,
    );
    $submit_button = 'Connect';
    $this->drupalPostForm($this->credentials_path, $edit_fields, $submit_button);
    // Send SPI data.
    $this->drupalGet($this->status_report_url);
    $this->assertText($this->acquiaSPIStrings('spi-status-text'), 'SPI explanation text exists');
    $this->clickLink($this->acquiaSPIStrings('spi-send-text'));
    $this->assertNoText($this->acquiaSPIStrings('spi-data-sent'), 'SPI data was not sent');
    $this->assertText($this->acquiaSPIStrings('spi-data-sent-error'), 'Page says there was an error sending data');

    // Connect site on non-error key and id.
    $this->connectSite();
    // Send SPI data.
    $this->drupalGet($this->status_report_url);
    $this->clickLink($this->acquiaSPIStrings('spi-send-text'));
    $this->assertText($this->acquiaSPIStrings('spi-data-sent'), 'SPI data was sent');
    $this->assertNoText($this->acquiaSPIStrings('spi-not-sent'), 'SPI does not say "data has not been sent"');
  }

  /**
   * Helper function connects to valid subscription.
   */
  protected function connectSite() {
    $edit_fields = array(
      'acquia_identifier' => $this->acqtest_id,
      'acquia_key' => $this->acqtest_key,
    );
    $submit_button = 'Connect';
    $this->drupalPostForm($this->credentials_path, $edit_fields, $submit_button);
  }
}