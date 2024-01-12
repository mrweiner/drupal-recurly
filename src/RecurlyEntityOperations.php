<?php

namespace Drupal\recurly;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Utility\Token;

/**
 * Defines a class for reacting to entity events.
 */
class RecurlyEntityOperations {

  /**
   * EntityTypeManagerInterface.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The translation manager service.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface
   */
  protected $stringTranslation;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The config factory object.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Service to retrieve token information.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Logger Factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The tokens mapping service.
   *
   * @var \Drupal\recurly\RecurlyTokenManager
   */
  protected $recurlyTokenManager;

  /**
   * Constructs a new RecurlyEntityOperations object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation_manager
   *   The translation manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory interface.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger_factory.
   * @param \Drupal\recurly\RecurlyTokenManager $recurly_token_manager
   *   A Recurly token manager object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger, TranslationInterface $translation_manager, ConfigFactoryInterface $config_factory, Token $token, LoggerChannelFactoryInterface $logger_factory, RecurlyTokenManager $recurly_token_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->stringTranslation = $translation_manager;
    $this->configFactory = $config_factory;
    $this->token = $token;
    $this->loggerFactory = $logger_factory;
    $this->recurlyTokenManager = $recurly_token_manager;
  }

  /**
   * Acts on an entity being updated.
   *
   * Update the Recurly remote account when the local Drupal entity is updated.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being updated.
   */
  public function entityUpdate(EntityInterface $entity) {
    $entity_type = $entity->getEntityTypeId();
    // If this isn't the enabled Recurly entity type, do nothing.
    if ($this->configFactory->get('recurly.settings')->get('recurly_entity_type') !== $entity_type) {
      return;
    }

    // Check if this entity has a remote Recurly account that we should sync.
    $local_account = recurly_account_load([
      'entity_type' => $entity_type,
      'entity_id' => $entity->id(),
    ], TRUE);
    if (!$local_account) {
      return;
    }

    // Check if any of the mapping tokens have changed. Note that
    // $entity->original only exists during a save operation. See
    // EntityStorageBase::save().
    if (!$original_entity = $entity->original) {
      return;
    }

    // Set default value for username before token mapping to
    // ensure token mapping gets the last say.
    $original_values['username'] = $original_entity->label();
    $updated_values['username'] = $entity->label();

    foreach ($this->recurlyTokenManager->tokenMapping() as $recurly_field => $token) {
      $original_values[$recurly_field] = $this->token->replace(
        $token,
        [$entity_type => $original_entity],
        ['clear' => TRUE, 'sanitize' => FALSE]
      );
      $updated_values[$recurly_field] = $this->token->replace(
        $token,
        [$entity_type => $entity],
        ['clear' => TRUE, 'sanitize' => FALSE]
      );
    }

    // If there are any changes, push them to Recurly.
    if ($original_values !== $updated_values) {
      $recurly_account = recurly_account_load([
        'entity_type' => $entity_type,
        'entity_id' => $entity->id(),
      ]);

      // This is set in recurly_account_load() if for some reason we can't
      // connect to Recurly to get the account object.
      if (!isset($recurly_account->orphaned)) {
        $address_fields = [
          'address1',
          'address2',
          'city',
          'state',
          'zip',
          'country',
          'phone',
        ];
        foreach ($updated_values as $field => $value) {
          if (strlen($value)) {
            if (in_array($field, $address_fields)) {
              // The Recurly PHP client doesn't check for nested objects when
              // determining what properties have changed when updating an
              // object. This works around it by re-assigning the address
              // property instead of directly modifying the address's fields.
              // This can be removed when
              // https://github.com/recurly/recurly-client-php/pull/80 is merged
              // in.
              //
              // $recurly_account->address->{$field} = $value;.
              $adr = $recurly_account->address;
              $adr->{$field} = $value;
              $recurly_account->address = $adr;
            }
            else {
              $recurly_account->{$field} = $value;
            }
          }
        }

        try {
          $recurly_account->update();
        }
        catch (\Recurly_Error $e) {
          $this->messenger->addWarning($this->stringTranslation->translate('The billing system reported an error: "@error" To ensure proper billing, please correct the problem if possible or contact support.', ['@error' => $e->getMessage()]));
          $this->loggerFactory->get('recurly')->error('Account information could not be sent to Recurly, it reported "@error" while trying to update <a href="@url">@title</a> with the values @values.', [
            '@error' => $e->getMessage(),
            '@title' => $entity->label(),
            '@url' => $entity->toUrl(),
            '@values' => print_r($updated_values, 1),
          ]);
        }
      }
      else {
        $this->messenger->addWarning($this->stringTranslation->translate('Unable to save updated account data. This is likely because we were unable to contact the billing system to synchronize changes.'));
        $this->loggerFactory->get('recurly')->error('Account information could not be sent to Recurly, could not load the Recurly_Account object while trying to update <a href="@url">@title</a> with the values @values.', [
          '@title' => $entity->label(),
          '@url' => $entity->toUrl(),
          '@values' => print_r($updated_values, 1),
        ]);
      }
    }
  }

  /**
   * Acts on an entity being deleted.
   *
   * This hook is *not* called when a user cancels their account through any
   * mechanism other than "delete account". This fires when user accounts are
   * being deleted, or when subscriptions are on other entities, such as nodes.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being updated.
   */
  public function entityDelete(EntityInterface $entity) {
    if (($entity_type = $entity->getEntityTypeId()) == $this->configFactory->get('recurly.settings')->get('recurly_entity_type')) {
      // Check for a local account first, no need to attempt to close an account
      // if we don't have any information about it.
      $local_account = recurly_account_load(
        [
          'entity_type' => $entity_type,
          'entity_id' => $entity->id(),
        ],
        TRUE
      );
      if ($local_account) {
        $recurly_account = recurly_account_load([
          'entity_type' => $entity_type,
          'entity_id' => $entity->id(),
        ]);
        recurly_account_delete($recurly_account);
      }
    }
  }

}
