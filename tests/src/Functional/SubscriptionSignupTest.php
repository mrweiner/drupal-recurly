<?php

namespace Drupal\Tests\recurly\Functional;

use Drupal\recurly_test_client\RecurlyMockClient;

/**
 * Tests basic subscription signup workflow.
 *
 * @package Drupal\Tests\recurly\Functional
 * @group recurly
 */
class SubscriptionSignupTest extends RecurlyBrowserTestBase {

  /**
   * Create a user and see if they can reach the signup page.
   */
  public function testSubscriptionSignupUserEntity() {
    RecurlyMockClient::addResponse('GET', '/plans', 'plans/index-200.xml');

    $account = $this->drupalCreateUser(['manage recurly subscription']);
    $this->drupalLogin($account);

    $this->drupalGet('user/' . $account->id() . '/subscription/signup');
    $assert = $this->assertSession();
    $assert->pageTextContains('Silver Plan');
    // Without enabling either recurlyjs or recurly_host_pages you can't
    // get any further than this.
    $assert->pageTextContains('Contact us to sign up');
  }

}
