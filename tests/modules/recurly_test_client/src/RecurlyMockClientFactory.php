<?php

namespace Drupal\recurly_test_client;

use Drupal\recurly\RecurlyClientFactory;

/**
 * Service decorator for Drupal\recurly\RecurlyClientFactory.
 *
 * Initializes and returns an instance of
 * Drupal\recurly_test_client\RecurlyMockClient instead of \Recurly_Client.
 */
class RecurlyMockClientFactory extends RecurlyClientFactory {

  /**
   * Return a mock Recurly API client.
   *
   * @return bool|\Drupal\recurly_test_client\RecurlyMockClient|\Recurly_Client
   *   Recurly API client that returns canned responses.
   */
  public function getClientFromSettings(array $account_settings = NULL) {
    return new RecurlyMockClient();
  }

}
