<?php

namespace Drupal\Tests\recurly\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests recurly settings form.
 *
 * @group recurly
 */
class SettingsFormTest extends BrowserTestBase {

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
   * Test the settings page.
   */
  public function testSettingsSections() {
    $this->drupalGet('/admin/config/services/recurly');
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);

    $assert->pageTextContains('Default account settings');
    $assert->pageTextContains('Push notification settings');
    $assert->pageTextContains('Built-in subscription/invoice pages');
  }

  /**
   * Test account settings fields.
   */
  public function testAccountSettingsFields() {
    $this->drupalGet('/admin/config/services/recurly');
    $assert = $this->assertSession();
    $assert->fieldExists('edit-recurly-private-api-key');
    $assert->fieldExists('edit-recurly-public-key');
    $assert->fieldExists('edit-recurly-subdomain');
    $assert->fieldExists('recurly_default_currency');
    $option_field = $assert->optionExists('edit-recurly-default-currency', 'USD');
    $this->assertTrue($option_field->hasAttribute('selected'), 'Currency field defaults to USD.');
  }

  /**
   * Test push notification settings fields.
   */
  public function testPushNotificationSettingFields() {
    $this->drupalGet('/admin/config/services/recurly');
    $assert = $this->assertSession();
    $assert->fieldExists('edit-recurly-listener-key', NULL);
    $assert->fieldExists('edit-recurly-push-logging', NULL);
  }

  /**
   * Test account sync settings fields.
   */
  public function testAccountSyncSettingsFields() {
    $this->drupalGet('/admin/config/services/recurly');
    $assert = $this->assertSession();
    $assert->fieldExists('recurly_entity_type');
    $option_field = $assert->optionExists('edit-recurly-entity-type', 'user');
    $this->assertTrue($option_field->hasAttribute('selected'), 'Entity type defaults to user.');
    $assert->fieldExists('edit-recurly-token-mapping-email');
    $assert->fieldValueEquals('edit-recurly-token-mapping-email', '[user:mail]');
    $assert->fieldExists('edit-recurly-token-mapping-username');
    $assert->fieldValueEquals('edit-recurly-token-mapping-username', '[user:name]');
  }

  /**
   * Test Recurly pages settings fields.
   */
  public function testRecurlyPagesSettingsFields() {
    $this->drupalGet('/admin/config/services/recurly');
    $assert = $this->assertSession();
    $assert->fieldExists('recurly_pages');
    $assert->checkboxChecked('edit-recurly-pages');

    $assert->fieldExists('recurly_coupon_page');
    $assert->checkboxChecked('edit-recurly-coupon-page');

    $assert->fieldExists('recurly_subscription_display');
    $assert->fieldExists('edit-recurly-subscription-display-live');
    $assert->fieldExists('edit-recurly-subscription-display-all');
    $assert->checkboxChecked('edit-recurly-subscription-display-live');

    $assert->fieldExists('recurly_subscription_max');
    $assert->fieldExists('edit-recurly-subscription-max-1');
    $assert->fieldExists('edit-recurly-subscription-max-0');
    $assert->checkboxChecked('edit-recurly-subscription-max-1');

    $assert->fieldExists('recurly_subscription_upgrade_timeframe');
    $assert->fieldExists('edit-recurly-subscription-upgrade-timeframe-now');
    $assert->fieldExists('edit-recurly-subscription-upgrade-timeframe-renewal');
    $assert->checkboxChecked('edit-recurly-subscription-upgrade-timeframe-now');

    $assert->fieldExists('recurly_subscription_downgrade_timeframe');
    $assert->fieldExists('edit-recurly-subscription-downgrade-timeframe-now');
    $assert->fieldExists('edit-recurly-subscription-downgrade-timeframe-renewal');
    $assert->checkboxChecked('edit-recurly-subscription-downgrade-timeframe-renewal');

    $assert->fieldExists('recurly_subscription_cancel_behavior');
    $assert->fieldExists('edit-recurly-subscription-cancel-behavior-cancel');
    $assert->fieldExists('edit-recurly-subscription-cancel-behavior-terminate-prorated');
    $assert->fieldExists('edit-recurly-subscription-cancel-behavior-terminate-full');
    $assert->checkboxChecked('edit-recurly-subscription-cancel-behavior-cancel');
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
