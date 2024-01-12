<?php

namespace Drupal\Tests\recurly\Functional;

use Drupal\Core\Url;
use Drupal\recurly_test_client\RecurlyMockClient;

/**
 * Tests ability to reactivate a canceled recurly subscription.
 *
 * @covers \Drupal\recurly\Controller\RecurlySubscriptionReactivateController
 * @group recurly
 */
class SubscriptionReactivateTest extends RecurlyBrowserTestBase {

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
   * Test that a cancelled subscription can be reactivated.
   */
  public function testReactivateCancelledSubscription() {
    // Create a user with a subscription.
    $account = $this->createUserWithSubscription();
    $this->drupalLogin($account);

    // Responses shared for each test case.
    $fixture_index_200_single = [
      'fixture' => 'subscriptions/index-200-single.xml',
      'alterations' => [
        '//state' => 'canceled',
      ],
    ];
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=200', $fixture_index_200_single);
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890', 'accounts/show-200.xml');
    RecurlyMockClient::addResponse('HEAD', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', 'subscriptions/head-200-single.xml');
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', $fixture_index_200_single);
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?state=past_due', 'subscriptions/empty-200.xml');

    // Test what happens if we return an invalid response from Recurly.
    RecurlyMockClient::addResponse('PUT', '/subscriptions/32558dd8a07eec471fbe6642d3a422f4/reactivate', 'subscriptions/missing-404.xml');
    $this->drupalGet($account->toUrl('recurly-reactivatelatest'));
    $this->assertSession()->pageTextContains('The plan could not be reactivated');

    // And, what happens if we return a valid response.
    RecurlyMockClient::addResponse('PUT', '/subscriptions/32558dd8a07eec471fbe6642d3a422f4/reactivate', 'subscriptions/index-200-single.xml');

    // Try using the 'latest' subscription route.
    $this->drupalGet($account->toUrl('recurly-reactivatelatest'));
    $this->assertTrue(RecurlyMockClient::assertRequestMade('PUT', '/subscriptions/32558dd8a07eec471fbe6642d3a422f4/reactivate'));
    $this->assertSession()->pageTextContains('Plan Silver Plan reactivated! Normal billing will resume on Tuesday, November 8, 2016');

    // Try using the specific subscription ID route.
    $url = Url::fromRoute('entity.user.recurly_reactivate', [
      'user' => $account->id(),
      'subscription_id' => '32558dd8a07eec471fbe6642d3a422f4',
    ], ['absolute' => TRUE]);

    // Error response.
    RecurlyMockClient::addResponse('GET', '/subscriptions/32558dd8a07eec471fbe6642d3a422f4', 'subscriptions/missing-404.xml');
    $this->drupalGet($url->toString());
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextContains('Subscription not found');

    // Happy path.
    RecurlyMockClient::addResponse('GET', '/subscriptions/32558dd8a07eec471fbe6642d3a422f4', 'subscriptions/show-200.xml');
    $this->drupalGet($url->toString());
    $this->assertSession()->pageTextContains('Plan Silver Plan reactivated! Normal billing will resume on Tuesday, November 8, 2016');
  }

}
