<?php

namespace Drupal\Tests\recurly\Kernel;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\recurly\Controller\RecurlyInvoicesController;
use Drupal\recurly_test_client\RecurlyMockClient;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests for RecurlyInvoicesControllerTest.
 *
 * @covers \Drupal\recurly\Controller\RecurlyInvoicesControllerTest
 * @group recurly
 */
class RecurlyInvoicesControllerTest extends KernelTestBase {

  use ProphecyTrait;
  use UserCreationTrait;

  /**
   * Instance of RecurlyInvoicesControllerTest.
   *
   * @var \Drupal\recurly\Controller\RecurlyController|\Drupal\recurly\Controller\RecurlyInvoicesControllerTest
   */
  protected $controller;

  /**
   * Drupal user object.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $drupalUser;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'recurly',
    'recurly_test_client',
    'system',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();

    $this->installConfig(['recurly', 'user', 'system']);
    $this->config('recurly.settings')
      ->set('recurly_entity_type', 'user')
      ->set('recurly_subscription_plans',
        ['silver' => ['status' => '1', 'weight' => '0']])
      ->save();

    $this->installSchema('system', ['sequences']);
    $this->installSchema('recurly', ['recurly_account']);
    $this->installEntitySchema('user');

    // Add a user and Recurly subscription, this matches what's in the API
    // fixtures.
    $user = $this->setUpCurrentUser();
    $account_code = 'abcdef1234567890';
    $recurly_account = new \Recurly_Account($account_code);
    recurly_account_save($recurly_account, 'user', $user->id(), FALSE);

    $this->drupalUser = $user;
    $this->controller = RecurlyInvoicesController::create(\Drupal::getContainer());

    RecurlyMockClient::clear();
  }

  /**
   * Tests for invoice list UI.
   *
   * @coverrs \Drupal\recurly\Controller\RecurlyInvoicesController::invoicesList
   */
  public function testInvoicesList() {
    RecurlyMockClient::addResponse('HEAD', '/accounts/abcdef1234567890/invoices?per_page=20', 'invoices/head-200.xml');
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890/invoices?per_page=20', 'invoices/index-200.xml');

    $routeMatch = $this->prophesize(RouteMatchInterface::class);
    $routeMatch->getParameter('user')->willReturn($this->drupalUser);

    $response = $this->controller->invoicesList($routeMatch->reveal());
    $this->assertArrayHasKey('#theme', $response);
    $this->assertArrayHasKey('#invoices', $response);
    $this->assertEquals(count($response['#invoices']), $response['#total']);
  }

  /**
   * Tests for display of individual invoice.
   *
   * @covers \Drupal\recurly\Controller\RecurlyInvoicesController::getInvoice
   */
  public function testGetInvoice() {
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890', 'accounts/show-200.xml');
    RecurlyMockClient::addResponse('GET', '/invoices/1000', 'invoices/show-200.xml');

    $routeMatch = $this->prophesize(RouteMatchInterface::class);
    $routeMatch->getParameter('user')->willReturn($this->drupalUser);

    $response = $this->controller->getInvoice($routeMatch->reveal(), '1000');
    $this->assertEquals('recurly_invoice', $response['#theme']);
    $this->assertArrayHasKey('#invoice', $response);
    $this->assertEquals(NULL, $response['#error_message']);

    // Verify #error_message if invoice state is not 'paid'.
    RecurlyMockClient::addResponse('GET', '/invoices/1001', 'invoices/show-200-past_due.xml');

    $response = $this->controller->getInvoice($routeMatch->reveal(), '1001');
    $this->assertEquals('recurly_invoice', $response['#theme']);
    $this->assertStringContainsString('This invoice is past due!', $response['#error_message']);

    // Can't find invoice with that ID.
    $this->expectException(NotFoundHttpException::class);
    $this->expectExceptionMessage('Invoice not found');
    $this->controller->getInvoice($routeMatch->reveal(), 'bad-invoice-id');

    // The user identified by the route does not match the user associated with
    // the invoice ID.
    $alternateUser = $this->createUser();
    $routeMatch->getParameter('user')->willReturn($alternateUser);
    $this->expectException(NotFoundHttpException::class);
    $this->expectExceptionMessage('User account does not match invoice account');
    $this->controller->getInvoice($routeMatch->reveal(), '1000');
  }

  /**
   * Tests for invoice PDF feature.
   *
   * @covers \Drupal\recurly\Controller\RecurlyInvoicesController::getInvoicePdf
   */
  public function testGetPdf() {
    RecurlyMockClient::addResponse('GET', '/accounts/abcdef1234567890', 'accounts/show-200.xml');
    RecurlyMockClient::addResponse('GET', '/invoices/1000', 'invoices/show-200.xml');

    $routeMatch = $this->prophesize(RouteMatchInterface::class);
    $routeMatch->getParameter('user')->willReturn($this->drupalUser);
    $response = $this->controller->getInvoicePdf($routeMatch->reveal(), '1000');

    $this->assertEquals('Here is that PDF you asked for', $response->getContent());
    $this->assertInstanceOf(Response::class, $response);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals('application/pdf', $response->headers->get('content-type'));

    // Can't find invoice with that ID.
    $this->expectException(NotFoundHttpException::class);
    $this->expectExceptionMessage('Invoice not found');
    $this->controller->getInvoicePdf($routeMatch->reveal(), 'bad-invoice-id');

    // The user identified by the route does not match the user associated with
    // the invoice ID.
    $alternateUser = $this->createUser();
    $routeMatch->getParameter('user')->willReturn($alternateUser);
    $this->expectException(NotFoundHttpException::class);
    $this->expectExceptionMessage('User account does not match invoice account');
    $this->controller->getInvoicePdf($routeMatch->reveal(), '1000');
  }

}
