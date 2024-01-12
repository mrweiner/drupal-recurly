<?php

namespace Drupal\recurlyjs\Routing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Subscriber for Recurly routes.
 */
class RecurlyJsRouteSubscriber extends RouteSubscriberBase {

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new RouteSubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    $entity_type_id = $this->configFactory->get('recurly.settings')->get('recurly_entity_type');
    $entity_type_manager_definitions = $this->entityTypeManager->getDefinitions();
    $entity_type = $entity_type_manager_definitions[$entity_type_id];
    $options = [
      '_admin_route' => TRUE,
      '_recurly_entity_type_id' => $entity_type_id,
      'parameters' => [
        $entity_type_id => [
          'type' => 'entity:' . $entity_type_id,
        ],
      ],
    ];
    if ($recurlyjs_signup = $entity_type->getLinkTemplate('recurlyjs-signup')) {
      // Create the route object.
      $route = new Route(
        $recurlyjs_signup,
        [
          '_controller' => '\Drupal\recurlyjs\Controller\RecurlyJsSubscriptionSignupController::subscribe',
          '_title' => 'Signup',
          'operation' => 'signup',
        ],
        [
          '_entity_access' => "$entity_type_id.update",
          '_access_check_recurly_user' => 'TRUE',
          '_access_check_recurly_list' => 'TRUE',
          '_access_check_recurly_signup' => 'TRUE',
        ],
        $options
      );
      // Give it a name and add it to the route collection.
      $collection->add("entity.$entity_type_id.recurlyjs_signup", $route);
    }
    if ($recurlyjs_billing = $entity_type->getLinkTemplate('recurlyjs-billing')) {
      $route = new Route(
        $recurlyjs_billing,
        [
          '_form' => '\Drupal\recurlyjs\Form\RecurlyJsUpdateBillingForm',
          '_title' => 'Update billing information',
          'operation' => 'update_billing',
        ],
        [
          '_entity_access' => "$entity_type_id.update",
          '_access_check_recurly_user' => 'TRUE',
          '_access_check_recurly_list' => 'TRUE',
          '_access_check_recurly_local_account' => 'TRUE',
        ],
        $options
      );

      $collection->add("entity.$entity_type_id.recurlyjs_billing", $route);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() : array {
    $events = parent::getSubscribedEvents();
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', 100];
    return $events;
  }

}
