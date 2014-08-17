<?php
  namespace Drupal\acquia_connector\Tests;

  use Drupal\acquia_connector\Connector;
  use Drupal\Tests\UnitTestCase;

/**
 * Connector Class unit tests.
 *
 * @ingroup acquia_connector
 * @group acquia_connector
 */
class ConnectorTest extends UnitTestCase {

  public function setUp() {
      parent::setUp();
  }

  /**
   * Tests Connector class.
   *
   * First create instance of Connector class.
   * Checks that getSubscription method returns correct type.
   */
  public function testConnector() {
    $connector = new Connector();
    $this->assertInstanceOf('Drupal\acquia_connector\Connector', $connector);
    $this->assertInstanceOf('Drupal\acquia_connector\Subscription', $connector->getSubscription());

  }

}
