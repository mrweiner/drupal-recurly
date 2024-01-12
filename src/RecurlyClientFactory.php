<?php

namespace Drupal\recurly;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerTrait;

/**
 * Factory to get instances of the Recurly API \Recurly_Client object.
 */
class RecurlyClientFactory {

  use MessengerTrait;

  const ERROR_MESSAGE_MISSING_API_KEY = 'The Recurly private API key is not configured.';

  const ERROR_MESSAGE_MISSING_SUBDOMAIN = 'The Recurly subdomain is not configured.';

  /**
   * This module's settings.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $moduleSettings;

  /**
   * The logging service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * Class Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_service
   *   The Recurly configuration.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_service
   *   The logger.
   */
  public function __construct(ConfigFactoryInterface $config_service, LoggerChannelFactoryInterface $logger_service) {
    $this->moduleSettings = $config_service->get('recurly.settings');
    $this->logger = $logger_service->get('recurly');
  }

  /**
   * Initializes the Recurly API client with a given set of account settings.
   *
   * @param array $account_settings
   *   An array of Recurly account settings including the following keys or NULL
   *   to use the site-wide account settings.
   *   - username: the API username to use
   *   - password: the API password for the given username
   *   - subdomain: the subdomain configured for your Recurly account.
   *   - environment: the current environment of the given account, either
   *     'sandbox' or 'production'.
   * @param bool $reset
   *   TRUE if the initialization should be reset; FALSE otherwise.
   *
   * @return bool
   *   TRUE or FALSE indicating whether or not the client was initialized with
   *   the specified account settings.
   * @phpcs:ignore Drupal.Commenting.Deprecated.IncorrectTextLayout
   * @deprecated in recurly 8.x-1.x and will be removed.
   *   Use \Drupal\recurly\RecurlyClient::getClientFromSettings() instead. It's
   *   better to get an initialized client object and pass it as an argument to
   *   Recurly_* resource classes than rely on the global \Recurly_Client.
   * @see https://www.drupal.org/project/recurly/issues/3164892
   */
  public function initialize(array $account_settings = NULL, $reset = FALSE) {
    static $initialized = FALSE;

    // Skip the process if we're not setting up a new connection and we're
    // already set up with a configuration.
    if ($initialized && !$reset) {
      return TRUE;
    }

    // If no settings array was given, use the default account settings.
    if (empty($account_settings)) {
      $account_settings = $this->getDefaultAccountSettings();
    }

    try {
      $this->validateSettings($account_settings);
    }
    catch (\Recurly_ConfigurationError $error) {
      return FALSE;
    }

    // Required for the API.
    \Recurly_Client::$apiKey = $account_settings['api_key'];

    $initialized = TRUE;
    return TRUE;
  }

  /**
   * Get an instance of Recurly_Client configured to connect to the API.
   *
   * @param array $account_settings
   *   An array of Recurly account settings including the following keys or NULL
   *   to use the site-wide account settings.
   *   - username: the API username to use
   *   - password: the API password for the given username
   *   - subdomain: the subdomain configured for your Recurly account.
   *   - environment: the current environment of the given account, either
   *     'sandbox' or 'production'.
   *
   * @return bool|Recurly_Client
   *   A Recurly API client with credentials and settings loaded, or FALSE if
   *   one could not be created.
   *
   * @throws \Recurly_ConfigurationError
   */
  public function getClientFromSettings(array $account_settings = NULL) {
    static $_client = FALSE;

    if (!$_client) {
      // If no settings array was given, use the default account settings.
      if (empty($account_settings)) {
        $account_settings = $this->getDefaultAccountSettings();
      }

      try {
        $this->validateSettings($account_settings);
        $_client = new \Recurly_Client($account_settings['api_key']);
      }
      catch (\Recurly_ConfigurationError $error) {
        $this->logger->error('Unable to intialize Recurly API client: %error', ['%error' => $error->getMessage()]);
        return FALSE;
      }

    }

    return $_client;
  }

  /**
   * Ensure that mandatory settings have been entered.
   *
   * @param array $account_settings
   *   Associative array containing settings to validate including 'api_key',
   *   and 'subdomain'.
   *
   * @throws \Recurly_ConfigurationError
   */
  protected function validateSettings(array $account_settings) {
    // Ensure that the mandatory settings have been entered.
    if (empty($account_settings['api_key'])) {
      $message = self::ERROR_MESSAGE_MISSING_API_KEY;
      $this->messenger()->addError($message);
      $this->logger->error($message);
      throw new \Recurly_ConfigurationError(self::ERROR_MESSAGE_MISSING_API_KEY);
    }
    if (empty($account_settings['subdomain'])) {
      $message = self::ERROR_MESSAGE_MISSING_SUBDOMAIN;
      $this->messenger()->addError($message);
      $this->logger->error($message);
      throw new \Recurly_ConfigurationError(self::ERROR_MESSAGE_MISSING_SUBDOMAIN);
    }
  }

  /**
   * Fetches the default account settings.
   *
   * @return array
   *   Associative array of settings to use when connecting to Recurly API.
   */
  protected function getDefaultAccountSettings() {
    return [
      'api_key' => $this->moduleSettings->get('recurly_private_api_key'),
      'subdomain' => $this->moduleSettings->get('recurly_subdomain'),
      'public_key' => $this->moduleSettings->get('recurly_public_key'),
    ];
  }

}
