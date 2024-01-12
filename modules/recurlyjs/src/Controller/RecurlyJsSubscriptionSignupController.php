<?php

namespace Drupal\recurlyjs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\recurly\RecurlyClientFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Returns responses for Recurly Subscription List.
 */
class RecurlyJsSubscriptionSignupController extends ControllerBase {

  /**
   * Request stack service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The Recurly client.
   *
   * @var \Recurly_Client
   */
  protected $recurlyClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('recurly.client')
    );
  }

  /**
   * Class constructor.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   Request stack service.
   * @param \Drupal\recurly\RecurlyClientFactory $recurly_client_factory
   *   The Recurly client service.
   */
  public function __construct(RequestStack $request_stack, RecurlyClientFactory $recurly_client_factory) {
    $this->requestStack = $request_stack;
    $this->recurlyClient = $recurly_client_factory->getClientFromSettings();
  }

  /**
   * Controller callback to trigger a user subscription.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   A RouteMatchInterface object.
   *
   * @return array
   *   A Drupal render array.
   */
  public function subscribe(RouteMatchInterface $route_match) {
    $entity_type = $this->config('recurly.settings')->get('recurly_entity_type');
    $entity = $route_match->getParameter($entity_type);
    $plan_code = $route_match->getParameter('plan_code');
    $currency = $route_match->getParameter('currency');

    // Ensure the account does not already have this exact same plan. Recurly
    // does not support a single account having multiple of the same plan.
    $local_account = recurly_account_load([
      'entity_type' => $entity_type,
      'entity_id' => $entity->id(),
    ], TRUE);
    if ($local_account) {
      $current_subscriptions = recurly_account_get_subscriptions($local_account->account_code, 'active');
      // If the account is only allowed one subscription total, they shouldn't
      // ever see this signup page.
      if (($this->config('recurly.settings')->get('recurly_subscription_max')) === '1' && count($current_subscriptions) && empty($this->requestStack->getCurrentRequest()->request)) {
        $current_subscription = reset($current_subscriptions);
        $this->messenger()->addMessage($this->t('This account already has a @plan plan!', ['@plan' => $current_subscription->plan->name]));
        if ($url = recurly_url('select_plan', [
          'entity_type' => $entity_type,
          'entity' => $entity,
        ])) {
          return $this->redirect($url->getRouteName(), $url->getRouteParameters());
        }
      }
      // Otherwise check if they already have one of this same plan.
      foreach ($current_subscriptions as $current_subscription) {
        if ($current_subscription->plan->plan_code === $plan_code && empty($this->requestStack->getCurrentRequest()->request)) {
          $this->messenger()->addMessage($this->t('This account already has a @plan plan!', ['@plan' => $current_subscription->plan->name]));
          if ($url = recurly_url('subscribe', [
            'entity_type' => $entity_type,
            'entity' => $entity,
            'plan_code' => $plan_code,
          ])) {
            return $this->redirect($url->getRouteName(), $url->getRouteParameters());
          }
        }
      }
    }

    // Although this controller contains little else besides the subscription
    // form, it's a separate class because it's highly likely to need theming.
    $form = $this->formBuilder()->getForm('Drupal\recurlyjs\Form\RecurlyJsSubscribeForm', $entity_type, $entity, $plan_code, $currency);
    try {
      \Recurly_Plan::get($plan_code, $this->recurlyClient);
    }
    catch (\Recurly_NotFoundError $e) {
      throw new NotFoundHttpException();
    }

    return [
      '#theme' => [
        'recurlyjs_subscribe_page',
      ],
      '#form' => $form,
    ];
  }

}
