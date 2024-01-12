<?php

namespace Drupal\Tests\recurly\Functional;

use Drupal\recurly\RecurlyClientFactory;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests recurly subscription plans form.
 *
 * @group recurly
 */
class SubscriptionPlansFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['recurly'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Admin user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();

    $permissions = [
      'administer recurly',
    ];
    $this->adminUser = $this->createUser($permissions);
    $this->drupalLogin($this->adminUser);
  }

  /**
   * Test settings form submission.
   */
  public function testPlanFormWithoutCredentials() {
    $this->drupalGet('/admin/config/services/recurly/subscription-plans');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains(RecurlyClientFactory::ERROR_MESSAGE_MISSING_API_KEY);
  }

}
