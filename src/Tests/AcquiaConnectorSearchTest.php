<?php

/**
 * @file
 * Definition of Drupal\acquia_connector\Tests\AcquiaConnectorSearchTest.
 */

namespace Drupal\acquia_connector\Tests;

use Drupal\simpletest\WebTestBase;
use Drupal\acquia_search\EventSubscriber;
use Drupal\search_api\Entity\Server;
use Drupal\Core\Url;

/**
 * Tests the functionality of the Acquia Search module.
 */
class AcquiaConnectorSearchTest extends WebTestBase {
  protected $strictConfigSchema = FALSE;
  protected $id;
  protected $key;
  protected $salt;
  protected $derivedKey;
  protected $url;
  protected $server;
  protected $index;
  protected $settings_path;

  protected $acquia_search_environment_id = 'acquia_search';


  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('acquia_connector', 'search_api', 'search_api_solr', 'toolbar', 'acquia_connector_test', 'node', 'devel');


  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return array(
      'name' => 'Acquia Search UI tests',
      'description' => 'Tests the Acquia Search user interface and functionality.',
      'group' => 'Acquia',
    );
  }

  /**
   *
   *
   */
  public function setUp() {
    parent::setUp();
    // Generate and store a random set of credentials.
    $this->id = $this->randomString(10);
    $this->key = $this->randomString(32);
    $this->salt = $this->randomString(32);
    $this->server = 'acquia_search_server';
    $this->index = 'acquia_search_index';
    $this->settings_path = 'admin/config/search/search-api';

    //connect
    $this->connect();

    //$event_subscriber = new EventSubscriber\SearchSubscriber();
    //$this->derivedKey = $event_subscriber->createDerivedKey($this->salt, $this->id, $this->key);
    //$this->derivedKey = _acquia_search_create_derived_key($this->salt, $this->id, $this->key);
    $subscription = array(
      'timestamp' => REQUEST_TIME - 60,
      'active' => '1',
    );

    //variable_set('acquia_identifier', $this->id);
    //variable_set('acquia_key', $this->key);
    //variable_set('acquia_subscription_data', $subscription);
  }

  /**
   *
   *
   */
  public function connect(){
    \Drupal::configFactory()->getEditable('acquia_connector.settings')->set('spi.ssl_verify', FALSE)->save();
    \Drupal::configFactory()->getEditable('acquia_connector.settings')->set('spi.ssl_override', TRUE)->save();

    $admin_user = $this->createAdminUser();
    $this->drupalLogin($admin_user);

   /* $edit_fields = array(
      'acquia_identifier' => 'ACDW-66194',
      'acquia_key' => 'fbfc879b06195a0957f1cdf527a69463',
    );*/

    $edit_fields = array(
      'acquia_identifier' => $this->randomString(8),
      'acquia_key' => $this->randomString(8),
    );
    $submit_button = 'Connect';
    $this->drupalPostForm('admin/config/system/acquia-connector/credentials', $edit_fields, $submit_button);

    \Drupal::service('module_installer')->install(array('acquia_search'), array());
  }

  /**
   * Creates an admin user.
   */
  public function createAdminUser() {
    $permissions = array(
      'administer site configuration',
      'access administration pages',
      'access toolbar',
      'administer search_api',
    );
    return $this->drupalCreateUser($permissions);
  }

  /**
   * Creates an authenticated user that has access to search content.
   *
   * @return stdClass
   *   The user object of the authenticated user.
   *
   * @see DrupalWebTestCase::drupalCreateUser()
   */
  public function createAuthenticatedUser() {
    $permissions = array(
      'search content',
    );
    return $this->drupalCreateUser($permissions);
  }

  /**
   * Method to clear static caches that could interrupt with the
   * simpletest procedures for Acquia Search.
   *
  public function clearStaticCache() {
    // Reset the static to test for bug where default environment was only set
    // on the current page load. We want to ensure the setting persists.
    // @see http://drupal.org/node/1784804
    drupal_static_reset('apachesolr_load_all_environments');
    drupal_static_reset('apachesolr_default_environment');
  }

  /**
   * Enables the environment of Acquia Search and clears the static caches so
   * that the change is reflected in the API functions.
   *
  public function enableAcquiaSearchEnvironment() {
    // API function that creates the environemnt if it doesn't exist yet.
    acquia_search_enable_acquia_solr_environment();
    $this->clearStaticCache();
  }*/

  /**
   * Tests Acquia Search environment creation.
   *
   * Tests executed:
   * - Acquia Search environment is saved and loaded.
   * - Acquia Search environment is set as the default environment when created.
   * - The service class is set to AcquiaSearchService.
   * - The environment's URL is built as expected.
   */
  public function testEnvironment() {
    // Connect site on key and id.
   // $this->drupalGet('admin/config/search/search-api');
   // $environment =  Server::load('acquia_search_server');
    // Test all the things!
    // Check if the environment is a valid variable
 //   $this->assertTrue($environment, t('Acquia Search environment saved.'), 'Acquia Search');
    // Check if the url is the same as the one we wanted to save.
    //$this->assertEqual($this->url, $environment['url'], t('Acquia Search is connected to the expected URL.'), 'Acquia Search'); //@todo
  }



  /**
   * Tests that the Acquia Search environment shows up in the interface and that
   */
  public function testEnvironmentUI() {
    $this->drupalGet($this->settings_path);
    //Check the Acquia Search Server is displayed
    $this->assertLinkByHref('/admin/config/search/search-api/server/' . $this->server, 0, t('The Acquia Search Server is displayed in the UI.'));
    //Check the Acquia Search Index is displayed
    $this->assertLinkByHref('/admin/config/search/search-api/index/' . $this->index, 0, t('The Acquia Search Index is displayed in the UI.'));
  }

  /**
   *
   *
   */
  public function testServerUI() {
    $settings_path = 'admin/config/search/search-api';
    $this->drupalGet($settings_path);
    $this->clickLink('Edit', 0);

    //Server is enabled
    $this->assertNoFieldChecked('edit-status', t('Acquia Search Server enabled.'), 'Acquia Search');

    //check backend
    $this->assertText('Backend', 'The Backend checkbox label exists');
    $this->assertFieldChecked('edit-backend-search-api-solr-acquia', t('Is used as a backend  Acquia Solr'), 'Acquia Search');

    //check field Solr server URI
    $this->assertText('Solr server URI', 'The Solr server URI label exist');

    //check http-protocol
    $this->assertText('HTTP protocol', 'HTTP protocol');
    $this->assertOptionSelected('edit-backend-config-scheme', 'http', t('By default selected HTTP protocol'), 'Acquia Search');
    $this->assertText('Solr host', 'Solr host');
    $this->assertText('Solr port',  t('The Solr port label exist'), 'Acquia Search');
    $this->assertText('Solr path', t('The Solr path label exist'), 'Acquia Search');
    $this->assertText('Basic HTTP authentication', t('The basic HTTP authentication label exist'), 'Acquia Search');

    $this->assertText('Solr version override', t('The selectbox "Solr version label" exist'), 'Acquia Search');
    $this->assertOptionSelected('edit-backend-config-advanced-solr-version', '4', t('By default selected Solr version 4.x'), 'Acquia Search');

    $this->assertText('HTTP method', t('The HTTP method label exist'));
    $this->assertOptionSelected('edit-backend-config-advanced-http-method', 'AUTO', t('By default selected AUTO HTTP method'), 'Acquia Search');

  }
}
