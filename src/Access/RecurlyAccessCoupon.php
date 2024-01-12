<?php

namespace Drupal\recurly\Access;

use Drupal\Core\Access\AccessResult;

/**
 * Checks if the coupon route should be accessible.
 */
class RecurlyAccessCoupon extends RecurlyAccess {

  /**
   * {@inheritdoc}
   */
  public function access() {
    $this->setLocalAccount();
    if ($this->recurlySettings->get('recurly_coupon_page')) {
      return AccessResult::allowed()
        ->addCacheContexts($this->recurlySettings->getCacheContexts());
    }
    return AccessResult::forbidden()
      ->addCacheContexts($this->recurlySettings->getCacheContexts());
  }

}
