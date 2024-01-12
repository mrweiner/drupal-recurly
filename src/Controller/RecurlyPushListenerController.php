<?php

namespace Drupal\recurly\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Default controller for the recurly module.
 */
class RecurlyPushListenerController extends RecurlyController {

  /**
   * Process push notification.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request stack.
   * @param string $key
   *   Recurly listener key.
   * @param string $subdomain
   *   Recurly account subdomain.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The appropriate Response object.
   */
  public function processPushNotification(Request $request, $key, $subdomain = NULL) {

    // Verify that the subdomain matches the configured one if it was specified.
    $subdomain_configured = $this->config('recurly.settings')
      ->get('recurly_subdomain');
    if (!empty($subdomain) && $subdomain != $subdomain_configured) {
      $subdomain_error_text = 'Incoming push notification did not contain the proper subdomain key.';
      $this->getLogger('recurly')->warning($subdomain_error_text, []);
      return new Response($subdomain_error_text, Response::HTTP_FORBIDDEN);
    }

    // Ensure that the push notification was sent with the proper key.
    if ($key != $this->config('recurly.settings')
      ->get('recurly_listener_key')) {
      // Log the failed attempt and bail.
      $url_key_error_text = 'Incoming push notification did not contain the proper URL key.';
      $this->getLogger('recurly')->warning($url_key_error_text, []);
      return new Response($url_key_error_text, Response::HTTP_FORBIDDEN);
    }

    // Retrieve the POST XML and create a notification object from it.
    if ($request->getContentType() == 'xml') {
      $post_xml = $request->getContent();
      $notification = new \Recurly_PushNotification($post_xml);
    }
    else {
      $notification = NULL;
    }

    // Bail if this is an empty or invalid notification.
    if (empty($notification) || empty($notification->type)) {
      return new Response('Empty or invalid notification.', Response::HTTP_BAD_REQUEST);
    }

    // Log the incoming push notification if enabled.
    if ($this->config('recurly.settings')->get('recurly_push_logging')) {
      $this->getLogger('recurly')->notice('Incoming %type: <pre>@notification</pre>', [
        '%type' => $notification->type,
        '@notification' => print_r($notification, TRUE),
      ]);
    }

    // If this is a new, canceled, or updated account set the database record.
    if (in_array($notification->type, [
      'new_account_notification',
      'new_subscription_notification',
      'canceled_account_notification',
      'reactivated_account_notification',
      'billing_info_updated_notification',
    ])) {
      // Retrieve the full account record from Recurly.
      try {
        $recurly_account = \Recurly_Account::get($notification->account->account_code, $this->recurlyClient);
      }
      catch (\Recurly_NotFoundError $e) {
        $this->messenger()->addMessage($this->t('Account not found'));
        watchdog_exception('recurly', $e);
      }

      // If we couldn't get anything, just attempt to use the submitted data.
      if (empty($recurly_account)) {
        $recurly_account = $notification->account;
      }

      // Look for a pre-existing local record.
      $local_account = recurly_account_load([
        'account_code' => $recurly_account->account_code,
      ], TRUE);

      // If no local record exists and we've specified to create it...
      if (empty($local_account)) {
        // First try to match based on the account code.
        // i.e. "user-1" would match the user with UID 1.
        $parts = explode('-', $recurly_account->account_code);
        $entity_type = $this->config('recurly.settings')->get('recurly_entity_type');
        if ($parts[0] === $entity_type) {
          if (isset($parts[1]) && is_numeric($parts[1])) {
            recurly_account_save($recurly_account, $entity_type, $parts[1]);
          }
        }
        // Attempt to find a matching user account by e-mail address if the
        // enabled entity type is user.
        elseif ($entity_type === 'user' && ($user = user_load_by_mail($recurly_account->email))) {
          recurly_account_save($recurly_account, 'user', $user->id());
        }
      }
      elseif (!empty($local_account)) {
        // Otherwise if a local record was found and we want to keep it
        // synchronized, save it again and let any modules respond.
        recurly_account_save($recurly_account, $local_account->entity_type, $local_account->entity_id);
      }
    }

    // Allow other modules to respond to incoming notifications.
    $this->moduleHandler()->invokeAll('recurly_process_push_notification', [
      $subdomain,
      $notification,
    ]);

    // Reply with the OK status code.
    return new Response('OK', Response::HTTP_OK);
  }

}
