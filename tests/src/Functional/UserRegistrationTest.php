<?php

namespace Drupal\Tests\recurly\Functional;

use Drupal\recurly_test_client\RecurlyMockClient;
use Drupal\user\UserInterface;

/**
 * Tests ability for users to register an account with a recurly subscription.
 *
 * @group recurly
 */
class UserRegistrationTest extends RecurlyBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();

    $config = $this->config('user.settings');
    // Don't require email verification and allow registration by site visitors
    // without administrator approval.
    $config
      ->set('verify_mail', FALSE)
      ->set('register', UserInterface::REGISTER_VISITORS)
      ->save();

    RecurlyMockClient::clear();
    RecurlyMockClient::addResponse('GET', '/plans', 'plans/index-200.xml');
    RecurlyMockClient::addResponse('GET', '/plans/corporis_excepturi8/add_ons/dolores_molestiae15', 'addons/show-200.xml');
    RecurlyMockClient::addResponse('GET', '/plans/silver', 'plans/show-200.xml');
    RecurlyMockClient::addResponse('HEAD', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', 'subscriptions/head-200-single.xml');
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active', 'subscriptions/index-200-single.xml');
  }

  /**
   * Test user registration when the recurly entity type is 'user'.
   */
  public function testUserRegistrationRecurlyEntityUser() {
    // Associate Recurly subscriptions with user entities (this is the default).
    // And enable the "silver" mock plan.
    $this->config('recurly.settings')
      ->set('recurly_entity_type', 'user')
      ->set('recurly_subscription_plans', [
        'silver' => [
          'status' => 1,
          'weight' => 0,
        ],
      ])
      ->save();

    $edit = [];
    $edit['name'] = $name = $this->randomMachineName();
    $edit['mail'] = $mail = $edit['name'] . '@example.com';
    $edit['pass[pass1]'] = $new_pass = $this->randomMachineName();
    $edit['pass[pass2]'] = $new_pass;
    $this->drupalGet('user/register');
    $this->submitForm($edit, 'Create new account');
    $this->container->get('entity_type.manager')
      ->getStorage('user')
      ->resetCache();
    $accounts = $this->container->get('entity_type.manager')->getStorage('user')
      ->loadByProperties(['name' => $name, 'mail' => $mail]);

    /** @var \Drupal\user\UserInterface $new_user */
    $new_user = reset($accounts);
    $this->assertNotNull($new_user, 'New account successfully created.');
    $this->assertSession()
      ->pageTextContains('Registration successful. You are now logged in.');

    // Verify the user is redirected to the recurly signup page after
    // registration.
    // @see recurly_user_edit_form_submit_redirect()
    $url = $new_user->toUrl('recurly-signup');
    $this->assertSession()->addressEquals($url);
    $this->assertSession()->pageTextContains('Silver Plan');

    $this->drupalLogout();
  }

}
