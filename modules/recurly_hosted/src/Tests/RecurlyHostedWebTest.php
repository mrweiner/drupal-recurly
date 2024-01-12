<?php

namespace Drupal\recurly_hosted\Tests;

use Drupal\Tests\recurly\Functional\RecurlyBrowserTestBase;

/**
 * Tests recurly hosted pages.
 *
 * @group recurly
 */
class RecurlyHostedWebTest extends RecurlyBrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['recurly_hosted'];

  /**
   * Test the settings page.
   */
  public function testAnonymousUpdateBillingAccess() {
    $this->drupalGet('/user/1/subscription/billing');
    $this->assertSession()->statusCodeEquals(403);
  }

}
