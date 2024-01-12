<?php

namespace Drupal\recurly\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Recurly change quantity form.
 */
class RecurlyChangeQuantityForm extends RecurlyNonConfigForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'recurly_change_quantity_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, RouteMatchInterface $route_match = NULL) {
    $entity_type_id = $this->config('recurly.settings')->get('recurly_entity_type');
    $entity = $route_match->getParameter($entity_type_id);
    $entity_type = $entity->getEntityType()->id();
    $subscription_id = $route_match->getParameter('subscription_id');

    $form['#entity_type'] = $entity_type;
    $form['#entity'] = $entity;

    if (!isset($form['#current_subscription'])) {
      try {
        $subscription = \Recurly_Subscription::get($subscription_id, $this->recurlyClient);
      }
      catch (\Recurly_NotFoundError $e) {
        $this->messenger()->addError('Unable to retrieve subscription information. Please try again or contact an administrator.');
        $this->logger('recurly')->error('Unable to retrieve subscription information: @error', [$e->getMessage()]);
        return $form;
      }

      $form['#current_subscription'] = $subscription;
    }

    // De-reference because calling $subscription->preview() can make changes
    // that we don't want to preserve.
    $subscription = clone($form['#current_subscription']);

    if ($form_state->get('do_preview') === TRUE) {
      // Figure out the current rate before we preview the new rate.
      $current_due = $this->recurlyFormatter->formatCurrency(($subscription->unit_amount_in_cents * $subscription->quantity), $subscription->currency);

      $old_quantity = $subscription->quantity;
      $new_quantity = (int) $form_state->get('preview_quantity');

      // Generate a preview of the quantity change and use the resulting
      // preview invoices to calculate the cost of this change.
      $subscription->quantity = $new_quantity;
      $subscription->preview();

      $invoices = [];
      /** @var \Recurly_Invoice $invoice */
      if (!empty($subscription->invoice_collection->charge_invoice)) {
        $invoice = $subscription->invoice_collection->charge_invoice;

        $invoices[] = [
          '#theme' => 'recurly_invoice',
          '#attached' => [
            'library' => [
              'recurly/recurly.invoice',
            ],
          ],
          '#invoice' => $invoice,
          '#invoice_account' => $invoice->account,
          '#entity_type' => $entity_type,
          '#entity' => $entity,
          '#error_message' => NULL,
        ];
      }

      if (!empty($subscription->invoice_collection->credit_invoices)) {
        foreach ($subscription->invoice_collection->credit_invoices as $invoice) {
          $invoices[] = [
            '#theme' => 'recurly_invoice',
            '#attached' => [
              'library' => [
                'recurly/recurly.invoice',
              ],
            ],
            '#invoice' => $invoice,
            '#invoice_account' => $invoice->account,
            '#entity_type' => $entity_type,
            '#entity' => $entity,
            '#error_message' => NULL,
          ];
        }
      }

      $plan_name = $subscription->plan->name;
      $due_next = $this->recurlyFormatter->formatCurrency($subscription->cost_in_cents, $subscription->currency);

      $form['preview'] = [
        '#type' => 'fieldset',
        '#title' => 'Preview changes',
        '#cache' => [
          'max-age' => 0,
        ],
      ];
      $form['preview']['due_note'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('You are changing from <strong>@old_quantity x @plan</strong> (@current_due) to <strong>@new_quantity x @plan</strong> (@due_next). Changes take effect immediately.',
            [
              '@old_quantity' => $old_quantity,
              '@plan' => $plan_name,
              '@current_due' => $current_due,
              '@new_quantity' => $new_quantity,
              '@due_next' => $due_next,
            ]) . '</p>',
      ];

      if (count($invoices)) {
        $form['preview']['invoices_note'] = [
          '#type' => 'markup',
          '#markup' => '<p>' . $this->t('In addition to changing the rate above this change will result in the following prorated charges or credits being issued:') . '</p>',
        ];
        $form['preview']['invoices'] = $invoices;
      }
    }

    $form['quantity'] = [
      '#type' => 'textfield',
      '#title' => $this->t('New quantity'),
      '#size' => 3,
      '#default_value' => 1,
    ];

    // Set the default properly, if we have a subscription.
    $form['quantity']['#default_value'] = $subscription->quantity;

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Preview quantity change'),
      '#name' => 'preview',
    ];

    $form['actions']['confirm'] = [
      '#type' => 'submit',
      '#value' => $this->t('Confirm quantity change'),
      '#name' => 'confirm',
      '#access' => ($form_state->get('do_preview') === TRUE),
    ];

    $form['actions']['cancel'] = $entity->toLink($this->t('Cancel'), 'recurly-subscriptionlist')->toRenderable();

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Make sure we're dealing with a positive integer.
    if ($form_state->getValue('quantity') < 1) {
      $form_state->setErrorByName('quantity', $this->t('Please enter a valid quantity'));
    }

    // The new quantity value must match what was used in the preview when
    // submitting the update.
    if ($form_state->getTriggeringElement()['#name'] === 'confirm') {
      if ($form_state->getValue('quantity') !== $form_state->get('preview_quantity')) {
        $form_state->setErrorByName('quantity', $this->t('Previewed quantity must match submitted quantity. Please update the preview and try again.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Recurly_Subscription $subscription */
    $subscription = $form['#current_subscription'];

    if ($subscription) {
      if ($form_state->getTriggeringElement()['#name'] === 'preview') {
        // Rebuild the form and display a preview of the change.
        $form_state
          ->set('preview_quantity', $form_state->getValue('quantity'))
          ->set('do_preview', TRUE)
          ->setRebuild();
      }
      elseif ($form_state->getTriggeringElement()['#name'] === 'confirm') {
        // Get a new $subscription object that also has an active Recurly client
        // because the one serialized into the form data won't work.
        $active_subscription = \Recurly_Subscription::get($subscription->uuid, $this->recurlyClient);
        // Set the new quantity.
        $active_subscription->quantity = $form_state->getValue('quantity');

        $success = FALSE;
        try {
          // Update immediately.
          // @todo allow user to choose when this happens?
          $active_subscription->updateImmediately();
          $success = TRUE;
        }
        catch (\Recurly_Error $e) {
          $this->messenger()->addError('Unable to update subscription quantity. Please try again or contact an administrator.');
          $this->logger('recurly')->error('Unable to update subscription quantity: @error', [$e->getMessage()]);
          $form_state->setRebuild();
        }

        if ($success) {
          $entity_type = $form['#entity_type'];
          $entity = $form['#entity'];
          $this->messenger()
            ->addMessage($this->t('Your subscription has been updated.'));
          $form_state->setRedirect("entity.$entity_type.recurly_subscriptionlist", [$entity_type => $entity->id()]);
        }
      }
    }
  }

}
