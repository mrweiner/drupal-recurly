<?php

namespace Drupal\Tests\recurly\Kernel;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\recurly\Controller\RecurlyPushListenerController;
use Drupal\recurly_test_client\RecurlyMockClient;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests for RecurlyPushListenerController.
 *
 * @covers \Drupal\recurly\Controller\RecurlyPushListenerController
 * @group recurly
 */
class RecurlyPushListenerControllerTest extends KernelTestBase {

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
   * Drupal user object.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $drupalUser;

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

    RecurlyMockClient::clear();
  }

  /**
   * Test validation of the push notification subdomain configuration.
   */
  public function testSubdomainValidation() {
    $subdomain = 'custom-subdomain';
    $invalid_sub_domain = 'invalid-sub-domain';
    $this->config('recurly.settings')
      ->set('recurly_subdomain', $subdomain)
      ->save();

    $request = $this->prophesize(Request::class);
    $request->getContentType()->willReturn('xml');
    $request->getContent()->willReturn('<xml><new_subscription_notification /></xml>');

    /** @var \Drupal\recurly\Controller\RecurlyPushListenerController $controller */
    $controller = $this->container->get('controller_resolver')
      ->getControllerFromDefinition(RecurlyPushListenerController::class);

    // Subdomain is not set. This should pass the subdomain verification.
    $response = $controller->processPushNotification($request->reveal(), 'key', NULL);
    $this->assertStringNotContainsString('Incoming push notification did not contain the proper subdomain key.', $response->getContent());

    // Subdomain is invalid.
    $response = $controller->processPushNotification($request->reveal(), 'key', $invalid_sub_domain);
    $this->assertNotEquals($subdomain, $invalid_sub_domain);
    $this->assertStringContainsString('Incoming push notification did not contain the proper subdomain key.', $response->getContent());

    // Subdomain matches.
    $response = $controller->processPushNotification($request->reveal(), 'key', $subdomain);
    $this->assertStringNotContainsString('Incoming push notification did not contain the proper subdomain key.', $response->getContent());
  }

  /**
   * Test validation of the push notification unique key parameter.
   */
  public function testKeyValidation() {
    $key = 'asdf1234';
    $invalid_key = 'jklm7890';
    $this->config('recurly.settings')
      ->set('recurly_listener_key', $key)
      ->save();

    $request = $this->prophesize(Request::class);
    $request->getContentType()->willReturn('xml');
    $request->getContent()->willReturn('<xml><new_subscription_notification /></xml>');

    /** @var \Drupal\recurly\Controller\RecurlyPushListenerController $controller */
    $controller = $this->container->get('controller_resolver')
      ->getControllerFromDefinition(RecurlyPushListenerController::class);

    // Invalid key.
    $response = $controller->processPushNotification($request->reveal(), $invalid_key, NULL);
    $this->assertNotEquals($key, $invalid_key);
    $this->assertStringContainsString('Incoming push notification did not contain the proper URL key.', $response->getContent());

    // Valid key.
    $response = $controller->processPushNotification($request->reveal(), $key, NULL);
    $this->assertStringNotContainsString('Incoming push notification did not contain the proper URL key.', $response->getContent());
  }

  /**
   * Tests that hooks are invoked to notify other modules of push notifications.
   */
  public function testHooksInvoked() {
    $moduleHandler = $this->prophesize(ModuleHandlerInterface::class);
    $this->container->set('module_handler', $moduleHandler->reveal());

    $request = $this->prophesize(Request::class);
    $request->getContentType()->willReturn('xml');
    $request->getContent()->willReturn('<xml><new_subscription_notification /></xml>');

    $key = 'asdf1234';
    $this->config('recurly.settings')
      ->set('recurly_listener_key', $key)
      ->save();

    /** @var \Drupal\recurly\Controller\RecurlyPushListenerController $controller */
    $controller = $this->container->get('controller_resolver')
      ->getControllerFromDefinition(RecurlyPushListenerController::class);

    $controller->processPushNotification($request->reveal(), $key, NULL);
    $moduleHandler->invokeAll('recurly_process_push_notification', Argument::any())->shouldHaveBeenCalled();
  }

  /**
   * Tests logic for processing push notifications.
   */
  public function testPushNotificationProcessing() {
    $user = $this->createUser();
    $account_code = 'abcdef1234567890';

    $push_notification = [
      'account' => [
        'account_code' => $account_code,
        'username' => $user->label(),
        'email' => $user->getEmail(),
      ],
      'subscription' => [
        'uuid' => '32558dd8a07eec471fbe6642d3a422f4',
        'plan' => [
          'plan_code' => 'silver',
          'name' => 'Silver',
        ],
        'state' => 'active',
      ],
    ];

    // Configure the push notifications listener route.
    $key = 'asdf1234';
    $this->config('recurly.settings')
      ->set('recurly_listener_key', $key)
      ->save();
    $listner_url = Url::fromRoute('recurly.process_push_notification', [
      'key' => $key,
    ])->toString();

    // First test that we get a 400 error for an empty, or invalid notificaton.
    $request = $this->getMockedRequest($listner_url, 'POST', 'bad');
    $response = $this->processRequest($request);
    $this->assertEquals(400, $response->getStatusCode());
    $this->assertStringContainsString('Empty or invalid notification.', $response->getContent());

    // Test that if the notification is for a new account that doesn't exist
    // locally that we generate the local account record.
    $local_account = recurly_account_load(['account_code' => $account_code], TRUE);
    $this->assertFalse($local_account);

    $new_subscription_notification = $this->arrayToXml($push_notification, '<new_subscription_notification />');
    $request = $this->getMockedRequest($listner_url, 'POST', $new_subscription_notification);
    $response = $this->processRequest($request);

    $this->assertEquals(200, $response->getStatusCode());
    $local_account = recurly_account_load(['account_code' => $account_code], TRUE);
    $this->assertNotFalse($local_account);
    $this->assertEquals('active', $local_account->status);

    // Create a local Recurly account with status set to 'mock-status' for
    // testing, and mock any Recurly API request to get this account.
    $recurly_account = new \Recurly_Account($account_code);
    $recurly_account->state = 'mock-status';
    recurly_account_save($recurly_account, 'user', $user->id(), FALSE);
    RecurlyMockClient::addResponse('GET', '/accounts/' . $account_code, 'accounts/show-200.xml');

    $local_account = recurly_account_load(['account_code' => $account_code], TRUE);
    $this->assertEquals('mock-status', $local_account->status);

    $new_subscription_notification = $this->arrayToXml($push_notification, '<new_subscription_notification />');
    $request = $this->getMockedRequest($listner_url, 'POST', $new_subscription_notification);
    $response = $this->processRequest($request);
    $this->assertEquals(200, $response->getStatusCode());

    $local_account = recurly_account_load(['account_code' => $account_code], TRUE);
    $this->assertEquals('active', $local_account->status);
  }

  /**
   * Creates a request object.
   *
   * @param string $uri
   *   The uri.
   * @param string $method
   *   The method.
   * @param string $document
   *   The document.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   *
   * @throws \Exception
   */
  protected function getMockedRequest(string $uri, string $method, string $document): Request {
    $request = Request::create($uri, $method, [], [], [], [], $document ? $document : NULL);
    if ($document !== []) {
      $request->headers->set('Content-Type', 'text/xml; charset=UTF8');
    }
    return $request;
  }

  /**
   * Process a request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  protected function processRequest(Request $request): Response {
    return $this->container->get('kernel')->handle($request);
  }

  /**
   * Convert an array to XML.
   *
   * @param array $data
   *   Array of key/value pairs to convert to XML.
   * @param string|null $root
   *   Name to use for root element, like a wrapper for the content of the
   *   $data array. Example '<new_subscription_notification />'.
   * @param \SimpleXMLElement $object
   *   XML element.
   *
   * @return string
   *   The generated XML.
   */
  protected function arrayToXml(array $data, string $root = NULL, \SimpleXMLElement $object = NULL) {
    if (is_null($object) && !is_null($root)) {
      $object = new \SimpleXMLElement($root);
    }

    foreach ($data as $key => $value) {
      if (is_array($value)) {
        $new_object = $object->addChild($key);
        $this->arrayToXml($value, NULL, $new_object);
      }
      else {
        // If the key is an integer, it needs text with it to actually work.
        if ($key != 0 && $key == (int) $key) {
          $key = "key_$key";
        }

        $object->addChild($key, $value);
      }
    }

    return $object->asXML();
  }

}
