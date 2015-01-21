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
  public static $modules = array('acquia_connector', 'toolbar', 'devel', 'acquia_connector_test'); //@todo devel

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
    $this->credentials_path = 'admin/config/system/acquia-agent/credentials';
    $this->settings_path = 'admin/config/system/acquia-agent';
    $this->status_report_url = 'admin/reports/status';
  }

  public function testAcquiaSPIUI() {
    
  }
}