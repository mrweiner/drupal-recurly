<?php

namespace Drupal\Tests\recurly\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\recurly\Controller\RecurlyManageSubscriptionController;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests for RecurlyManageSubscriptionController.
 *
 * @covers \Drupal\recurly\Controller\RecurlyManageSubscriptionController
 * @group recurly
 */
class RecurlyManageSubscriptionControllerTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * Instance of controller to test.
   *
   * @var \Drupal\recurly\Controller\RecurlyController|\Drupal\recurly\Controller\RecurlyManageSubscriptionController
   */
  protected $controller;

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

    $this->controller = RecurlyManageSubscriptionController::create(\Drupal::getContainer());
  }

  /**
   * Tests that a redirect is returned for valid account codes.
   *
   * @covers \Drupal\recurly\Controller\RecurlyManageSubscriptionController::subscriptionRedirect
   */
  public function testSubscriptionRedirect() {
    $user = $this->setUpCurrentUser();
    // Add a Recurly subscription to the user.
    $account_code = 'abcdef1234567890';
    $recurly_account = new \Recurly_Account($account_code);
    recurly_account_save($recurly_account, 'user', $user->id(), FALSE);

    $response = $this->controller->subscriptionRedirect($account_code);
    $this->assertInstanceOf('\Symfony\Component\HttpFoundation\RedirectResponse', $response);
    $this->assertEquals(302, $response->getStatusCode());
    $this->assertStringContainsString('/user/' . $user->id() . '/subscription', $response->getTargetUrl());

    $this->expectException(NotFoundHttpException::class);
    $this->controller->subscriptionRedirect('bad-account-code');
  }

}
