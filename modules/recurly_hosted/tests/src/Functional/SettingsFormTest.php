<?php

namespace Drupal\Tests\recurly_hosted\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests recurly hosted pages settings form.
 *
 * @group recurly
 */
class SettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['recurly', 'recurly_hosted'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Test the settings page.
   */
  public function testAnonymousUpdateBillingAccess() {
    $this->drupalGet('/user/1/subscription/billing');
    $this->assertSession()->statusCodeEquals(403);
  }

}
