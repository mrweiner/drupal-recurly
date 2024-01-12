<?php

namespace Drupal\recurly\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Returns responses for Recurly Subscription List.
 *
 * @todo refactor this code so that the logic for retrieving the list of
 * subscriptions is handled by a service and the controller just deals with
 * displaying data.
 */
class RecurlySubscriptionListController extends RecurlyController {

  /**
   * Cached results of looking up past_due subscriptions.
   *
   * @var array
   */
  private $pastDueCache = [];

  /**
   * Route title callback.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   A RouteMatch object.
   *   Contains information about the route and the entity being acted on.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Recurly subscription details or a no-results message as a render array.
   */
  public function subscriptionList(RouteMatchInterface $route_match) {
    $entity_type_id = $this->config('recurly.settings')->get('recurly_entity_type');
    $entity = $route_match->getParameter($entity_type_id);
    $subscriptions = [];

    $entity_type = $entity->getEntityType()->id();
    $account = recurly_account_load([
      'entity_type' => $entity_type,
      'entity_id' => $entity->id(),
    ]);
    // If the user does not have an account yet, send them to the signup page.
    if (empty($account)) {
      if ($url = recurly_url('select_plan', [
        'entity_type' => $entity_type,
        'entity' => $entity,
      ])) {
        return $this->redirect($url->getRouteName(), $url->getRouteParameters());
      }
      else {
        throw new NotFoundHttpException();
      }
    }

    // Make sure we have a full account, and not just local data.
    if (!$account instanceof \Recurly_Account) {
      throw new NotFoundHttpException('Unable to load account data');
    }

    // Unlikely that we'd have more than 50 subscriptions, but you never know.
    $per_page = 50;
    $subscription_type = $this->config('recurly.settings')->get('recurly_subscription_display');
    $state = $subscription_type === 'live' ? ['state' => 'active'] : [];
    $params = array_merge(['per_page' => $per_page], $state);
    $subscription_list = \Recurly_SubscriptionList::getForAccount($account->account_code, $params, $this->recurlyClient);
    $page_subscriptions = $this->recurlyPageManager->pagerResults($subscription_list, $per_page);

    $subscriptions['subscriptions']['#attached']['library'][] = 'recurly/recurly.default';

    /** @var \Recurly_Subscription $subscription */
    foreach ($page_subscriptions as $subscription) {
      // Determine the state of this subscription.
      $states = $this->subscriptionGetStates($subscription, $account);

      // Ensure that 'canceled' is listed before 'in_trial' in the list of
      // possible states, as this can influence what the summary looks like and
      // displaying the summary for a canceled account even when in trial is
      // less confusing for users who have cancelled their in trial account.
      // Similar for 'expired', which should be listed before 'non_renewing'.
      if (in_array('canceled', $states) || in_array('expired', $states)) {
        sort($states);
      }

      $links = $this->subscriptionLinks($entity_type, $entity, $subscription, $account, $states);

      $plan = $subscription->plan;
      $add_ons = [];
      $total = 0;
      foreach ($subscription->subscription_add_ons as $add_on) {
        // Fully load the add on to get the name attribute.
        $full_add_on = \Recurly_Addon::get($plan->plan_code, $add_on->add_on_code, $this->recurlyClient);
        $add_ons[$add_on->add_on_code] = [
          'add_on_code' => $add_on->add_on_code,
          'name' => Html::escape($full_add_on->name),
          'quantity' => Html::escape($add_on->quantity),
          'cost' => $this->recurlyFormatter->formatCurrency($add_on->unit_amount_in_cents, $subscription->currency),
        ];
        $total += $add_on->unit_amount_in_cents * $add_on->quantity;
      }
      $total += $subscription->unit_amount_in_cents * $subscription->quantity;

      $message = '';
      foreach ($states as $state) {
        $message = $this->subscriptionStateMessage($state, [
          'account' => $account,
          'subscription' => $subscription,
          'entity' => $entity,
          'plan_code' => $subscription->plan->code,
        ]);
        break;
      }

      $subscriptions['subscriptions'][$subscription->uuid] = [
        '#theme' => ['recurly_subscription_summary'],
        '#plan_code' => $plan->plan_code,
        '#plan_name' => Html::escape($plan->name),
        '#state_array' => $states,
        '#state_status' => $this->recurlyFormatter->formatState(reset($states)),
        '#period_end_header' => $this->periodEndHeaderString($states),
        '#cost' => $this->recurlyFormatter->formatCurrency($subscription->unit_amount_in_cents, $subscription->currency),
        '#quantity' => $subscription->quantity,
        '#add_ons' => $add_ons,
        '#start_date' => $this->recurlyFormatter->formatDate($subscription->activated_at),
        '#end_date' => isset($subscription->expires_at) ? $this->recurlyFormatter->formatDate($subscription->expires_at) : NULL,
        '#current_period_start' => $this->recurlyFormatter->formatDate($subscription->current_period_started_at),
        '#current_period_ends_at' => $this->recurlyFormatter->formatDate($subscription->current_period_ends_at),
        '#total' => $this->recurlyFormatter->formatCurrency($total, $subscription->currency),
        '#subscription_links' => [
          '#theme' => 'links',
          '#links' => $links,
          '#attributes' => ['class' => ['inline', 'links']],
        ],
        '#message' => $message,
        '#subscription' => $subscription,
        '#account' => $account,
        // Add custom properties to each subscription via the alter hook below.
        '#custom_properties' => [],
      ];
    }

    $subscriptions['pager'] = [
      '#theme' => 'pager',
      '#access' => $subscription_list->count() > $per_page,
    ];

    // Allow other modules to alter subscriptions.
    $this->moduleHandler()->alter('recurly_subscription_list_page', $subscriptions);

    // If the user doesn't have any active subscriptions, redirect to signup.
    if (count(Element::children($subscriptions['subscriptions'])) === 0) {
      return $this->redirect("entity.$entity_type_id.recurly_signup", [$entity_type_id => $entity->id()]);
    }

    return $subscriptions;
  }

  /**
   * Build a list of links to manage a subscription.
   */
  protected function subscriptionLinks($entity_type, $entity, $subscription, $account, $states) {
    // Generate the list of links for this subscription.
    $url_context = [
      'entity_type' => $entity_type,
      'entity' => $entity,
      'subscription' => $subscription,
      'account' => $account,
    ];

    $links = [];
    if ($subscription->state === 'active') {
      $links['change'] = [
        'url' => recurly_url('change_plan', $url_context),
        'external' => TRUE,
        'title' => $this->t('Change plan'),
      ];
      $links['cancel'] = [
        'url' => recurly_url('cancel', $url_context),
        'external' => TRUE,
        'title' => $this->t('Cancel'),
        // Pass in the past_due flag to accurately calculate refunds.
        'query' => in_array('past_due', $states) ? ['past_due' => '1'] : [],
      ];
    }
    elseif ($subscription->state === 'canceled') {
      $links['reactivate'] = [
        'url' => recurly_url('reactivate', $url_context),
        'external' => TRUE,
        'title' => $this->t('Reactivate'),
      ];
    }

    if ($this->config('recurly.settings')->get('recurly_subscription_multiple')) {
      $links['quantity'] = [
        'url' => recurly_url('quantity', $url_context),
        'external' => TRUE,
        'title' => $this->t('Change quantity'),
      ];
    }

    // Allow other modules to provide links, perhaps "suspend" for example.
    $this->moduleHandler()->alter('recurly_subscription_links', $links, $url_context);

    return $links;
  }

  /**
   * Returns a message for subscription if the subscription state is not active.
   */
  protected function subscriptionStateMessage($state, $context) {
    switch ($state) {
      case 'active':
        return '';

      case 'closed':
        return $this->t('This account is closed.');

      case 'in_trial':
        return $this->t('Currently in trial period.');

      case 'past_due':
        $url = recurly_url('update_billing', $context);
        if ($url) {
          return $this->t('This account is past due. Please <a href=":url">update your billing information</a>.', [':url' => $url->toString()]);
        }
        else {
          return $this->t('This account is past due. Please contact an administrator to update your billing information.');
        }
      case 'canceled':
        $url = recurly_url('reactivate', $context);
        if ($url) {
          return $this->t('This plan is canceled and will not renew. You may <a href=":url">reactivate the plan</a> to resume billing.', [':url' => $url->toString()]);
        }
        else {
          return $this->t('This plan is canceled and will not renew.');
        }
      case 'expired':
        $url = recurly_url('select_plan', $context);
        if ($url) {
          return $this->t('This plan has expired. Please <a href=":url">purchase a new subscription</a>.', [':url' => $url->toString()]);
        }
        else {
          return $this->t('This plan has expired.');
        }
      case 'pending_subscription':
        return $this->t('This plan will be changed to @plan on @date.', [
          '@plan' => $context['subscription']->pending_subscription->plan->name,
          '@date' => $this->recurlyFormatter->formatDate($context['subscription']->current_period_ends_at),
        ]);

      case 'future':
        return $this->t('This plan has not started yet. Please contact support if you have any questions.');

      default:
        return '';
    }
  }

  /**
   * Get a list of all states in which a subscription exists currently.
   *
   * @param object $subscription
   *   A Recurly subscription object.
   * @param object $account
   *   A Recurly account object.
   */
  protected function subscriptionGetStates($subscription, $account) {
    // Only base states ('pending', 'active', 'canceled', 'expired', 'future')
    // will be present in the returned subscription records. So we do some
    // extra work here to figure out additional states like 'in_trial',
    // 'past_due', and 'non_renewing'.
    $states = [];

    // Determine if in a trial.
    if ($subscription->trial_started_at && $subscription->trial_ends_at) {
      $subscription->trial_started_at->setTimezone(new \DateTimeZone('UTC'));
      $subscription->trial_ends_at->setTimezone(new \DateTimeZone('UTC'));
      $start = $subscription->trial_started_at->format('U');
      $end = $subscription->trial_ends_at->format('U');
      if (\Drupal::time()->getRequestTime() > $start && \Drupal::time()->getRequestTime() < $end) {
        $states[] = 'in_trial';
      }
    }

    // Determine if non-renewing.
    if (empty($subscription->auto_renew)) {
      $states[] = 'non_renewing';
    }

    // The base subscription record doesn't indicate if it has any past due
    // invoices or not. The easiest way to figure out if a subscription is
    // past_due is to query for all past_due subscriptions and then compare
    // against that list. This is more efficient than querying for every invoice
    // for every subscription.
    $past_due = $this->getPastDueSubscriptions($account);
    if (in_array($subscription->uuid, $past_due)) {
      $states[] = 'past_due';
    }

    // Subscriptions that have pending changes.
    if (!empty($subscription->pending_subscription)) {
      $states[] = 'pending_subscription';
    }

    $states[] = $subscription->state;
    return $states;
  }

  /**
   * Get an account's past due subscriptions.
   *
   * @param \Recurly_Account $account
   *   Recurly account to retrieve past due subscriptions for.
   *
   * @return array|mixed
   *   An array of UUIDs of any past due subscriptions for the account.
   */
  protected function getPastDueSubscriptions(\Recurly_Account $account) {
    // This can get called multiple times in a loop when displaying more than
    // one subscription so cache the results and reuse them.
    if (isset($this->pastDueCache[$account->account_code])) {
      return $this->pastDueCache[$account->account_code];
    }

    $this->pastDueCache[$account->account_code] = [];
    $subscriptions = \Recurly_SubscriptionList::getForAccount($account->account_code, ['state' => 'past_due'], $this->recurlyClient);

    foreach ($subscriptions as $past_due_subscription) {
      $this->pastDueCache[$account->account_code][] = $past_due_subscription->uuid;
    }

    return $this->pastDueCache[$account->account_code];
  }

  /**
   * Clear the past due subscriptions cache.
   */
  public function clearPastDueCache() {
    $this->pastDueCache = [];
  }

  /**
   * Generates table header string for subscription period end.
   *
   * @param array $states
   *   An array of subscription states.
   *
   * @return string
   *   Text to be used as the table header when the subscription period ends.
   */
  protected function periodEndHeaderString(array $states) {
    if (count(array_intersect(['canceled', 'non_renewing', 'expired'], $states))
      && !in_array('in_trial', $states)) {
      return $this->t('Expiration Date');
    }
    return $this->t('Next Invoice');
  }

}
