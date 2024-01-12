<?php

namespace Drupal\recurly\Form;

use Drupal\Core\Form\FormBase;
use Drupal\recurly\RecurlyClientFactory;
use Drupal\recurly\RecurlyFormatManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Parent class for Recurly forms.
 */
abstract class RecurlyNonConfigForm extends FormBase {

  /**
   * The Recurly client service, initialized on construction.
   *
   * @var \Recurly_Client
   */
  protected $recurlyClient;

  /**
   * The formatting service.
   *
   * @var \Drupal\recurly\RecurlyFormatManager
   */
  protected $recurlyFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('recurly.client'),
      $container->get('recurly.format_manager')
    );
  }

  /**
   * Constructs a \Drupal\recurly\Form\RecurlyRedeemCouponForm object.
   *
   * @param \Drupal\recurly\RecurlyClientFactory $recurlyClientFactory
   *   The Recurly client service.
   * @param \Drupal\recurly\RecurlyFormatManager $recurly_formatter
   *   A Recurly formatter object.
   */
  public function __construct(
    RecurlyClientFactory $recurlyClientFactory,
    RecurlyFormatManager $recurly_formatter
  ) {
    $this->recurlyClient = $recurlyClientFactory->getClientFromSettings();
    $this->recurlyFormatter = $recurly_formatter;
  }

}
