<?php

namespace Drupal\Tests\recurly\Functional;

use Drupal\recurly_test_client\RecurlyMockClient;

/**
 * Tests ability to change a recurly subscription plan.
 *
 * @group recurly
 */
class SubscriptionChangeTest extends RecurlyBrowserTestBase {

  /**
   * User with subscription to silver plan.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $user;

  /**
   * Current subscription object.
   *
   * @var \Recurly_Subscription
   */
  protected $subscription;

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();
    $this->drupalPlaceBlock('local_tasks_block');
    $this->drupalPlaceBlock('system_messages_block');

    // Enable both silver and bedrock mock plans.
    $this->config('recurly.settings')
      ->set('recurly_subscription_plans', [
        'silver' => [
          'status' => 1,
          'weight' => 0,
        ],
        'bedrock' => [
          'status' => 1,
          'weight' => 1,
        ],
      ])
      ->save();

    // Create a user with a subscription to the silver plan.
    $this->user = $this->createUserWithSubscription();
    // Get current subscription object.
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=200', 'subscriptions/index-200-single.xml');
    $recurly_account = recurly_account_load([
      'entity_type' => $this->user->bundle(),
      'entity_id' => $this->user->id(),
    ], TRUE);
    $subscriptions = recurly_account_get_subscriptions($recurly_account->account_code, 'active');
    /** @var \Recurly_Subscription $subscription */
    $this->subscription = reset($subscriptions);

    $this->drupalLogin($this->user);
  }

  /**
   * Test proper error handling for routes.
   *
   * @covers \Drupal\recurly\Controller\RecurlySubscriptionChangeController::changePlan
   */
  public function testRoutingErrorHandling() {
    RecurlyMockClient::clear();
    RecurlyMockClient::addResponse('GET', '/subscriptions/' . $this->subscription->uuid, 'subscriptions/show-200.xml');

    $this->drupalGet('/user/' . $this->user->id() . '/subscription/id/BAD-UUID/change/bedrock');
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextContains('Subscription not found');

    // Test loading old plan code.
    // The old plan code is the plan associated with the current subscription,
    // which for our mock subscription is the "silver" plan.
    RecurlyMockClient::addResponse('GET', '/plans/silver', 'plans/missing-404.xml');
    $this->drupalGet('/user/' . $this->user->id() . '/subscription/id/' . $this->subscription->uuid . '/change/bedrock');
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextContains('Plan code "silver" not found');

    // Test loading bad new plan code.
    // The new plan code is specified in the URL when changing plans.
    RecurlyMockClient::addResponse('GET', '/plans/silver', 'plans/show-200.xml');
    RecurlyMockClient::addResponse('GET', '/plans/bad-plan-code', 'plans/missing-404.xml');
    $this->drupalGet('/user/' . $this->user->id() . '/subscription/id/' . $this->subscription->uuid . '/change/bad-plan-code');
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextContains('Plan code "bad-plan-code" not found');
  }

  /**
   * Test ability for user to see plans they can change too.
   */
  public function testChangePlanList() {
    RecurlyMockClient::clear();
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890', 'accounts/show-200.xml');
    RecurlyMockClient::addResponse('GET', '/plans', 'plans/index-200.xml');
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=200', 'subscriptions/index-200-single.xml');

    $this->drupalGet('/user/' . $this->user->id() . '/subscription/change');
    $this->assertSession()->pageTextContains('Change plan');
    $this->assertSession()
      ->elementTextContains('css', '.plan-silver', 'Selected');
    $this->assertSession()
      ->elementTextContains('css', '.plan-bedrock', 'Select');
  }

  /**
   * Upgrade to a more expensive plan immediately.
   *
   * This one also confirms the logic for a plan change. The other tests just
   * verify wording on the confirmation form since the logic after submission
   * is the same regardless.
   *
   * @covers \Drupal\recurly\Form\RecurlySubscriptionChangeConfirmForm
   * @covers \Drupal\recurly\Form\RecurlySubscriptionChangeConfirmForm::submitForm
   * @covers \Drupal\recurly\Controller\RecurlySubscriptionChangeController::changePlan
   */
  public function testUpgradeImmediate() {
    RecurlyMockClient::clear();
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890', 'accounts/show-200.xml');
    RecurlyMockClient::addResponse('GET', '/subscriptions/' . $this->subscription->uuid, 'subscriptions/show-200.xml');
    RecurlyMockClient::addResponse('GET', '/plans/silver', 'plans/show-200.xml');
    RecurlyMockClient::addResponse('GET', '/plans/bedrock', 'plans/show-200-bedrock.xml');
    // When the plan change happens you get redirected to user/ID/subscriptions
    // which result in a bunch of extra requests getting made.
    RecurlyMockClient::addResponse('HEAD', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', 'subscriptions/head-200-single.xml');
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', 'subscriptions/index-200-single.xml');
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=200', 'subscriptions/index-200-single.xml');
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?state=past_due', 'subscriptions/empty-200.xml');

    $this->config('recurly.settings')
      ->set('recurly_subscription_upgrade_timeframe', 'now')
      ->save();

    $this->drupalGet('/user/' . $this->user->id() . '/subscription/id/' . $this->subscription->uuid . '/change/bedrock');
    $this->assertSession()->pageTextContains('The new plan will take effect immediately and a prorated charge (or credit) will be applied to this account.');

    // Verify an error is displayed if we get no response from Recurly.
    $this->getSession()->getPage()->pressButton('Change plan');
    $this->assertTrue(RecurlyMockClient::assertRequestMade('PUT', '/subscriptions/' . $this->subscription->uuid));
    $this->assertSession()->pageTextContains('The plan could not be updated because the billing service encountered an error.');

    // Now mock the response and try again.
    RecurlyMockClient::clearRequestLog();
    RecurlyMockClient::addResponse('PUT', '/subscriptions/' . $this->subscription->uuid, 'subscriptions/show-200.xml');
    $this->getSession()->getPage()->pressButton('Change plan');
    $this->assertTrue(RecurlyMockClient::assertRequestMade('PUT', '/subscriptions/' . $this->subscription->uuid));
    $this->assertSession()->pageTextContains('Plan changed to Bedrock Plan!');
  }

  /**
   * Upgrade to a more expensive plan on renewal.
   *
   * @covers \Drupal\recurly\Form\RecurlySubscriptionChangeConfirmForm
   */
  public function testUpgradeOnRenewal() {
    RecurlyMockClient::clear();
    RecurlyMockClient::addResponse('GET', '/subscriptions/' . $this->subscription->uuid, 'subscriptions/show-200.xml');
    RecurlyMockClient::addResponse('GET', '/plans/silver', 'plans/show-200.xml');
    RecurlyMockClient::addResponse('GET', '/plans/bedrock', 'plans/show-200-bedrock.xml');

    $this->config('recurly.settings')
      ->set('recurly_subscription_upgrade_timeframe', 'renewal')
      ->save();

    $this->drupalGet('/user/' . $this->user->id() . '/subscription/id/' . $this->subscription->uuid . '/change/bedrock');
    $this->assertSession()->pageTextContains('The new plan will take effect on the next billing cycle.');
  }

  /**
   * Verify messaging for downgrades is accurate.
   *
   * @covers \Drupal\recurly\Form\RecurlySubscriptionChangeConfirmForm
   */
  public function testDowngradeMessaging() {
    RecurlyMockClient::clear();
    RecurlyMockClient::addResponse('GET', '/subscriptions/' . $this->subscription->uuid, 'subscriptions/show-200.xml');
    RecurlyMockClient::addResponse('GET', '/plans/silver', 'plans/show-200.xml');
    // Make the bedrock plan cheaper so that it is a downgrade and not an
    // upgrade.
    RecurlyMockClient::addResponse('GET', '/plans/bedrock', [
      'fixture' => 'plans/show-200-bedrock.xml',
      'alterations' => [
        '//unit_amount_in_cents/USD' => 10,
      ],
    ]);

    $this->config('recurly.settings')
      ->set('recurly_subscription_downgrade_timeframe', 'now')
      ->save();

    $this->drupalGet('/user/' . $this->user->id() . '/subscription/id/' . $this->subscription->uuid . '/change/bedrock');
    $this->assertSession()->pageTextContains('The new plan will take effect immediately and a prorated charge (or credit) will be applied to this account.');

    $this->config('recurly.settings')
      ->set('recurly_subscription_downgrade_timeframe', 'renewal')
      ->save();

    $this->drupalGet('/user/' . $this->user->id() . '/subscription/id/' . $this->subscription->uuid . '/change/bedrock');
    $this->assertSession()->pageTextContains('The new plan will take effect on the next billing cycle.');
  }

  /**
   * Test access to quantity change form based on module configuration.
   *
   * @covers \Drupal\recurly\Access\RecurlyAccessQuantity
   */
  public function testChangeQuantityAccess() {
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890', 'accounts/show-200.xml');
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=200', 'subscriptions/index-200-single.xml');
    RecurlyMockClient::addResponse('GET', '/subscriptions/' . $this->subscription->uuid, 'subscriptions/show-200.xml');

    $this->drupalLogin($this->user);

    $this->config('recurly.settings')
      ->set('recurly_subscription_multiple', 0)
      ->save();

    $url = recurly_url('quantity', [
      'entity_type' => $this->user->bundle(),
      'entity' => $this->user,
      'subscription' => $this->subscription,
    ]);
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(403);

    $this->config('recurly.settings')
      ->set('recurly_subscription_multiple', 1)
      ->save();

    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('quantity');
  }

  /**
   * Test the change quantity form.
   *
   * @covers \Drupal\recurly\Form\RecurlyChangeQuantityForm
   */
  public function testChangeQuantityForm() {
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890', 'accounts/show-200.xml');

    $this->config('recurly.settings')
      ->set('recurly_subscription_multiple', 1)
      ->save();

    $url = recurly_url('quantity', [
      'entity_type' => $this->user->bundle(),
      'entity' => $this->user,
      'subscription' => $this->subscription,
    ]);

    // Error is displayed if attempt to load current subscription fails.
    RecurlyMockClient::addResponse('GET', '/subscriptions/' . $this->subscription->uuid, 'subscriptions/missing-404.xml');
    $this->drupalGet($url);
    $this->assertTrue(RecurlyMockClient::assertRequestMade('GET', '/subscriptions/' . $this->subscription->uuid));
    $this->assertSession()->pageTextContains('Unable to retrieve subscription information.');

    // Add a valid response and refresh the page.
    RecurlyMockClient::addResponse('GET', '/subscriptions/' . $this->subscription->uuid, 'subscriptions/show-200.xml');
    $this->drupalGet($url);
    $this->assertSession()->fieldValueEquals('quantity', 1);

    // This should fail.
    $this->submitForm(['quantity' => -5], 'Preview quantity change');
    $this->assertSession()->pageTextContains('Please enter a valid quantity');

    // Submit again with a valid quantity and it should create a confirmation
    // step with a preview.
    RecurlyMockClient::addResponse('POST', '/subscriptions/' . $this->subscription->uuid . '/preview', [
      'fixture' => 'subscriptions/preview-200-change.xml',
      'alterations' => [
        '//uuid' => $this->subscription->uuid,
        '//quantity' => 5,
      ],
    ]);
    $this->submitForm(['quantity' => 5], 'Preview quantity change');
    $this->assertSession()->pageTextContains('Preview changes');
    // Pricing data from fixtures.
    $this->assertSession()->pageTextContains('You are changing from 1 x Silver ($59.82 USD) to 5 x Silver ($20.00 USD)');
    // Verifies the preview invoice from the fixture is displayed.
    $this->assertSession()->elementTextContains('css', '.invoice', 'Total Due: $10.00 USD');

    // If you try and submit the confirmation form with a quantity that's
    // different than the preview it should re-build the confirmation instead.
    $this->submitForm(['quantity' => 6], 'Confirm quantity change');
    $this->assertSession()->pageTextContains('Previewed quantity must match submitted quantity. Please update the preview and try again.');

    $this->submitForm(['quantity' => 6], 'Preview quantity change');
    // The quantity in this string is generated from the form value not the
    // Recurly API fixture so this check should work.
    $this->assertSession()->pageTextContains('You are changing from 1 x Silver ($59.82 USD) to 6 x Silver ($20.00 USD)');

    // This should result in an error.
    RecurlyMockClient::addResponse('PUT', '/subscriptions/' . $this->subscription->uuid, 'subscriptions/missing-404.xml');
    $this->submitForm(['quantity' => 6], 'Confirm quantity change');
    $this->assertSession()->pageTextContains('Unable to update subscription quantity.');

    // But this should work.
    RecurlyMockClient::addResponse('PUT', '/subscriptions/' . $this->subscription->uuid, 'subscriptions/show-200.xml');
    // And results in a redirect so we need these mocks too for the main
    // subscription list page.
    RecurlyMockClient::addResponse('HEAD', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', 'subscriptions/head-200-single.xml');
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', 'subscriptions/index-200-single.xml');
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?state=past_due', 'subscriptions/empty-200.xml');

    $this->submitForm(['quantity' => 6], 'Confirm quantity change');
    $this->assertSession()->pageTextContains('Your subscription has been updated.');
  }

}
