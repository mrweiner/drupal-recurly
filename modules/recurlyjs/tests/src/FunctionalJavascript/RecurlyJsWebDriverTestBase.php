<?php

namespace Drupal\Tests\recurlyjs\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Base class for creating WebDriver tests for RecurlyJS.
 *
 * @group recurly
 */
abstract class RecurlyJsWebDriverTestBase extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'field_test',
    'node',
    'recurly',
    'recurlyjs',
    'recurly_test_client',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();

    $this->drupalPlaceBlock('local_tasks_block');
    $this->drupalPlaceBlock('system_messages_block');

    // Associate Recurly subscriptions with user entities and enable the
    // "silver" mock plan.
    $this->config('recurly.settings')
      ->set('recurly_public_key', 'ASDF-1234')
      ->set('recurly_private_api_key', 'ASDF-1234')
      ->set('recurly_subdomain', 'recurly-drupal-test')
      ->set('recurly_entity_type', 'user')
      ->set('recurly_subscription_plans', [
        'silver' => [
          'status' => 1,
          'weight' => 0,
        ],
      ])
      ->save();
  }

  /**
   * Test user's ability to cancel their subscription.
   *
   * Wrapper for \Drupal\Tests\user\Traits\UserCreationTrait::createUser() that
   * will also associate a recurly subscription with the user.
   */
  public function createUserWithSubscription($permissions = []) {
    $permissions = empty($permissions) ? ['manage recurly subscription'] : $permissions;
    $account = $this->drupalCreateUser($permissions);

    // Add a Recurly subscription to the user.
    $account_code = 'abcdef1234567890';
    $recurly_account = new \Recurly_Account($account_code);
    recurly_account_save($recurly_account, 'user', $account->id(), FALSE);
    return $account;
  }

}
