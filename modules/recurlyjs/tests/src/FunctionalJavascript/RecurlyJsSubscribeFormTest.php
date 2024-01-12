<?php

namespace Drupal\Tests\recurlyjs\FunctionalJavascript;

use Drupal\recurly_test_client\RecurlyMockClient;

/**
 * Tests for RecurlyJsSubscribeForm.
 *
 * @group recurly
 * @covers \Drupal\recurlyjs\Form\RecurlyJsSubscribeForm;
 */
class RecurlyJsSubscribeFormTest extends RecurlyJsWebDriverTestBase {

  /**
   * Tests for the RecurlyJS subscription checkout form.
   */
  public function testSubscribeForm() {
    $account = $this->drupalCreateUser(['manage recurly subscription']);
    $this->drupalLogin($account);

    RecurlyMockClient::addResponse('GET', '/plans/silver', 'plans/show-200.xml');
    $this->drupalGet('/user/' . $account->id() . '/subscription/signup/silver');

    // Mock the JavaScript payload that Recurly sends when changes are made
    // to the price. For example when someone enters a coupon code. This at
    // least confirms our JS that shows/hides elements works. Mocking the whole
    // RecurlyJS API probably isn't really worth it.
    $javascript = "Drupal.recurly.recurlyJSPricing({currency: 'USD', 'now': {total: 10, 'setup_fee': 2, discount: 2, subtotal: 8}, 'next': {}});";
    $this->getSession()->getDriver()->executeScript($javascript);

    $assert = $this->assertSession();
    $assert->elementTextContains('css', '#recurlyjs-subscribe', 'Setup fee');
    $assert->elementTextContains('css', '#recurlyjs-subscribe', 'Discount');
    $assert->elementTextContains('css', '#recurlyjs-subscribe', 'Subtotal');
    $assert->elementTextContains('css', '#recurlyjs-subscribe', 'Total due now');

    // Clicking the purchase button before filling in any of the form should
    // trigger an error validating the CC details.
    $this->getSession()->getPage()->findButton('Purchase')->click();
    $this->assertSession()->pageTextContains('There was an error validating your request.');

    // Mock the message returned from RecurlyJS API for a form validation error
    // and call the custom callback function to verify the message gets
    // displayed.
    $error = 'var err = {
      "name": "api-error",
      "code": "invalid-parameter",
      "message": "Address1 can\'t be empty, City can\'t be empty, Country can\'t be empty, and Postal code can\'t be empty",
      "fields": [
        "address1",
        "city",
        "state",
        "country",
        "postal_code"
      ],
      "details": [
        {
          "field": "address1",
          "messages": [
            "can\'t be empty"
          ]
        },
        {
          "field": "city",
          "messages": [
            "can\'t be empty"
          ]
        },
        {
          "field": "country",
          "messages": [
            "can\'t be empty"
          ]
        },
        {
          "field": "state",
          "messages": [
            "can\'t be empty"
          ]
        },
        {
          "field": "postal_code",
          "messages": [
            "can\'t be empty"
          ]
        }
      ]
    };
    Drupal.recurly.recurlyJSFormError(err);';
    $this->getSession()->getDriver()->executeScript($error);

    // Verify the error message returned from the RecurlyJS API is displayed.
    $this->assertSession()->pageTextContains("Address1 can't be empty, City can't be empty, Country can't be empty, and Postal code can't be empty");
    // Verify the JS adds an error class to fields with errors.
    $fields = [
      'address1',
      'city',
      'state',
      'postal_code',
      'country',
    ];
    foreach ($fields as $field) {
      $this->assertSession()->elementExists('css', '.recurlyjs-form-item__' . $field . '.recurlyjs-form-item__error');
    }

    // @todo add tests for various permutations of the recurly JS form, for
    // example 'full' address vs. 'none' address requirements.
  }

  /**
   * Test that the quantity field is present if configured to appear.
   */
  public function testQuantityFieldSetting() {
    $this->config('recurly.settings')
      ->set('recurly_subscription_multiple', 0)
      ->save();

    $account = $this->drupalCreateUser(['manage recurly subscription']);
    $this->drupalLogin($account);

    RecurlyMockClient::addResponse('GET', '/plans/silver', 'plans/show-200.xml');
    $this->drupalGet('/user/' . $account->id() . '/subscription/signup/silver');

    $this->assertSession()->fieldNotExists('quantity');

    $this->config('recurly.settings')
      ->set('recurly_subscription_multiple', 1)
      ->save();
    $this->drupalGet('/user/' . $account->id() . '/subscription/signup/silver');
    $this->assertSession()->fieldExists('quantity');
  }

}
