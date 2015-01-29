<?php

/**
 * @file
 * Contains \Drupal\acquia_search\Tests\AcquiaSearchTest
 */

namespace Drupal\acquia_search\Tests;

use Drupal\acquia_search\EventSubscriber\SearchSubscriber;
use Drupal\Tests\UnitTestCase;

/**
 * Tests settings configuration of individual aggregator plugins.
 *
 * @group acquia_search
 */
class AcquiaSearchTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    // Include Solarium autoloader.
    require_once __DIR__ . '../../../../../search_api_solr/vendor/autoload.php';
  }

  /**
   * @covers ::createDerivedKey
   */
  public function testCreateDerivedKey() {
    $searchSubscriber = new SearchSubscriber();
    $this->assertEquals('bb691399304fe22102f524f3865a5b223781fce3', $searchSubscriber->createDerivedKey('1', '2', '3'));
  }

  /**
   * @covers ::calculateAuthCookie
   */
  public function testCalculateAuthCookie() {
    $searchSubscriber = $this->getMockBuilder('Drupal\acquia_search\EventSubscriber\SearchSubscriber')
      ->setMethods(['getDerivedKey'])
      ->getMock();
    $derivered_key = $searchSubscriber->createDerivedKey('1', '2', '3');
    $searchSubscriber->expects($this->any())
      ->method('getDerivedKey')
      ->willReturn($derivered_key);
    $this->assertEquals('acquia_solr_time=' . REQUEST_TIME . '; acquia_solr_nonce=2; acquia_solr_hmac=' . hash_hmac('sha1', REQUEST_TIME . '2' . '1', $derivered_key) . ';',
      $searchSubscriber->calculateAuthCookie('1', '2'));
  }
}
