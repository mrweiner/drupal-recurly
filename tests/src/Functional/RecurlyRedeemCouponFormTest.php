<?php

namespace Drupal\Tests\recurly\Functional;

use Drupal\recurly_test_client\RecurlyMockClient;

/**
 * Tests for RecurlyRedeemCouponFormTest.
 *
 * @group recurly
 */
class RecurlyRedeemCouponFormTest extends RecurlyBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();
    $this->drupalPlaceBlock('local_tasks_block');
    $this->drupalPlaceBlock('system_messages_block');
  }

  /**
   * Test access to coupon redemption form based on module configuration.
   *
   * @covers \Drupal\recurly\Access\RecurlyAccessCoupon
   */
  public function testAccessControl() {
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890', 'accounts/show-200.xml');
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=200', 'subscriptions/index-200-single.xml');

    $account = $this->createUserWithSubscription();
    $this->drupalLogin($account);

    $this->config('recurly.settings')
      ->set('recurly_coupon_page', 0)
      ->save();

    $this->drupalGet($account->toUrl('recurly-coupon'));
    $this->assertSession()->statusCodeEquals(403);

    $this->config('recurly.settings')
      ->set('recurly_coupon_page', 1)
      ->save();

    $this->drupalGet($account->toUrl('recurly-coupon'));
    $this->assertSession()->statusCodeEquals(200);
  }

}
