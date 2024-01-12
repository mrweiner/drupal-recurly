<?php

namespace Drupal\Tests\recurly\Kernel;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\recurly_test_client\RecurlyMockClient;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Tests for RecurlyEntityOperations service.
 *
 * @covers \Drupal\recurly\RecurlyEntityOperations
 * @group recurly
 */
class RecurlyEntityOperationsTest extends KernelTestBase {

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
   * Drupal user account for testing.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $user;

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

    // Configure token mapping so that the module attempts to save values back
    // to Recurly account objects.
    $this->config('recurly.settings')
      ->set('recurly_token_mapping', [
        'email' => '[user:mail]',
        'username' => '[user:name]',
      ])
      ->save();

    // Add a user and Recurly subscription, this matches what's in the API
    // fixtures.
    $user = $this->createUser();
    $account_code = 'abcdef1234567890';
    $recurly_account = new \Recurly_Account($account_code);
    recurly_account_save($recurly_account, 'user', $user->id(), FALSE);
    $this->user = $user;

    RecurlyMockClient::clear();
  }

  /**
   * Tests that errors are logged if the Recurly account can't be loaded.
   *
   * @covers \Drupal\recurly\RecurlyEntityOperations::entityUpdate
   */
  public function testEntityUpdate() {
    $messenger = $this->prophesize(MessengerInterface::class);
    $this->container->set('messenger', $messenger->reveal());

    // Then update the user, and verify the entityUpdate method is triggered.
    $old_email = $this->user->mail;
    $new_email = $this->randomMachineName() . '@example.com';
    $this->assertNotEquals($new_email, $old_email);
    $this->user->mail = $new_email;

    // This should trigger RecurlyEntityOperations::entityUpdate, which should
    // make a call to the Recurly API. First, we should get an error if the
    // Recurly_Account object can't be loaded.
    $this->user->save();

    // Check that our message was added to the messenger service. Gets called
    // 2 times, once for the RecurlyMockClient notices.
    $messenger->addWarning(Argument::that(function ($arg) {
      return stristr($arg, 'Unable to save updated account data') || stristr($arg, 'RecurlyMockClient');
    }))->shouldHaveBeenCalledTimes(2);
  }

  /**
   * Tests that errors are logged if the account data can't be saved to Recurly.
   *
   * @covers \Drupal\recurly\RecurlyEntityOperations::entityUpdate
   */
  public function testEntityUpdatePutRequestError() {
    $messenger = $this->prophesize(MessengerInterface::class);
    $this->container->set('messenger', $messenger->reveal());

    // Mock recurly response when trying to load this account.
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890', 'accounts/show-200.xml');

    // Then, do it again, we should get an error when the PUT request fails.
    $old_email = $this->user->mail;
    $new_email = $this->randomMachineName() . '@example.com';
    $this->assertNotEquals($new_email, $old_email);
    $this->user->mail = $new_email;
    $this->user->save();

    // Once for the error, and 2x for notices from RecurlyMockClient.
    $messenger->addWarning(Argument::that(function ($arg) {
      return stristr($arg, 'The billing system reported an error') || stristr($arg, 'RecurlyMockClient');
    }))->shouldHaveBeenCalledTimes(3);
  }

  /**
   * Tests that data can succsesfully be safed to Recurly.
   *
   * @covers \Drupal\recurly\RecurlyEntityOperations::entityUpdate
   */
  public function testEntityUpdateSucess() {
    $messenger = $this->prophesize(MessengerInterface::class);
    $this->container->set('messenger', $messenger->reveal());

    // And finally, one that should succeed because we mock the PUT request too.
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890', 'accounts/show-200.xml');
    RecurlyMockClient::addResponse('PUT', '/accounts/abcdef1234567890', 'accounts/show-200.xml');

    $old_email = $this->user->mail;
    $new_email = $this->randomMachineName() . '@example.com';
    $this->assertNotEquals($new_email, $old_email);
    $this->user->mail = $new_email;
    $this->user->save();
    $messenger->addWarning(Argument::that(function ($arg) {
      return stristr($arg, 'The billing system reported an error') || stristr($arg, 'RecurlyMockClient');
    }))->shouldHaveBeenCalledTimes(2);

    $this->assertTrue(RecurlyMockClient::assertRequestMade('PUT', '/accounts/abcdef1234567890'));
  }

}
