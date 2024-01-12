<?php

namespace Drupal\recurly\Controller;

use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Returns responses for Recurly Subscription List.
 */
class RecurlyInvoicesController extends RecurlyController {

  /**
   * Retrieve all invoices for the specified entity.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   A RouteMatch object.
   *   Contains information about the route and the entity being acted on.
   *
   * @return array
   *   Returns a render array for a list of invoices.
   */
  public function invoicesList(RouteMatchInterface $route_match) {
    $entity_type = $this->config('recurly.settings')->get('recurly_entity_type');
    $entity = $route_match->getParameter($entity_type);

    $account = recurly_account_load([
      'entity_type' => $entity_type,
      'entity_id' => $entity->id(),
    ]);

    $per_page = 20;
    $invoice_list = \Recurly_InvoiceList::getForAccount($account->account_code, ['per_page' => $per_page], $this->recurlyClient);
    $invoices = $this->recurlyPageManager->pagerResults($invoice_list, $per_page);

    return [
      '#theme' => 'recurly_invoice_list',
      '#attached' => [
        'library' => [
          'recurly/recurly.invoice',
        ],
      ],
      '#invoices' => $invoices,
      '#entity_type' => $entity_type,
      '#entity' => $entity,
      '#per_page' => $per_page,
      '#total' => $invoice_list->count(),
    ];
  }

  /**
   * Retrieve a single specified entity.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   A RouteMatch object.
   *   Contains information about the route and the entity being acted on.
   * @param string $invoice_number
   *   A Recurly invoice UUID.
   *
   * @return array
   *   Returns a render array for an invoice.
   */
  public function getInvoice(RouteMatchInterface $route_match, $invoice_number) {
    $entity_type = $this->config('recurly.settings')->get('recurly_entity_type');
    $entity = $route_match->getParameter($entity_type);

    $account = recurly_account_load([
      'entity_type' => $entity_type,
      'entity_id' => $entity->id(),
    ]);

    // Load the invoice.
    try {
      $invoice = \Recurly_Invoice::get($invoice_number, $this->recurlyClient);
    }
    catch (\Recurly_NotFoundError $e) {
      $this->messenger()->addMessage($this->t('Invoice not found'));
      throw new NotFoundHttpException('Invoice not found');
    }

    // Load the invoice account.
    $invoice_account = $invoice->account->get();

    // Ensure that the user account is the same as the invoice account.
    if (empty($account) || $invoice_account->account_code !== $account->account_code) {
      throw new NotFoundHttpException('User account does not match invoice account');
    }

    // @todo Fix drupal_set_title() has been removed. There are now a few ways
    // to set the title dynamically, depending on the situation.
    //
    // @see https://www.drupal.org/node/2067859
    // drupal_set_title(t('Invoice #@number', [
    // '@number' => $invoice->invoice_number]));
    if ($invoice->state != 'paid') {
      $url = recurly_url('update_billing', ['entity' => $entity]);
      if ($url) {
        $error_message = $this->t('This invoice is past due! Please <a href=":url">update your billing information</a>.', [':url' => $url->toString()]);
      }
      else {
        $error_message = $this->t('This invoice is past due! Please contact an administrator to update your billing information.');
      }
    }

    return [
      '#theme' => 'recurly_invoice',
      '#attached' => [
        'library' => [
          'recurly/recurly.invoice',
        ],
      ],
      '#invoice' => $invoice,
      '#invoice_account' => $invoice_account,
      '#entity_type' => $entity_type,
      '#entity' => $entity,
      '#error_message' => $error_message ?? NULL,
    ];
  }

  /**
   * Deliver an invoice PDF file from Recurly.com.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   A RouteMatch object.
   *   Contains information about the route and the entity being acted on.
   * @param string $invoice_number
   *   A Recurly invoice UUID.
   */
  public function getInvoicePdf(RouteMatchInterface $route_match, $invoice_number) {
    $entity_type = $this->config('recurly.settings')->get('recurly_entity_type');
    $entity = $route_match->getParameter($entity_type);

    $account = recurly_account_load([
      'entity_type' => $entity_type,
      'entity_id' => $entity->id(),
    ]);

    // Load the invoice.
    try {
      $invoice = \Recurly_Invoice::get($invoice_number, $this->recurlyClient);
      $pdf = \Recurly_Invoice::getInvoicePdf($invoice_number, NULL, $this->recurlyClient);
    }
    catch (\Recurly_NotFoundError $e) {
      $this->messenger()->addMessage($this->t('Invoice not found'));
      throw new NotFoundHttpException('Invoice not found');
    }

    // Load the invoice account.
    $invoice_account = $invoice->account->get();

    // Ensure that the user account is the same as the invoice account.
    if (empty($account) || $invoice_account->account_code !== $account->account_code) {
      throw new NotFoundHttpException('User account does not match invoice account');
    }

    if (!empty($pdf)) {
      if (headers_sent()) {
        die("Unable to stream pdf: headers already sent");
      }

      $response = new Response();
      $response->headers->set('Content-Type', 'application/pdf', TRUE);
      $response->headers->set('Content-Disposition', 'inline; filename="' . $invoice_number . '.pdf"', TRUE);
      $response->setContent($pdf);
      return $response;
    }
  }

}
