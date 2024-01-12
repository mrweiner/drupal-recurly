<?php

namespace Drupal\recurlyjs\Form;

use Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Locale\CountryManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\recurly\RecurlyClientFactory;
use Drupal\recurly\RecurlyFormatManager;
use Drupal\recurly\RecurlyTokenManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * RecurlyJS abstract class with common form elements to be shared.
 */
abstract class RecurlyJsFormBase extends FormBase {

  /**
   * The country manager service.
   *
   * @var \Drupal\Core\Locale\CountryManagerInterface
   */
  protected $countryManager;

  /**
   * The event dispatcher service.
   *
   * @var \Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher
   */
  protected $eventDispatcher;

  /**
   * The Recurly client service, initialized on construction.
   *
   * @var \Recurly_Client
   */
  protected $recurlyClient;

  /**
   * The Recurly format manager service.
   *
   * @var \Drupal\recurly\RecurlyFormatManager
   */
  protected $formatManager;

  /**
   * The Recurly token manager service.
   *
   * @var \Drupal\recurly\RecurlyTokenManager
   */
  protected $tokenManager;

  /**
   * Drupal token service container.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Current billing info to optionally pre-populate form fields.
   *
   * @var \Recurly_BillingInfo
   */
  protected $billingInfo;

  /**
   * Creates a RecurlyJS base form.
   *
   * @param \Drupal\Core\Locale\CountryManagerInterface $country_manager
   *   The country manager service.
   * @param \Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher $event_dispatcher
   *   The event dispatcher service.
   * @param \Drupal\recurly\RecurlyClientFactory $recurly_client_factory
   *   The Recurly client service.
   * @param \Drupal\recurly\RecurlyFormatManager $format_manager
   *   The Recurly format manager.
   * @param \Drupal\recurly\RecurlyTokenManager $token_manager
   *   The Recurly token manager.
   * @param \Drupal\Core\Utility\Token $token
   *   Drupal token service.
   */
  public function __construct(
    CountryManagerInterface $country_manager,
    ContainerAwareEventDispatcher $event_dispatcher,
    RecurlyClientFactory $recurly_client_factory,
    RecurlyFormatManager $format_manager,
    RecurlyTokenManager $token_manager,
    Token $token
  ) {
    $this->countryManager = $country_manager;
    $this->eventDispatcher = $event_dispatcher;
    $this->recurlyClient = $recurly_client_factory->getClientFromSettings();
    $this->formatManager = $format_manager;
    $this->tokenManager = $token_manager;
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('country_manager'),
      $container->get('event_dispatcher'),
      $container->get('recurly.client'),
      $container->get('recurly.format_manager'),
      $container->get('recurly.token_manager'),
      $container->get('token')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'recurlyjs/recurlyjs.default';
    $form['#attached']['library'][] = 'recurlyjs/recurlyjs.recurlyjs';

    $form['#attached']['drupalSettings']['recurlyjs']['recurly_public_key'] = $this->config('recurly.settings')->get('recurly_public_key') ?: '';
    $form['#attached']['library'][] = 'recurlyjs/recurlyjs.configure';
    // @FIXME: Include inline call to configure RecurlyJS.
    // @see: https://github.com/CHROMATIC-LLC/recurly/blob/7.x-2.x/modules/recurlyjs/includes/recurlyjs.pages.inc#L510-L513
    $form['#attached']['library'][] = 'recurlyjs/recurlyjs.element';

    return $this->appendBillingFields($form);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * Configure Form API elements for Recurly billing forms.
   *
   * @param array $form
   *   A Drupal form array.
   *
   * @return array
   *   The modified form array.
   */
  private function appendBillingFields(array $form) {
    $form['#prefix'] = '<div class="recurly-form-wrapper">';
    $form['#suffix'] = '</div>';

    $form['billing'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Payment Information'),
      '#attributes' => [
        'class' => ['recurlyjs-billing-info'],
      ],
    ];
    // recurly-element.js adds errors here upon failed validation.
    $form['errors'] = [
      '#markup' => '<div id="recurly-form-errors"></div>',
      '#weight' => -300,
    ];
    $form['billing']['name'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['recurlyjs-name-wrapper'],
      ],
    ];
    $form['billing']['name']['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First Name'),
      '#attributes' => [
        'data-recurly' => 'first_name',
      ],
      '#prefix' => '<div class="recurlyjs-form-item__first_name">',
      '#suffix' => '</div>',
      '#default_value' => !empty($this->billingInfo) ? $this->billingInfo->first_name : '',
    ];
    $form['billing']['name']['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last Name'),
      '#attributes' => [
        'data-recurly' => 'last_name',
      ],
      '#prefix' => '<div class="recurlyjs-form-item__last_name">',
      '#suffix' => '</div>',
      '#default_value' => !empty($this->billingInfo) ? $this->billingInfo->last_name : '',
    ];
    $form['billing']['cc_info'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['recurlyjjs-cc-info'],
      ],
    ];
    // Credit card fields are represented as <divs> in the DOM and Recurly.JS
    // will dynamically replace them with an input field inside of an iFrame. In
    // order to ensure these fields never contain data in Drupal's Form API we
    // just add them as static markup.
    $form['billing']['cc_info']['number'] = [
      '#title' => $this->t('Card Number'),
      '#markup' => '<label for="number">' . $this->t('Card Number') . '</label><div data-recurly="number"></div>',
      '#allowed_tags' => ['label', 'div'],
      '#prefix' => '<div class="form-item recurlyjs-form-item__number">',
      '#suffix' => '<span class="recurlyjs-icon-card recurlyjs-icon-card__inline recurlyjs-icon-card__unknown"></span></div>',
    ];
    $form['billing']['cc_info']['cvv'] = [
      '#title' => $this->t('CVV'),
      '#markup' => '<label for="cvv">' . $this->t('CVV') . '</label><div data-recurly="cvv"></div>',
      '#allowed_tags' => ['label', 'div'],
      '#prefix' => '<div class="form-item recurlyjs-form-item__cvv">',
      '#suffix' => '</div>',
    ];
    $form['billing']['cc_info']['month'] = [
      '#title' => $this->t('Month'),
      '#markup' => '<label for="month">' . $this->t('Month') . '</label><div data-recurly="month"></div>',
      '#allowed_tags' => ['label', 'div'],
      '#prefix' => '<div class="form-item recurlyjs-form-item__month">',
      '#suffix' => '</div>',
    ];
    $form['billing']['cc_info']['year'] = [
      '#title' => $this->t('Year'),
      '#markup' => '<label for="year">' . $this->t('Year') . '</label><div data-recurly="year"></div>',
      '#allowed_tags' => ['label', 'div'],
      '#prefix' => '<div class="form-item recurlyjs-form-item__year">',
      '#suffix' => '</div>',
    ];

    $address_requirement = $this->config('recurlyjs.settings')->get('recurlyjs_address_requirement') ?: 'full';
    $hide_vat_number = $this->config('recurlyjs.settings')->get('recurlyjs_hide_vat_number') ?: 0;

    if (in_array($address_requirement, [
      'zipstreet',
      'full',
    ])) {
      $form['billing']['address1'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Address Line 1'),
        '#attributes' => [
          'data-recurly' => 'address1',
        ],
        '#prefix' => '<div class="recurlyjs-form-item__address1">',
        '#suffix' => '</div>',
        '#default_value' => !empty($this->billingInfo) ? $this->billingInfo->address1 : '',
      ];
      $form['billing']['address2'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Address Line 2'),
        '#attributes' => [
          'data-recurly' => 'address2',
        ],
        '#prefix' => '<div class="recurlyjs-form-item__address2">',
        '#suffix' => '</div>',
        '#default_value' => !empty($this->billingInfo) ? $this->billingInfo->address2 : '',
      ];
    }
    $form['billing']['city_state_postal'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['recurlyjs-city-state-postal-wrapper'],
      ],
    ];

    if ($address_requirement == 'full') {
      $form['billing']['city_state_postal']['city'] = [
        '#type' => 'textfield',
        '#title' => $this->t('City'),
        '#attributes' => [
          'data-recurly' => 'city',
        ],
        '#prefix' => '<div class="recurlyjs-form-item__city">',
        '#suffix' => '</div>',
        '#default_value' => !empty($this->billingInfo) ? $this->billingInfo->city : '',
      ];
      $form['billing']['city_state_postal']['state'] = [
        '#type' => 'textfield',
        '#title' => $this->t('State'),
        '#attributes' => [
          'data-recurly' => 'state',
        ],
        '#prefix' => '<div class="recurlyjs-form-item__state">',
        '#suffix' => '</div>',
        '#default_value' => !empty($this->billingInfo) ? $this->billingInfo->state : '',
      ];
    }

    if ($address_requirement != 'none') {
      $form['billing']['city_state_postal']['postal_code'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Postal Code'),
        '#attributes' => [
          'data-recurly' => 'postal_code',
        ],
        '#prefix' => '<div class="recurlyjs-form-item__postal_code">',
        '#suffix' => '</div>',
        '#default_value' => !empty($this->billingInfo) ? $this->billingInfo->zip : '',
      ];
    }

    if ($address_requirement == 'full') {
      $countries = $this->countryManager->getList();
      $form['billing']['country'] = [
        '#type' => 'select',
        '#title' => $this->t('Country'),
        '#attributes' => [
          'data-recurly' => 'country',
        ],
        '#prefix' => '<div class="recurlyjs-form-item__country">',
        '#suffix' => '</div>',
        '#options' => $countries,
        '#empty_option' => $this->t('Select country...'),
        '#default_value' => !empty($this->billingInfo) ? $this->billingInfo->country : '',
      ];
    }

    if (!$hide_vat_number) {
      $form['billing']['vat_number'] = [
        '#type' => 'textfield',
        '#title' => $this->t('VAT Number'),
        '#attributes' => [
          'data-recurly' => 'vat_number',
        ],
        '#prefix' => '<div class="recurlyjs-form-item__vat_number">',
        '#suffix' => '</div>',
        '#default_value' => !empty($this->billingInfo) ? $this->billingInfo->vat_number : '',
      ];
    }

    $form['tax_code'] = [
      '#type' => 'hidden',
      '#title' => $this->t('digital'),
      '#attributes' => [
        'data-recurly' => 'tax_code',
      ],
    ];
    $form['recurly-token'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'data-recurly' => 'token',
      ],
    ];
    return $form;
  }

  /**
   * Set default billing information for form.
   *
   * When present, the information here will be used to pre-populate fields in
   * the billing information form.
   *
   * @param \Recurly_BillingInfo $billing_info
   *   Current billing information.
   */
  public function setBillingInfo(\Recurly_BillingInfo $billing_info) {
    $this->billingInfo = $billing_info;
  }

}
