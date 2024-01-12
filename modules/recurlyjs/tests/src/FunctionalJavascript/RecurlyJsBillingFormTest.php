<?php

namespace Drupal\Tests\recurlyjs\FunctionalJavascript;

use Drupal\recurly_test_client\RecurlyMockClient;

/**
 * Tests for RecurlyJsBillingForm.
 *
 * The form itself is the same form used in RecurlyJsSubscribeForm so here we
 * only test the things specific to the billing form controller.
 *
 * @group recurly
 * @covers \Drupal\recurlyjs\Form\RecurlyJsUpdateBillingForm;
 */
class RecurlyJsBillingFormTest extends RecurlyJsWebDriverTestBase {

  /**
   * User can not access billing form without a locally stored recurly account.
   */
  public function testBillingFormAccess() {
    $account = $this->drupalCreateUser(['manage recurly subscription']);
    $this->drupalLogin($account);

    $this->drupalGet('/user/' . $account->id() . '/subscription/billing');
    $this->assertSession()->pageTextContains('You are not authorized to access this page');
  }

  /**
   * Test validation and submission of the billing info update form.
   */
  public function testBillingForm() {
    $account = $this->createUserWithSubscription();
    $this->drupalLogin($account);

    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890', 'accounts/show-200.xml');
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/subscriptions?per_page=200', 'subscriptions/index-200-single.xml');

    // Visit the page first with no valid response for the billing_info request
    // and verify the error is caught.
    $this->drupalGet('/user/' . $account->id() . '/subscription/billing');
    $this->assertSession()->pageTextContains('Unable to retrieve billing information');

    // Add a mock for the billing_info request and try again.
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/billing_info', 'billing_info/show-200.xml');
    $this->drupalGet('/user/' . $account->id() . '/subscription/billing');

    $this->assertSession()->pageTextNotContains('Unable to retrieve billing information');
    $this->assertSession()->pageTextContains('We currently have the following credit card on file');
    // Display data from the billing_info mock, and properly mask the CC number.
    $this->assertSession()->pageTextContains('Visa Exp: 01/2049');
    $this->assertSession()->pageTextContains('xxxxxxxxxxxx1111');

    // Test that form gets prepopulated with values from Recurly. Use input
    // element IDs instead of names because the form code strips the name
    // attribute per Recurly JS recommendations.
    $this->assertSession()->fieldValueEquals('edit-first-name', 'Larry');
    $this->assertSession()->fieldValueEquals('edit-last-name', 'David');
    $this->assertSession()->fieldValueEquals('edit-address1', '123 Pretty Pretty Good St.');
    $this->assertSession()->fieldValueEquals('edit-city', 'Los Angeles');
    $this->assertSession()->fieldValueEquals('edit-state', 'CA');
    $this->assertSession()->fieldValueEquals('edit-postal-code', '90210');

    // Make sure all the RecurlyJS iframes have a 'name' attribute. They don't
    // by default, and we need one to be able to switch to the frame. So we use
    // the CSS class of the parent element.
    $javascript = "document.querySelectorAll('iframe').forEach(function(f) { f.setAttribute('name', f.parentElement.classList[f.parentElement.classList.length -1]); })";
    $this->getSession()->executeScript($javascript);

    // Try an invalid CC and ensure validation works.
    //
    // This also validates that the Recurly JS CC#, CVV, Month, and Year fields
    // get added to the page.
    $this->getSession()->switchToIFrame('recurly-hosted-field-number');
    $this->getSession()->getPage()->fillField('recurly-hosted-field-input', '4222222222222222');

    $this->getSession()->switchToWindow();
    $this->getSession()->switchToIFrame('recurly-hosted-field-cvv');
    $this->getSession()->getPage()->fillField('recurly-hosted-field-input', '666');

    $this->getSession()->switchToWindow();
    $this->getSession()->switchToIFrame('recurly-hosted-field-month');
    $this->getSession()->getPage()->fillField('recurly-hosted-field-input', '03');

    $this->getSession()->switchToWindow();
    $this->getSession()->switchToIFrame('recurly-hosted-field-year');
    $this->getSession()->getPage()->fillField('recurly-hosted-field-input', '2030');

    // Switch back to the main frame. And submit the invalid data.
    $this->getSession()->switchToWindow();
    $this->getSession()->getPage()->pressButton('Update');

    $this->assertSession()->pageTextContains('There was an error validating your request');
    $this->assertSession()->elementTextContains('css', '#recurly-form-errors', 'Credit Card number');

    // Switch to a known good CC number.
    $this->getSession()->switchToIFrame('recurly-hosted-field-number');
    $this->getSession()->getPage()->fillField('recurly-hosted-field-input', '4111111111111111');
    $this->getSession()->switchToWindow();

    // Tests for logic in
    // \Drupal\recurlyjs\Form\RecurlyJsUpdateBillingForm::submitForm().
    // First, invalid token, should return an \Recurly_NotFoundError.
    $this->removeJsFromForm();
    $this->submitForm([], 'Update');
    $this->assertSession()->pageTextContains('Could not find account or token is invalid or expired');

    // Then, if Recurly returns a validation error, we should get a
    // \Recurly_ValidationError.
    RecurlyMockClient::addResponse('PUT', '/accounts/abcdef1234567890/billing_info', 'billing_info/verify-422.xml');
    $this->removeJsFromForm();
    $this->submitForm([], 'Update');
    // Text comes from the mock error response.
    $this->assertSession()->pageTextContains('Only stored credit card billing information can be verified at this time');

    // And, if we just get some other random error from Recurly that should
    // result in an \Recurly_Error.
    RecurlyMockClient::addResponse('PUT', '/accounts/abcdef1234567890/billing_info', 'client/server-error-500.xml');
    $this->removeJsFromForm();
    $this->submitForm([], 'Update');
    // Text comes from the mock error response.
    $this->assertSession()->pageTextContains('An error occured while trying to update your account. Please contact a site administrator');
  }

  /**
   * Helper method to undbind Recurly JS from billing form.
   *
   * Normally this form needs to call recurly.token(), RecurlyJS does it's work
   * and sets the recurly-token field value. And then the form, without all the
   * CC details, is submitted to Drupal. For testing, we can bypass the Recurly
   * JS API by un-binding the JS callback and spoofing the recurly-token value.
   */
  protected function removeJsFromForm() {
    $this->getSession()->executeScript("document.querySelectorAll('.recurly-form-wrapper form').forEach(function(el) { el.removeEventListener('submit', Drupal.recurly.recurlyJSTokenFormSubmit); });");
    $this->getSession()->executeScript("document.querySelector('[name=\"recurly-token\"]').value = '1234-asdf'");
  }

}
