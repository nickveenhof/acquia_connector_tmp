<?php
namespace Drupal\acquia_connector\Tests;

use Drupal\acquia_connector\Migration;
use Drupal\Tests\UnitTestCase;



/**
 * Client Class unit tests.
 *
 * @ingroup acquia_connector
 * @group acquia_connector
 */
class MigrationTest extends UnitTestCase {

  protected $mockMigration;

  public function setUp() {
    parent::setUp();

    $this->mockMigration = $this->getMockBuilder('Drupal\acquia_connector\Migration')
      ->getMock();

    $this->mockMigration->expects($this->any())
      ->method('getUrl')
      ->with('admin/config/system/acquia-agent', array('absolute' => TRUE))
      ->will($this->returnValue('hello'));

  }

  /**
   * Tests Migration class.
   *
   * First create instance of Migration class.
   * Test the checkEnv method.
   * Test the processSetup method.
   * Test that checkEnv and process Setup return the same thing.
   */

  public function testMigration() {
    $migration = new Migration();
    $this->assertInstanceOf('Drupal\acquia_connector\Migration', $migration);

    $this->assertArrayHasKey('error', $migration->checkEnv());
    $this->assertArrayHasKey('error', $migration->processSetup());
    $this->assertEquals($migration->checkEnv(), $migration->processSetup());

    // @todo: Add tests once the Migration class is refactored to at least somewhat work.
  }

}
