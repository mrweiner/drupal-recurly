<?php

namespace Drupal\Tests\recurly\Kernel;

use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\recurly\RecurlyPagerManager;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Tests for RecurlyPagerManager.
 *
 * @covers \Drupal\recurly\RecurlyPagerManager
 * @group recurly
 */
class RecurlyPagerManagerTest extends KernelTestBase {

  use ProphecyTrait;
  /**
   * Recurly pager object that mocks some features.
   *
   * @var \Recurly_Pager
   */
  protected $mockRecurlyPager;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['recurly', 'system'];

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();

    $this->mockRecurlyPager = new class extends \Recurly_Pager {

      /**
       * Mock count.
       */
      public function count() {
        return 25;
      }

      /**
       * Mock setObject.
       */
      public function setObject($object) {
        $this->_objects[] = $object;
      }

    };

    for ($i = 1; $i <= 25; $i++) {
      $resourceMock = $this->prophesize(\Recurly_Resource::class);
      $resourceMock->uuid = $i;
      $this->mockRecurlyPager->setObject($resourceMock->reveal());
    }
  }

  /**
   * Tests for pager service.
   *
   * @covers \Drupal\recurly\RecurlyPagerManager::pagerResults
   */
  public function testPagerResults() {
    // Mock the Drupal pager service. And ensure that it's called from within
    // the recurly pager service to initialize a pager for theming.
    $mockDrupalPagerService = $this->prophesize(PagerManagerInterface::class);
    $mockDrupalPagerService->findPage()
      ->willReturn(0)
      ->shouldBeCalled();
    $mockDrupalPagerService->createPager(
        Argument::type('int'),
        Argument::type('int')
      )
      ->shouldBeCalled();

    $pager = new RecurlyPagerManager($mockDrupalPagerService->reveal());

    $per_page = 5;
    $result = $pager->pagerResults($this->mockRecurlyPager, $per_page);

    // Verify we got the desired items.
    $this->assertCount($per_page, $result);
    // And that it's the first 5 items.
    $this->assertEquals([1, 2, 3, 4, 5], array_keys($result));

    // Advance the pager.
    $result = $pager->pagerResults($this->mockRecurlyPager, $per_page, 1);
    $this->assertCount($per_page, $result);
    // Verify that it's the second 5 items.
    $this->assertEquals([6, 7, 8, 9, 10], array_keys($result));
  }

}
