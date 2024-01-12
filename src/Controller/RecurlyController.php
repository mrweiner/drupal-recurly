<?php

namespace Drupal\recurly\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\recurly\RecurlyClientFactory;
use Drupal\recurly\RecurlyFormatManager;
use Drupal\recurly\RecurlyPagerManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for Recurly controllers.
 */
abstract class RecurlyController extends ControllerBase {

  /**
   * The Recurly page manager service.
   *
   * @var \Drupal\recurly\RecurlyPagerManager
   */
  protected $recurlyPageManager;

  /**
   * The Recurly formatting service.
   *
   * @var \Drupal\recurly\RecurlyFormatManager
   */
  protected $recurlyFormatter;

  /**
   * The Recurly client service, initialized on construction.
   *
   * @var \Drupal\recurly\RecurlyClientFactory
   */
  protected $recurlyClientFactory;

  /**
   * Initialized instance of the Recurly API client.
   *
   * @var \Recurly_Client
   */
  protected $recurlyClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('recurly.pager_manager'),
      $container->get('recurly.format_manager'),
      $container->get('recurly.client')
    );
  }

  /**
   * Class constructor.
   *
   * @param \Drupal\recurly\RecurlyPagerManager $recurly_page_manager
   *   The Recurly page manager service.
   * @param \Drupal\recurly\RecurlyFormatManager $recurly_formatter
   *   The Recurly formatter to be used for formatting.
   * @param \Drupal\recurly\RecurlyClientFactory $clientFactory
   *   The Recurly client service.
   */
  public function __construct(RecurlyPagerManager $recurly_page_manager, RecurlyFormatManager $recurly_formatter, RecurlyClientFactory $clientFactory) {
    $this->recurlyPageManager = $recurly_page_manager;
    $this->recurlyFormatter = $recurly_formatter;
    $this->recurlyClientFactory = $clientFactory;
    $this->recurlyClient = $clientFactory->getClientFromSettings();
  }

}
