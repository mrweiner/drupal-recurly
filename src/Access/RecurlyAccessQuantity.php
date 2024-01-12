<?php

namespace Drupal\recurly\Access;

use Drupal\Core\Access\AccessResult;

/**
 * Checks if the quantity change route should be accessible.
 */
class RecurlyAccessQuantity extends RecurlyAccess {

  /**
   * {@inheritdoc}
   */
  public function access() {
    $this->setLocalAccount();
    if ($this->recurlySettings->get('recurly_subscription_multiple')) {
      return AccessResult::allowed()
        ->addCacheContexts($this->recurlySettings->getCacheContexts());
    }
    return AccessResult::forbidden()
      ->addCacheContexts($this->recurlySettings->getCacheContexts());
  }

}
