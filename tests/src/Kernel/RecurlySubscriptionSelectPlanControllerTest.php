<?php

namespace Drupal\Tests\recurly\Kernel;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\recurly\Controller\RecurlySubscriptionSelectPlanController;
use Drupal\recurly_test_client\RecurlyMockClient;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Test different redirects from recurly.routing.yml.
 *
 * @covers \Drupal\recurly\Controller\RecurlySubscriptionSelectPlanController
 * @group recurly
 */
class RecurlySubscriptionSelectPlanControllerTest extends KernelTestBase {

  use ProphecyTrait;
  use UserCreationTrait;

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
      ->set('recurly_subscription_plans', [
        'silver' => [
          'status' => '1',
          'weight' => '0',
        ],
      ])
      ->save();

    $this->installSchema('system', ['sequences']);
    $this->installSchema('recurly', ['recurly_account']);
    $this->installEntitySchema('user');

    RecurlyMockClient::clear();
  }

  /**
   * This also validates the route /subscription/register.
   *
   * @covers \Drupal\recurly\Controller\RecurlySubscriptionSelectPlanController::redirectToRegistration
   */
  public function testRegistrationRedirect() {
    $controller = RecurlySubscriptionSelectPlanController::create(\Drupal::getContainer());
    $response = $controller->redirectToRegistration();
    $this->assertInstanceOf('\Symfony\Component\HttpFoundation\RedirectResponse', $response);
    $this->assertEquals(302, $response->getStatusCode());
    $this->assertStringContainsString('/user/register', $response->getTargetUrl());
  }

  /**
   * This helps validate the route /user/signup for anon. users.
   *
   * @covers \Drupal\recurly\Controller\RecurlySubscriptionSelectPlanController::planSelect
   */
  public function testPlanSelectAnonUserSignup() {
    RecurlyMockClient::addResponse('GET', '/plans', 'plans/index-200.xml');

    // Anon users should be shown a themed plan selection widget in signup mode.
    $controller = RecurlySubscriptionSelectPlanController::create(\Drupal::getContainer());
    $response = $controller->planSelect(\Drupal::routeMatch());
    $this->assertArrayHasKey('#theme', $response);
    $this->assertEquals(RecurlySubscriptionSelectPlanController::SELECT_PLAN_MODE_SIGNUP, $response['#mode']);
  }

  /**
   * This helps validate the route /user/signup for authenticated users.
   *
   * @covers \Drupal\recurly\Controller\RecurlySubscriptionSelectPlanController::planSelect
   */
  public function testPlanSelectAuthenticatedUserSignup() {
    // Authenticated users who do not have a subscription should be redirected
    // to the authenticated user signup page.
    $controller = RecurlySubscriptionSelectPlanController::create(\Drupal::getContainer());
    $this->setUpCurrentUser();

    $response = $controller->planSelect(\Drupal::routeMatch());
    $this->assertInstanceOf('\Symfony\Component\HttpFoundation\RedirectResponse', $response);
  }

  /**
   * Plan selection for users who already have a subscription.
   *
   * @covers \Drupal\recurly\Controller\RecurlySubscriptionSelectPlanController::planSelect
   */
  public function testPlanSelectSubscriptionLookup() {
    RecurlyMockClient::addResponse('GET', '/subscriptions/32558dd8a07eec471fbe6642d3a422f4', 'subscriptions/show-200.xml');
    RecurlyMockClient::addResponse('GET', '/plans', 'plans/index-200.xml');
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=200', 'subscriptions/index-200-single.xml');

    $controller = RecurlySubscriptionSelectPlanController::create(\Drupal::getContainer());
    $user = $this->setUpCurrentUser();

    // Add a Recurly subscription to the user.
    $recurly_account = new \Recurly_Account('abcdef1234567890');
    recurly_account_save($recurly_account, 'user', $user->id(), FALSE);

    $subscription_id = 'latest';
    $routeMatch = $this->prophesize(RouteMatchInterface::class);
    $routeMatch->getParameters()
      ->willReturn(new ParameterBag([
        'subscription_id' => $subscription_id,
        'user' => $user,
      ]));
    $routeMatch->getParameter('user')->willReturn($user);
    $response = $controller->planSelect($routeMatch->reveal(), NULL, 'latest');
    $this->assertArrayHasKey('#theme', $response);
    $this->assertEquals('change', $response['#mode']);

    $subscription_id = '32558dd8a07eec471fbe6642d3a422f4';
    $routeMatch = $this->prophesize(RouteMatchInterface::class);
    $routeMatch->getParameters()
      ->willReturn(new ParameterBag([
        'subscription_id' => $subscription_id,
        'user' => $user,
      ]));
    $routeMatch->getParameter('user')->willReturn($user);
    $response = $controller->planSelect($routeMatch->reveal(), NULL, $subscription_id);
    $this->assertArrayHasKey('#theme', $response);
    $this->assertEquals('change', $response['#mode']);
  }

}
