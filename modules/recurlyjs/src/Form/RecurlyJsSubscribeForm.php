<?php

namespace Drupal\recurlyjs\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\recurlyjs\Event\SubscriptionAlter;
use Drupal\recurlyjs\Event\SubscriptionCreated;
use Drupal\recurlyjs\RecurlyJsEvents;

/**
 * RecurlyJS subscribe form.
 */
class RecurlyJsSubscribeForm extends RecurlyJsFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'recurlyjs_subscribe';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $entity_type = NULL, $entity = NULL, $plan_code = NULL, $currency = NULL) {
    if (!$entity_type || !$entity || !$plan_code) {
      // @todo Replace exception.
      throw new Exception();
    }

    $form = parent::buildForm($form, $form_state);
    $form['#entity_type'] = $entity_type;
    $form['#entity'] = $entity;
    $form['#plan_code'] = $plan_code;
    $form['#currency'] = $currency ?: $this->config('recurly.settings')->get('recurly_default_currency') ?: 'USD';

    // Display a summary of what the user is about to purchase.
    $currency = $this->config('recurly.settings')->get('recurly_default_currency');
    $plan = \Recurly_Plan::get($plan_code, $this->recurlyClient);
    $unit_amount = NULL;
    foreach ($plan->unit_amount_in_cents as $unit_currency) {
      if ($unit_currency->currencyCode === $currency) {
        $unit_amount = $this->formatManager->formatCurrency($unit_currency->amount_in_cents, $unit_currency->currencyCode, TRUE);
        break;
      }
    }
    $frequency = $this->formatManager->formatPriceInterval($unit_amount, $plan->plan_interval_length, $plan->plan_interval_unit, FALSE);

    $form['plan'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Plan'),
      '#weight' => -350,
    ];
    $form['plan']['plan_name'] = [
      '#markup' => '<div class="recurlyjs-element recurlyjs-plan-name">' . HTML::escape($plan->name) . ' <span class="recurlyjs-plan-frequency">(' . $frequency . ')</span></div>',
    ];
    $form['plan']['plan_description'] = [
      '#markup' => '<div class="recurlyjs-element recurlyjs-plan-description">' . HTML::escape($plan->description) . '</span></div>',
    ];
    $form['plan']['addons'] = [
      '#markup' => '<h2 id="addons-title" class="recurlyjs-element__hidden">Add-ons</h2><div id="addons"></div>',
    ];

    // Use the recurly.js recurly.Pricing() module to display a summary of what
    // the user is going to purchase. For more information about
    // recurly.Pricing() see https://dev.recurly.com/docs/recurly-js-pricing.
    $form['plan']['pricing'] = [
      '#type' => '#container',
      '#attributes' => [
        'class' => ['recurlyjs-pricing'],
      ],
    ];
    // For the recurly.Pricing() module, we need to make sure we include an
    // input field with the plan name.
    $form['plan']['pricing']['plan_code'] = [
      '#type' => 'hidden',
      '#value' => $plan_code,
      '#attributes' => [
        'data-recurly' => 'plan',
      ],
    ];

    // Setup fee. Hidden by default, populated by JS as needed.
    $form['plan']['pricing']['plan_setup'] = [
      '#markup' => '<div class="recurlyjs-element recurlyjs-setup-fee recurlyjs-element__hidden">' . $this->t('Setup fee:') . ' <span data-recurly="currency_symbol"></span><span data-recurly="setup_fee_now"></span></div>',
    ];

    // Discount. Hidden by default, populated by JS as needed.
    $form['plan']['pricing']['plan_discount'] = [
      '#markup' => '<div class="recurlyjs-element recurlyjs-discount recurlyjs-element__hidden">' . $this->t('Discount:') . ' <span data-recurly="currency_symbol"></span><span data-recurly="discount_now"></span></div>',
    ];

    // Sub total. Hidden by default, populated by JS as needed.
    $form['plan']['pricing']['plan_subtotal'] = [
      '#markup' => '<div class="recurlyjs-element recurlyjs-subtotal recurlyjs-element__hidden">' . $this->t('Subtotal:') . ' <span data-recurly="currency_symbol"></span><span data-recurly="subtotal_now"></span></div>',
    ];

    // Taxes. Hidden by default, populated by JS as needed.
    $form['plan']['pricing']['plan_tax'] = [
      '#markup' => '<div class="recurlyjs-element recurlyjs-tax recurlyjs-element__hidden">' . $this->t('Taxes:') . ' <span data-recurly="currency_symbol"></span><span data-recurly="tax_now"></span></div>',
    ];

    // Sub total. Hidden by default, populated by JS as needed.
    $form['plan']['pricing']['plan_total'] = [
      '#markup' => '<div class="recurlyjs-element recurlyjs-total recurlyjs-element__hidden">' . $this->t('Total due now:') . ' <span data-recurly="currency_symbol"></span><span data-recurly="total_now"></span></div>',
    ];

    if ($this->config('recurlyjs.settings')->get('recurlyjs_enable_quantity') || $this->config('recurlyjs.settings')->get('recurlyjs_enable_coupons')) {
      $form['billing_extras'] = [
        '#type' => 'fieldset',
      ];
    }

    // Controlled by a setting in the base recurly module.
    if ($this->config('recurly.settings')->get('recurly_subscription_multiple')) {
      $form['billing_extras']['quantity'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Quantity'),
        '#description' => $this->t('Number of subscriptions to this plan.'),
        '#size' => 3,
        '#default_value' => $this->getRequest()->query->get('quantity', 1),
        '#attributes' => [
          'data-recurly' => 'plan_quantity',
        ],
        '#weight' => '-300',
      ];
    }

    if ($this->config('recurlyjs.settings')->get('recurlyjs_enable_coupons')) {
      $form['billing_extras']['coupon_code'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Coupon Code'),
        '#description' => $this->t('Recurly coupon code to be applied to subscription.'),
        '#element_validate' => ['::validateCouponCode'],
        '#attributes' => [
          'data-recurly' => 'coupon',
        ],
        '#weight' => -50,
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Purchase'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $entity_type = $form['#entity_type'];
    $entity = $form['#entity'];
    $plan_code = $form['#plan_code'];
    $currency = $form['#currency'];
    $recurly_token = $form_state->getValue('recurly-token');
    $coupon_code = $form_state->getValue('coupon_code');
    $quantity = $form_state->getValue('quantity') ? $form_state->getValue('quantity') : 1;
    $recurly_account = recurly_account_load([
      'entity_type' => $entity_type,
      'entity_id' => $entity->id(),
    ]);
    if (!$recurly_account) {
      $recurly_account = new \Recurly_Account(NULL, $this->recurlyClient);
      $recurly_account->first_name = Html::escape($form_state->getValue('first_name'));
      $recurly_account->last_name = Html::escape($form_state->getValue('last_name'));

      if ($entity_type == 'user') {
        $recurly_account->email = $entity->getEmail();
        $recurly_account->username = $entity->getAccountName();
      }

      // Use token mapping for new recurly account fields.
      foreach ($this->tokenManager->tokenMapping() as $recurly_field => $token) {
        $recurly_account->{$recurly_field} = $this->token->replace(
          $token,
          [$entity_type => $entity],
          ['clear' => TRUE, 'sanitize' => FALSE]
        );
      }

      // Account code is the only property required for account creation.
      // https://dev.recurly.com/docs/create-an-account.
      $recurly_account->account_code = $entity_type . '-' . $entity->id();
    }

    $subscription = new \Recurly_Subscription(NULL, $this->recurlyClient);
    $subscription->account = $recurly_account;
    $subscription->plan_code = $plan_code;
    $subscription->currency = $currency;
    $subscription->coupon_code = $coupon_code;
    $subscription->quantity = $quantity;

    // Allow other modules the chance to alter the new Recurly Subscription
    // object before it is saved.
    $event = new SubscriptionAlter($subscription, $entity, $plan_code);
    $this->eventDispatcher->dispatch($event, RecurlyJsEvents::SUBSCRIPTION_ALTER);
    $subscription = $event->getSubscription();

    // Billing info is based on the token we retrieved from the Recurly JS API
    // and should only contain the token in this case. We add this after the
    // above alter hook to ensure it's not modified.
    $subscription->account->billing_info = new \Recurly_BillingInfo(NULL, $this->recurlyClient);
    $subscription->account->billing_info->token_id = $recurly_token;

    try {
      // This saves all of the data assembled above in addition to creating a
      // new subscription record.
      $subscription->create();
    }
    catch (\Recurly_ValidationError $e) {
      // There was an error validating information in the form. For example,
      // credit card was declined. We don't need to log these in Drupal, you can
      // find the errors logged within Recurly.
      $this->messenger()->addError($this->t('<strong>Unable to create subscription:</strong><br/>@error', ['@error' => $e->getMessage()]));
      $form_state->setRebuild(TRUE);
      return;
    }
    catch (\Recurly_Error $e) {
      // Catch any non-validation errors. This will be things like unable to
      // contact Recurly API, or lower level errors. Display a generic message
      // to the user letting them know there was an error and then log the
      // detailed version. There's probably nothing a user can do to correct
      // these errors so we don't need to display the details.
      $this->logger('recurlyjs')->error('Unable to create subscription. Received the following error: @error', ['@error' => $e->getMessage()]);
      $this->messenger()->addError($this->t('Unable to create subscription.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    // Allow other modules to react to the new subscription being created.
    $event = new SubscriptionCreated($subscription, $entity, $plan_code);
    $this->eventDispatcher->dispatch($event, RecurlyJsEvents::SUBSCRIPTION_CREATED);
    $subscription = $event->getSubscription();

    $this->messenger()->addMessage($this->t('Account upgraded to @plan!', ['@plan' => $subscription->plan->name]));
    // Save the account locally immediately so that subscriber information may
    // be retrieved when the user is directed back to the /subscription tab.
    try {
      $account = $subscription->account->get();
      recurly_account_save($account, $entity_type, $entity->id());
    }
    catch (\Recurly_Error $e) {
      $this->logger('recurlyjs')->error('New subscriber account could not be retreived from Recurly. Received the following error: @error', ['@error' => $e->getMessage()]);
    }
    return $form_state->setRedirect("entity.$entity_type.recurly_subscriptionlist", [
      $entity->getEntityType()->id() => $entity->id(),
    ]);
  }

  /**
   * Element validate callback.
   */
  public function validateCouponCode($element, &$form_state, $form) {
    $coupon_code = $form_state->hasValue('coupon_code') ? $form_state->getValue('coupon_code') : NULL;
    if (!$coupon_code) {
      return;
    }
    $currency = $form['#currency'];
    $plan_code = $form['#plan_code'];

    // Query Recurly to make sure this is a valid coupon code.
    try {
      $coupon = \Recurly_Coupon::get($coupon_code, $this->recurlyClient);
    }
    catch (\Recurly_NotFoundError $e) {
      $form_state->setError($element, $this->t('The coupon code you have entered is not valid.'));
      return;
    }
    // Check that the coupon is available in the specified currency.
    if ($coupon && !in_array($coupon->discount_type, ['percent', 'free_trial'])) {
      if (!$coupon->discount_in_cents->offsetExists($currency)) {
        $form_state->setError($element, $this->t('The coupon code you have entered is not valid in @currency.', ['@currency' => $currency]));
        return;
      }
    }
    // Check the the coupon is valid for the specified plan.
    if ($coupon && !$this->couponValidForPlan($coupon, $plan_code)) {
      $form_state->setError($element, $this->t('The coupon code you have entered is not valid for the specified plan.'));
      return;
    }
  }

  /**
   * Validate Recurly coupon against a specified plan.
   *
   * @todo Move to recurly.module?
   *
   * @param \Recurly_Coupon $recurly_coupon
   *   A Recurly coupon object.
   * @param string $plan_code
   *   A Recurly plan code.
   *
   * @return BOOL
   *   TRUE if the coupon is valid for the specified plan, else FALSE.
   */
  protected function couponValidForPlan(\Recurly_Coupon $recurly_coupon, $plan_code) {
    return ($recurly_coupon->applies_to_all_plans || in_array($plan_code, $recurly_coupon->plan_codes));
  }

}
