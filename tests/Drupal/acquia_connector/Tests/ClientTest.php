<?php
namespace Drupal\acquia_connector\Tests;

use Drupal\acquia_connector\Client;
use Drupal\Tests\UnitTestCase;


/**
 * Client Class unit tests.
 *
 * @ingroup acquia_connector
 * @group acquia_connector
 */
class ClientTest extends UnitTestCase {

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit_Framework_MockObject_MockObject
   */
  protected $configFactory;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit_Framework_MockObject_MockObject
   */
  protected $clientInterface;

  /**
   * The config data.
   *
   * @var array (configuration data)
   */
  public $configData;


  public function setUp() {
    parent::setUp();

    $this->configData = array(
      'network_address' => 'https://rpc.acquia.com',
      'active' => FALSE,
      'ah' => array(
        'network' => array(
          'key' => '',
          'identifier' => '',
        ),
      ),
    );

    $config = $this->getMockBuilder('Drupal\Core\Config\Config')
      ->disableOriginalConstructor()
      ->getMock();
    $config->expects($this->at(0))
      ->method('get')
      ->with('network_address')
      ->willReturn($this->returnValue($this->getAddress()));

    $this->configFactory = $this->getMock('Drupal\Core\Config\ConfigFactoryInterface');
    $this->configFactory->expects($this->once())
      ->method('get')
      ->with('acquia_connector.settings')
      ->will($this->returnValue($config));

    $this->clientInterface = $this->getMockBuilder('GuzzleHttp\ClientInterface')
      ->getMock();

  }

  /**
   * Tests Client class.
   *
   * First create instance of Client class.
   * Checks that the object was created correctly and contains wanted attributes.
   */
  public function testClient() {
    $client = new Client($this->clientInterface, $this->configFactory);
    $this->assertInstanceOf('Drupal\acquia_connector\Client', $client);
    $this->assertObjectHasAttribute('server', $client);
    $this->assertObjectHasAttribute('config', $client);

    $this->assertTrue($client->validateCredentials($this->getNetworkId(), $this->getKey()));
    $body = array();
    $this->assertFalse($client->getSubscription($this->getNetworkId(), $this->getKey(), $body));

  }

  /**
   * Retrieve the network identifier.
   */
  private function getNetworkId() {
    return $this->configData['ah']['network']['identifier'];
  }

  /**
   * Retrieve the network key.
   */
  private function getKey() {
    return $this->configData['ah']['network']['key'];
  }

  /**
   * Retrieve the network address.
   */
  private function getAddress() {
    return $this->configData['network_address'];
  }
}
