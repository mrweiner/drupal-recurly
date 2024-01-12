<?php

namespace Drupal\Tests\recurly\Functional;

use Drupal\recurly_test_client\RecurlyMockClient;

/**
 * Tests ability to cancel a recurly subscription.
 *
 * @covers \Drupal\recurly\Controller\RecurlySubscriptionCancelController
 * @covers \Drupal\recurly\Form\RecurlySubscriptionCancelConfirmForm
 * @group recurly
 */
class SubscriptionCancelTest extends RecurlyBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();
    $this->drupalPlaceBlock('local_tasks_block');
    $this->drupalPlaceBlock('system_messages_block');
  }

  /**
   * Helper method to add mock responses shared by most test methods.
   */
  protected function addSharedResponses() {
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890', 'accounts/show-200.xml');
    RecurlyMockClient::addResponse('HEAD', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', 'subscriptions/head-200-single.xml');
    $fixture_index_200_single = [
      'fixture' => 'subscriptions/index-200-single.xml',
      'alterations' => [
        '//current_period_started_at' => date('c', strtotime('-2 weeks')),
        '//current_period_ends_at' => date('c', strtotime('+2 weeks')),
      ],
    ];
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', $fixture_index_200_single);
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=200', $fixture_index_200_single);
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?state=past_due', 'subscriptions/empty-200.xml');
    $fixture_show_200 = $fixture_index_200_single;
    $fixture_show_200['fixture'] = 'subscriptions/show-200.xml';
    RecurlyMockClient::addResponse('GET', '/subscriptions/32558dd8a07eec471fbe6642d3a422f4', $fixture_show_200);
  }

  /**
   * Test that a bad subscription ID returns a 404.
   */
  public function testUserCancelSubscriptionNotFound() {
    RecurlyMockClient::clear();
    RecurlyMockClient::addResponse('GET', '/subscriptions/non-existent-subscription', 'subscriptions/missing-404.xml');

    $account = $this->createUserWithSubscription();
    $this->drupalLogin($account);
    $this->drupalGet('/user/' . $account->id() . '/subscription/id/non-existent-subscription/cancel');
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextContains('Subscription not found ');
  }

  /**
   * Test user's ability to cancel their subscription at next renewal.
   */
  public function testUserCancelAtRenewal() {
    RecurlyMockClient::clear();
    $this->addSharedResponses();

    $this->config('recurly.settings')
      ->set('recurly_subscription_cancel_behavior', 'cancel')
      ->save();

    $account = $this->createUserWithSubscription();
    $this->drupalLogin($account);

    // Routes to 'user/' . $account->id() . '/subscription/id/latest/cancel'.
    // Test that the dynamic route which automatically chooses the latest
    // subscription works.
    $this->drupalGet($account->toUrl('recurly-cancellatest'));
    $this->assertSession()->pageTextContains('Cancel subscription');
    $this->assertSession()->buttonExists('Cancel at Renewal');

    // Load the form again, this by do so by navigating there like a user would.
    // This also confirms the UUID version of the URL works.
    $this->drupalGet($account->toUrl());
    $this->clickLink('Subscription');
    $this->assertSession()->pageTextContains('Subscription Summary');
    $this->clickLink('Cancel');
    $this->assertSession()->addressMatches('@^/user/' . $account->id() . '/subscription/id/.*/cancel$@');
    $this->assertSession()->pageTextContains('Cancel subscription');
    $this->assertSession()->buttonExists('Cancel at Renewal');

    // When the form is submitted it'll make a PUT request to Recurly to update
    // the subscription. We need to mock a response for that, and update the
    // subscription GET response to indicate that it's been cancelled in the
    // Recurly backend.
    RecurlyMockClient::addResponse('PUT', '/subscriptions/32558dd8a07eec471fbe6642d3a422f4/cancel', 'subscriptions/cancel-200.xml');
    $fixture_index_200_single = [
      'fixture' => 'subscriptions/index-200-single.xml',
      'alterations' => [
        '//current_period_started_at' => date('c', strtotime('-2 weeks')),
        '//current_period_ends_at' => date('c', strtotime('+2 weeks')),
        '//state' => 'canceled',
        '//updated_at' => date('c'),
        '//canceled_at' => date('c'),
        '//expires_at' => date('c', strtotime('+2 weeks')),
      ],
    ];
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', $fixture_index_200_single);

    $this->getSession()->getPage()->pressButton('Cancel at Renewal');
    $this->assertSession()->pageTextContains('Plan Silver Plan canceled!');
    $this->assertSession()->elementTextContains('css', '.subscription .status', 'Canceled (will not renew)');
    $this->assertTrue(RecurlyMockClient::assertRequestMade('PUT', '/subscriptions/32558dd8a07eec471fbe6642d3a422f4/cancel'));
  }

  /**
   * Test ability to terminate subscription immediately with partial refund.
   */
  public function testUserCancelTerminateProratedRefund() {
    RecurlyMockClient::clear();
    $this->addSharedResponses();

    $this->config('recurly.settings')
      ->set('recurly_subscription_cancel_behavior', 'terminate_prorated')
      ->save();

    $account = $this->createUserWithSubscription();
    $this->drupalLogin($account);

    $recurly_account = recurly_account_load([
      'entity_type' => $account->bundle(),
      'entity_id' => $account->id(),
    ], TRUE);
    $subscriptions = recurly_account_get_subscriptions($recurly_account->account_code, 'active');
    /** @var \Recurly_Subscription $subscription */
    $subscription = reset($subscriptions);

    // Load the page directly. We tested the navigation elsewhere.
    $this->drupalGet('/user/' . $account->id() . '/subscription/id/' . $subscription->uuid . '/cancel');
    $this->assertSession()->pageTextContains('Cancel subscription');

    // $unit_amount_in_cents * $remaining_time / $total_period_time
    // See recurly_subscription_calculate_refund()
    $this->assertSession()->pageTextMatches('/A refund of\s\$.*\sUSD will be credited to your account/');
    $this->assertSession()->buttonExists('Cancel Plan');

    // When the cancel form is submitted it'll trigger a PUT call to Recurly
    // which we need to mock a response for. As well as change the response
    // that is returned when retrieving the subscription so that it now shows
    // that it's been terminated. This mocks logic that happens in Recurly as
    // a result of the PUT request.
    RecurlyMockClient::addResponse('PUT', '/subscriptions/32558dd8a07eec471fbe6642d3a422f4/terminate?refund=partial&charge=true', 'subscriptions/cancel-200.xml');
    // After this the subscription response should be canceled.
    $fixture_index_200_single = [
      'fixture' => 'subscriptions/index-200-single.xml',
      'alterations' => [
        '//current_period_started_at' => date('c', strtotime('-2 weeks')),
        '//current_period_ends_at' => date('c', strtotime('+2 weeks')),
        '//state' => 'expired',
        '//updated_at' => date('c'),
        '//canceled_at' => date('c'),
        '//expires_at' => date('c'),
      ],
    ];
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', $fixture_index_200_single);

    // Submit the form. And verify the updated summary page.
    $this->getSession()->getPage()->pressButton('Cancel Plan');
    $this->assertSession()->pageTextContains('Plan Silver Plan terminated!');
    $this->assertSession()->elementTextContains('css', '.subscription .status', 'Expired');
    // Also verify that a PUT request gets made to Recurly.
    $this->assertTrue(RecurlyMockClient::assertRequestMade('PUT', '/subscriptions/32558dd8a07eec471fbe6642d3a422f4/terminate?refund=partial&charge=true'));
  }

  /**
   * Test ability to terminate subscription immediately with full refund.
   */
  public function testUserCancelTerminateFullRefund() {
    RecurlyMockClient::clear();
    $this->addSharedResponses();

    $this->config('recurly.settings')
      ->set('recurly_subscription_cancel_behavior', 'terminate_full')
      ->save();

    $account = $this->createUserWithSubscription();
    $this->drupalLogin($account);

    $recurly_account = recurly_account_load([
      'entity_type' => $account->bundle(),
      'entity_id' => $account->id(),
    ], TRUE);
    $subscriptions = recurly_account_get_subscriptions($recurly_account->account_code, 'active');
    /** @var \Recurly_Subscription $subscription */
    $subscription = reset($subscriptions);

    // Load the page directly. We tested the navigation elsewhere.
    $this->drupalGet('/user/' . $account->id() . '/subscription/id/' . $subscription->uuid . '/cancel');
    $this->assertSession()->pageTextContains('Cancel subscription');

    // See recurly_subscription_calculate_refund()
    $this->assertSession()->pageTextContains('A refund of $59.82 USD will be credited to your account.');
    $this->assertSession()->buttonExists('Cancel Plan');

    // When the cancel form is submitted it'll trigger a PUT call to Recurly
    // which we need to mock a response for. As well as change the response
    // that is returned when retrieving the subscription so that it now shows
    // that it's been terminated. This mocks logic that happens in Recurly as
    // a result of the PUT request.
    RecurlyMockClient::addResponse('PUT', '/subscriptions/32558dd8a07eec471fbe6642d3a422f4/terminate?refund=full&charge=true', 'subscriptions/cancel-200.xml');
    // After this the subscription response should be canceled.
    $fixture_index_200_single = [
      'fixture' => 'subscriptions/index-200-single.xml',
      'alterations' => [
        '//current_period_started_at' => date('c', strtotime('-2 weeks')),
        '//current_period_ends_at' => date('c', strtotime('+2 weeks')),
        '//state' => 'expired',
        '//updated_at' => date('c'),
        '//canceled_at' => date('c'),
        '//expires_at' => date('c'),
      ],
    ];
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', $fixture_index_200_single);

    // Submit the form. And verify the updated summary page.
    $this->getSession()->getPage()->pressButton('Cancel Plan');
    $this->assertSession()->pageTextContains('Plan Silver Plan terminated!');
    $this->assertSession()->elementTextContains('css', '.subscription .status', 'Expired');
    // Also verify that a PUT request gets made to Recurly.
    $this->assertTrue(RecurlyMockClient::assertRequestMade('PUT', '/subscriptions/32558dd8a07eec471fbe6642d3a422f4/terminate?refund=full&charge=true'));
  }

  /**
   * Test ability to terminate subscription immediately with full refund.
   */
  public function testAdminCancelOptions() {
    RecurlyMockClient::clear();
    $this->addSharedResponses();

    $account = $this->createUserWithSubscription();

    $recurly_account = recurly_account_load([
      'entity_type' => $account->bundle(),
      'entity_id' => $account->id(),
    ], TRUE);
    $subscriptions = recurly_account_get_subscriptions($recurly_account->account_code, 'active');
    /** @var \Recurly_Subscription $subscription */
    $subscription = reset($subscriptions);

    $admin = $this->drupalCreateUser(['administer recurly', 'administer users']);
    $this->drupalLogin($admin);

    // Admin should see all cancellation options regardless of configuration.
    $this->drupalGet('/user/' . $account->id() . '/subscription/id/' . $subscription->uuid . '/cancel');
    $this->assertSession()->pageTextContains('Cancel subscription');
    $this->assertSession()->buttonExists('Cancel at Renewal');
    $this->assertSession()->pageTextContains('USD - None');
    $this->assertSession()->pageTextContains('USD - Prorated');
    $this->assertSession()->pageTextContains('USD - Full');
    $this->assertSession()->buttonExists('Terminate Immediately');

    // When the form is submitted it'll make a PUT request to Recurly to update
    // the subscription. We need to mock a response for that, and update the
    // subscription GET response to indicate that it's been cancelled in the
    // Recurly backend.
    RecurlyMockClient::addResponse('PUT', '/subscriptions/32558dd8a07eec471fbe6642d3a422f4/cancel', 'subscriptions/cancel-200.xml');
    $fixture_index_200_single = [
      'fixture' => 'subscriptions/index-200-single.xml',
      'alterations' => [
        '//current_period_started_at' => date('c', strtotime('-2 weeks')),
        '//current_period_ends_at' => date('c', strtotime('+2 weeks')),
        '//state' => 'canceled',
        '//updated_at' => date('c'),
        '//canceled_at' => date('c'),
        '//expires_at' => date('c', strtotime('+2 weeks')),
      ],
    ];
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', $fixture_index_200_single);

    // Clicking the 'Cancel at Renewal' button should work even if none of the
    // required radio options in the form are selected. This tests the
    // #limit_validation_options feature on that button.
    $this->getSession()->getPage()->pressButton('Cancel at Renewal');
    $this->assertSession()->pageTextContains('Plan Silver Plan canceled!');
  }

}
