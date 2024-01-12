<?php

namespace Drupal\Tests\recurlyjs\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests recurlyjs settings form.
 *
 * @group recurly
 */
class SettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['recurly', 'recurlyjs'];

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
   * Test the settings page.
   */
  public function testSettingsSections() {
    $this->drupalGet('/admin/config/services/recurly');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Recurly.js settings');
  }

  /**
   * Test for the presence of the settings fields.
   */
  public function testSettingsFields() {
    $this->drupalGet('/admin/config/services/recurly');
    $this->assertSession()->fieldExists('recurlyjs_enable_add_ons');
    $this->assertSession()->fieldExists('recurlyjs_enable_coupons');
    $this->assertSession()->fieldExists('recurlyjs_accept_paypal');
  }

  /**
   * Test settings form submission.
   */
  public function testSettingsFormSubmission() {
    $this->drupalGet('/admin/config/services/recurly');
    $this->submitForm([], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
  }

}
