<?php

namespace Drupal\recurly;

use Drupal\Core\Pager\PagerManagerInterface;

/**
 * Recurly pager utility functionality.
 */
class RecurlyPagerManager {

  /**
   * Pager service.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected $pagerManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Pager\PagerManagerInterface $pagerManager
   *   The Drupal page manager service.
   */
  public function __construct(PagerManagerInterface $pagerManager) {
    $this->pagerManager = $pagerManager;
  }

  /**
   * Utility function to retrieve a specific page of results from Recurly_Pager.
   *
   * @param object $recurly_pager_object
   *   Any object that extends a Recurly_Pager object, such as a
   *   Recurly_InvoiceList, Recurly_SubscriptionList, or
   *   Recurly_TransactionList.
   * @param int $per_page
   *   The number of items to display per page.
   * @param int $page_num
   *   The desired page number to display. Usually automatically determined from
   *   the URL.
   */
  public function pagerResults($recurly_pager_object, $per_page, $page_num = NULL) {
    if (!isset($page_num)) {
      $page_num = $this->pagerManager->findPage();
    }

    $recurly_pager_object->rewind();

    // Fast forward the list to the current page.
    $start = $page_num * $per_page;
    for ($n = 0; $n < $start; $n++) {
      $recurly_pager_object->next();
    }

    // Populate $page_results with the list of items for the current page.
    $total = $recurly_pager_object->count();
    $page_end = min($start + $per_page, $total);
    $page_results = [];
    for ($n = $start; $n < $page_end; $n++) {
      $item = $recurly_pager_object->current();
      $page_results[$item->uuid] = $item;
      $recurly_pager_object->next();
    }

    $this->pagerManager->createPager($total, $per_page);

    return $page_results;
  }

}
