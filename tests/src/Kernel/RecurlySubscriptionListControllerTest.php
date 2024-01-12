<?php

namespace Drupal\Tests\recurly\Kernel;

use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\recurly_test_client\RecurlyMockClient;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Tests for RecurlySubscriptionListController.
 *
 * @group recurly
 */
class RecurlySubscriptionListControllerTest extends KernelTestBase {

  use ProphecyTrait;
  use UserCreationTrait;

  /**
   * Drupal user object.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $drupalUser;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'recurly',
    'recurly_test_client',
    'system',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();

    $this->installConfig(['recurly', 'user', 'system']);

    $this->config('recurly.settings')
      ->set('recurly_entity_type', 'user')
      ->set('recurly_subscription_plans',
        ['silver' => ['status' => '1', 'weight' => '0']])
      ->save();

    $this->installSchema('system', ['sequences']);
    $this->installSchema('recurly', ['recurly_account']);
    $this->installEntitySchema('user');

    // Add a user and Recurly subscription, this matches what's in the API
    // fixtures.
    $user = $this->setUpCurrentUser();
    $account_code = 'abcdef1234567890';
    $recurly_account = new \Recurly_Account($account_code);
    recurly_account_save($recurly_account, 'user', $user->id(), FALSE);

    $this->drupalUser = $user;

    RecurlyMockClient::clear();
  }

  /**
   * Test configuration settings for subscription list page.
   *
   * @covers \Drupal\recurly\Controller\RecurlySubscriptionListController::subscriptionList
   */
  public function testSubscriptionList() {
    // For when the Recurly account gets loaded.
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890', 'accounts/show-200.xml');

    // Only list 'live' subscriptions.
    $this->config('recurly.settings')
      ->set('recurly_subscription_display', 'live')
      ->save();

    // Only return a single active subscription.
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', 'subscriptions/index-200-single.xml');
    RecurlyMockClient::addResponse('HEAD', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', 'subscriptions/head-200-single.xml');
    // No past-due subscriptions.
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?state=past_due', 'subscriptions/empty-200.xml');

    $routeMatch = $this->prophesize(RouteMatchInterface::class);
    $routeMatch->getParameter('user')->willReturn($this->drupalUser);

    /** @var \Drupal\recurly\Controller\RecurlySubscriptionListController $controller */
    $controller = $this->container->get('controller_resolver')
      ->getControllerFromDefinition('Drupal\recurly\Controller\RecurlySubscriptionListController');

    $response = $controller->subscriptionList($routeMatch->reveal());
    $this->assertTrue(RecurlyMockClient::assertRequestMade('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active'));
    $this->assertFalse(RecurlyMockClient::assertRequestMade('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50'));
    $this->assertCount(1, Element::children($response['subscriptions']));

    // Only return a set of 5 subscriptions.
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', 'subscriptions/index-200.xml');
    RecurlyMockClient::addResponse('HEAD', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', 'subscriptions/head-200.xml');

    $response = $controller->subscriptionList($routeMatch->reveal());
    $this->assertCount(5, Element::children($response['subscriptions']));

    // List 'all' subscriptions.
    $this->config('recurly.settings')
      ->set('recurly_subscription_display', 'all')
      ->save();

    // When 'all' subscriptions are displayed the &state=active param is not
    // included in the API request.
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50', 'subscriptions/index-200-single.xml');
    RecurlyMockClient::addResponse('HEAD', '/accounts/abcdef1234567890/subscriptions?per_page=50', 'subscriptions/head-200-single.xml');
    RecurlyMockClient::clearRequestLog();

    $response = $controller->subscriptionList($routeMatch->reveal());
    $this->assertTrue(RecurlyMockClient::assertRequestMade('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50'));
    $this->assertFalse(RecurlyMockClient::assertRequestMade('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50state=active'));
  }

  /**
   * Verify user is redirected if they do not have a recurly account.
   *
   * @covers \Drupal\recurly\Controller\RecurlySubscriptionListController::subscriptionList
   */
  public function testNoAccountBehavior() {
    $account = $this->createUser(['manage recurly subscription']);
    $routeMatch = $this->prophesize(RouteMatchInterface::class);
    $routeMatch->getParameter('user')->willReturn($account);

    // User does not have a recurly account.
    $recurly_account = recurly_account_load([
      'entity_type' => 'user',
      'entity_id' => $account->id(),
    ]);
    $this->assertFalse($recurly_account);

    /** @var RecurlySubscriptionListController $controller */
    $controller = $this->container->get('controller_resolver')
      ->getControllerFromDefinition('Drupal\recurly\Controller\RecurlySubscriptionListController');

    $response = $controller->subscriptionList($routeMatch->reveal());
    $this->assertInstanceOf(RedirectResponse::class, $response);
  }

  /**
   * Test user is redirected if they have no subscriptions.
   *
   * @covers \Drupal\recurly\Controller\RecurlySubscriptionListController::subscriptionList
   */
  public function testNoSubscriptionsBehavior() {
    // For when the Recurly account gets loaded.
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890', 'accounts/show-200.xml');

    // No subscriptions.
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', 'subscriptions/empty-200.xml');
    RecurlyMockClient::addResponse('HEAD', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', 'subscriptions/empty-200.xml');
    // No past-due subscriptions.
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?state=past_due', 'subscriptions/empty-200.xml');

    $routeMatch = $this->prophesize(RouteMatchInterface::class);
    $routeMatch->getParameter('user')->willReturn($this->drupalUser);

    /** @var RecurlySubscriptionListController $controller */
    $controller = $this->container->get('controller_resolver')
      ->getControllerFromDefinition('Drupal\recurly\Controller\RecurlySubscriptionListController');

    $response = $controller->subscriptionList($routeMatch->reveal());
    $this->assertInstanceOf(RedirectResponse::class, $response);
  }

  /**
   * Tests calculation of subscription states.
   *
   * @covers \Drupal\recurly\Controller\RecurlySubscriptionListController::subscriptionGetStates
   */
  public function testSubscriptionListStates() {
    // For when the Recurly account gets loaded.
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890', 'accounts/show-200.xml');

    // Single subscription.
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', 'subscriptions/index-200-single.xml');
    RecurlyMockClient::addResponse('HEAD', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', 'subscriptions/head-200-single.xml');
    // No past-due subscriptions.
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?state=past_due', 'subscriptions/empty-200.xml');

    $routeMatch = $this->prophesize(RouteMatchInterface::class);
    $routeMatch->getParameter('user')->willReturn($this->drupalUser);

    /** @var RecurlySubscriptionListController $controller */
    $controller = $this->container->get('controller_resolver')
      ->getControllerFromDefinition('Drupal\recurly\Controller\RecurlySubscriptionListController');

    // This UUID matches what's in the index-200-single.xml mock.
    $subscription_uuid = '32558dd8a07eec471fbe6642d3a422f4';

    // These are the base states that a subscription can be in as stored in the
    // Recurly API. Other states like 'past_due' are calculated states.
    $base_states = ['active', 'canceled', 'expired', 'pending', 'future'];
    foreach ($base_states as $state) {
      RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', [
        'fixture' => 'subscriptions/index-200-single.xml',
        'alterations' => [
          '//state' => $state,
        ],
      ]);
      $response = $controller->subscriptionList($routeMatch->reveal());
      $this->assertSame([$state], $response['subscriptions'][$subscription_uuid]['#state_array']);
    }

    // The 'in_trial' state is added if the subscription has trial start/end
    // dates.
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', [
      'fixture' => 'subscriptions/index-200-single.xml',
      'alterations' => [
        '//state' => 'active',
        '//trial_started_at' => date('c', strtotime('-2 weeks')),
        '//trial_ends_at' => date('c', strtotime('+2 weeks')),
      ],
    ]);
    $response = $controller->subscriptionList($routeMatch->reveal());
    $this->assertSame(['in_trial', 'active'], $response['subscriptions'][$subscription_uuid]['#state_array']);

    // The 'non_renewing' state is added if the subscription is set to
    // auto_renew = false.
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', [
      'fixture' => 'subscriptions/index-200-single.xml',
      'alterations' => [
        '//state' => 'active',
        '//auto_renew' => 'false',
      ],
    ]);
    $response = $controller->subscriptionList($routeMatch->reveal());
    $this->assertSame(['non_renewing', 'active'], $response['subscriptions'][$subscription_uuid]['#state_array']);

    // Subscription is in the 'past_due' list so that state is reflected.
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', 'subscriptions/index-200-single.xml');
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?state=past_due', 'subscriptions/index-200-single.xml');

    $controller->clearPastDueCache();
    $response = $controller->subscriptionList($routeMatch->reveal());
    $this->assertSame(['past_due', 'active'], $response['subscriptions'][$subscription_uuid]['#state_array']);
  }

}
