<?php

namespace Drupal\recurly_test_client;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Mock for Recurly_Client provided by recurly/recurly-client.
 *
 * This class can be used to replace Recurly_Client when you want to return
 * canned responses instead of making queries to the API.
 *
 * Response data for mocked calls is stored in fixtures/ as XML files. These
 * are mostly copied from the Recurly PHP library, with some adjustments to
 * values to allow them to work better in scenarios where you need to be able
 * to associated a specific account ID with a subscription.
 *
 * The content of a fixture can be altered at runtime allowing for things like
 * dates to be relative so you can have a subscription that expires '+2 weeks'
 * from now. See RecurlyMockClient::addResponse() for more info.
 *
 * If you use this Mock client in a test, you will need to ensure the
 * 'key_value_expire' table exists. Usually by adding something like this to
 * your test's setUp method for Unit/Kernel tests that don't have a full Drupal
 * install.
 *
 * @code
 *
 * public function setUp() {
 *   parent::setUp();
 *   $this->installSchema('system', ['key_value_expire', 'sequences']);
 *   RecurlyMockClient::clearResponses();
 * }
 *
 * @endcode
 */
class RecurlyMockClient {

  use StringTranslationTrait;

  /**
   * Enable debugging mode.
   *
   * When debugging mode is enabled the responses in debugResponses() are added
   * to the client. This makes it easier to use the mock client when interacting
   * with the site through the normal Drupal UI in your browser and see what the
   * tests see. Additionally it will indicate what requests are being made so
   * you'll know which ones you need to mock.
   *
   * @var bool
   */
  private static $debug = FALSE;

  /**
   * Return debugging responses.
   *
   * To make it easier to enable this module, and then us it in the browser, and
   * help with debugging tests, you can add responses to this method. These
   * follow the same format you would get from addResponse(). There are a few
   * examples.
   *
   * @return array|bool
   *   An array of responses in the same format as addResponse(). Or FALSE.
   */
  protected static function debugResponses() {
    if (self::$debug) {
      return [
        'GET' => [
          '/accounts/abcdef1234567890' => 'accounts/show-200.xml',
          '/accounts/abcdef1234567890/billing_info' => 'billing_info/show-200.xml',
          '/accounts/abcdef1234567890/invoices?per_page=20' => 'invoices/index-200.xml',
          '/accounts/abcdef1234567890/subscriptions?per_page=200' => [
            'fixture' => 'subscriptions/index-200-single.xml',
            'alterations' => [
              '//current_period_started_at' => date('c', strtotime('-2 weeks')),
              '//current_period_ends_at' => date('c', strtotime('+2 weeks')),
            ],
          ],
          '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active' => [
            'fixture' => 'subscriptions/index-200.xml',
            'alterations' => [
              '//current_period_started_at' => date('c', strtotime('-2 weeks')),
              '//current_period_ends_at' => date('c', strtotime('+2 weeks')),
              // '//state' => 'expired',
              '//updated_at' => date('c'),
              '//canceled_at' => date('c'),
              '//expires_at' => date('c'),
            ],
          ],
          '/accounts/abcdef1234567890/subscriptions?state=past_due' => 'subscriptions/index-200.xml',
          '/invoices/1000' => 'invoices/show-200.xml',
          '/invoices/1001' => 'invoices/show-200-past_due.xml',
          '/plans' => 'plans/index-200.xml',
          '/plans/corporis_excepturi8/add_ons/dolores_molestiae15' => 'addons/show-200.xml',
          '/plans/bedrock' => 'plans/show-200-bedrock.xml',
          '/plans/silver' => 'plans/show-200.xml',
          '/subscriptions/32558dd8a07eec471fbe6642d3a422f4' => [
            'fixture' => 'subscriptions/show-200.xml',
            'alterations' => [
              '//current_period_started_at' => date('c', strtotime('-2 weeks')),
              '//current_period_ends_at' => date('c', strtotime('+2 weeks')),
              // '//state' => 'expired',
              // '//updated_at' => date('c'),
              // '//canceled_at' => date('c'),
              // '//expires_at' => date('c', strtotime('+2 weeks')),
            ],
          ],
        ],
        'HEAD' => [
          '/accounts/abcdef1234567890/invoices?per_page=20' => 'invoices/head-200.xml',
          '/accounts/abcdef1234567890/subscriptions?per_page=50&state=active' => 'subscriptions/head-200.xml',
        ],
        'PUT' => [
          '/subscriptions/32558dd8a07eec471fbe6642d3a422f4' => 'subscriptions/show-200.xml',
          '/subscriptions/32558dd8a07eec471fbe6642d3a422f4/cancel' => [
            'fixture' => 'subscriptions/cancel-200.xml',
            'alterations' => [
              '//updated_at' => date('c'),
              '//canceled_at' => date('c'),
              '//expires_at' => date('c', strtotime('+2 weeks')),
            ],
          ],
          '/subscriptions/32558dd8a07eec471fbe6642d3a422f4/terminate?refund=partial&charge=true' => [
            'fixture' => 'subscriptions/cancel-200.xml',
            'alterations' => [
              '//updated_at' => date('c'),
              '//canceled_at' => date('c'),
              '//expires_at' => date('c'),
              '//state' => 'expired',
            ],
          ],
          '/subscriptions/32558dd8a07eec471fbe6642d3a422f4/terminate?refund=full&charge=true' => [
            'fixture' => 'subscriptions/cancel-200.xml',
            'alterations' => [
              '//updated_at' => date('c'),
              '//canceled_at' => date('c'),
              '//expires_at' => date('c'),
              '//state' => 'expired',
            ],
          ],
        ],
      ];
    }

    return FALSE;
  }

  /**
   * Add a new response fixture.
   *
   * @param string $method
   *   HTTP request method, e.g. 'GET' or 'POST'.
   * @param string $uri
   *   API endpoint that the response should be returned for.
   * @param string|array $fixture
   *   Can be either a string path to the fixture file that contains the
   *   response to return. Or an associative array with:
   *   'fixture': Path to the fixture file to return.
   *   'alterations': An array of alterations to make to the fixture where the
   *   keys are xpath selectors, and the values are used to replace the value
   *   of any element matched by the selector.
   *
   *   Example:
   *   @code
   *   '/subscriptions/subscription/current_period_ends_at' => date('c', strtotime('+2 weeks'))
   *   @endcode
   *
   * @throws \Drupal\Core\TempStore\TempStoreException
   */
  public static function addResponse(string $method, string $uri, $fixture) {
    $tempStore = self::getTempStore();
    $responses = $tempStore->get('responses');
    $responses[$method][$uri] = $fixture;
    $tempStore = self::getTempStore();
    $tempStore->delete('responses');
    $tempStore->set('responses', $responses);
  }

  /**
   * Assert that the specified API request has been made.
   *
   * @param string $method
   *   HTTP request method.
   * @param string $uri
   *   URI check if it was requested.
   *
   * @return bool
   *   TRUE if the request is in the call log.
   */
  public static function assertRequestMade($method, $uri) {
    $tempStore = self::getTempStore();
    $previousRequests = $tempStore->get('request_log');
    return (is_array($previousRequests[$method]) && in_array($uri, $previousRequests[$method]));
  }

  /**
   * Clear stored responses and request log.
   */
  public static function clear() {
    $tempStore = self::getTempStore();
    if ($tempStore && $tempStore->get('responses')) {
      $tempStore->delete('responses');
    }
    if ($tempStore && $tempStore->get('request_log')) {
      $tempStore->delete('request_log');
    }
  }

  /**
   * Clear the request log.
   */
  public static function clearRequestLog() {
    $tempStore = self::getTempStore();
    if ($tempStore && $tempStore->get('request_log')) {
      $tempStore->delete('request_log');
    }
  }

  /**
   * Load the temporary storage.
   *
   * @return \Drupal\Core\TempStore\SharedTempStore
   *   Temporary storage object.
   */
  private static function getTempStore() {
    return \Drupal::service('tempstore.shared')
      ->get('recurly_test_client');
  }

  /**
   * Mock for Recurly_Client::request() that returns canned responses.
   *
   * @return \Recurly_ClientResponse
   *   Recurly response generated from the content of the fixture file used ot
   *   fulfill the request.
   *
   * @throws \Exception
   */
  public function request($method, $uri, $data = NULL) {
    $tempStore = self::getTempStore();
    $uri = str_replace('https://api.recurly.com/v2', '', $uri);
    // Log that this request was made.
    $previousRequests = $tempStore->get('request_log');
    $tempStore->delete('request_log');
    $previousRequests[$method][] = $uri;
    $tempStore->set('request_log', $previousRequests);

    $tempResponses = $tempStore->get('responses');
    $debugResponses = self::debugResponses();
    if ($tempResponses && $debugResponses) {
      $responses = array_replace_recursive($debugResponses, $tempResponses);
    }
    elseif (!empty($debugResponses)) {
      $responses = $debugResponses;
    }
    elseif (!empty($tempResponses)) {
      $responses = $tempResponses;
    }

    if (isset($responses[$method][$uri])) {
      if (is_array($responses[$method][$uri])) {
        $fixture_filename = $responses[$method][$uri]['fixture'];
        $fixture_alterations = $responses[$method][$uri]['alterations'];
      }
      else {
        $fixture_alterations = NULL;
        $fixture_filename = $responses[$method][$uri];
      }
    }
    else {
      \Drupal::messenger()->addWarning($this->t('RecurlyMockClient does not know how to @method : @uri',
        [
          '@method' => strtoupper($method),
          '@uri' => $uri,
        ]
      ));
      throw new \Recurly_NotFoundError("Don't know how to $method '$uri'");
    }

    \Drupal::messenger()->addWarning($this->t('RecurlyMockClient - using mock response for @method : @uri from %fixture',
      [
        '@method' => strtoupper($method),
        '@uri' => $uri,
        '%fixture' => $fixture_filename,
      ]
    ));

    return $this->responseFromFixture($fixture_filename, $fixture_alterations);
  }

  /**
   * Create a Recurly_ClientResponse from a fixture.
   *
   * @param string $filename
   *   Name of file that contains the canned response to load.
   * @param array $alterations
   *   An array of alterations to make to the data in the fixture. The keys are
   *   xpath selectors, and the values will be used to replace the value of any
   *   matching elements.
   *
   * @return \Recurly_ClientResponse
   *   Recurly response object populated with a canned response.
   */
  protected function responseFromFixture($filename, array $alterations = NULL) {
    $headers = [];
    $body = NULL;

    $fixture = file(__DIR__ . '/../fixtures/' . $filename, FILE_IGNORE_NEW_LINES);

    $matches = NULL;
    preg_match('/HTTP\/1\.1 ([0-9]{3})/', $fixture[0], $matches);
    $statusCode = intval($matches[1]);

    $bodyLineNumber = 0;
    for ($i = 1; $i < count($fixture); $i++) {
      if (strlen($fixture[$i]) < 5) {
        $bodyLineNumber = $i + 1;
        break;
      }
      preg_match('/([^:]+): (.*)/', $fixture[$i], $matches);
      if (count($matches) > 2) {
        $headerKey = strtolower($matches[1]);
        $headers[$headerKey] = $matches[2];
      }
    }

    if ($bodyLineNumber < count($fixture)) {
      $body = implode("\n", array_slice($fixture, $bodyLineNumber));
    }

    if ($alterations !== NULL && count($alterations)) {
      $xml = new \SimpleXMLElement($body);
      foreach ($alterations as $path => $value) {
        $result = $xml->xpath($path);
        foreach ($result as $node) {
          $node[0] = $value;
        }
      }
      $body = $xml->asXML();
    }

    return new \Recurly_ClientResponse($statusCode, $headers, $body);
  }

  /**
   * Mocks the recurly client's getPdf() method. Returns a known string.
   *
   * @return string
   *   Mock value.
   */
  public function getPdf($uri, $locale = NULL) {
    return 'Here is that PDF you asked for';
  }

}
