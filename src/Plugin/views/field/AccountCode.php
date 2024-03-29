<?php

namespace Drupal\recurly\Plugin\views\field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\recurly\RecurlyUrlManager;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add a link to view a Recurly account.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("recurly_account_code")
 */
class AccountCode extends FieldPluginBase {

  /**
   * The Recurly URL manager service.
   *
   * @var \Drupal\recurly\RecurlyUrlManager
   */
  protected $recurlyUrlManager;

  /**
   * Creates an account code field plugin base.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\recurly\RecurlyUrlManager $recurly_url_manager
   *   The Recurly URL manager service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RecurlyUrlManager $recurly_url_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->recurlyUrlManager = $recurly_url_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('recurly.url_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['link_to_recurly'] = ['default' => TRUE];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['link_to_recurly'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Link this field to the account at Recurly'),
      '#description' => $this->t("Enable to link this field to the account in Recurly's user interface"),
      '#default_value' => $this->options['link_to_recurly'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $value = $this->getValue($values);
    return $this->renderLink($this->sanitizeValue($value), $values);
  }

  /**
   * Render a link for a Recurly account.
   *
   * @param string $data
   *   Sanitized data to render.
   * @param \Drupal\views\ResultRow $values
   *   An ResultRow object of values associated with this row.
   *
   * @return mixed
   *   A rendered account string.
   */
  private function renderLink($data, ResultRow $values) {
    if (!empty($this->options['link_to_recurly']) && ($account_code = $this->getValue($values)) && $data !== NULL && $data !== '') {
      $this->options['alter']['make_link'] = TRUE;
      $this->options['alter']['path'] = $this->recurlyUrlManager->hostedUrl('accounts/' . $account_code)->getUri();
    }
    return $data;
  }

}
