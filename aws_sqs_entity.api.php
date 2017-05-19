<?php

/**
 * @file
 * Hooks provided by AWS SQS Entity module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Allows modules to respond after an item is sent to the AWS Queue.
 *
 * @param object $entity
 *   A clone of the Entity object, used as SQS queue item message body.
 * @param string $type
 *   The Entity type.
 * @param string $op
 *   The Entity CRUD operation. Can be one of:
 *   - insert
 *   - update
 *   - delete
 */
function hook_aws_sqs_entity_send_item_alter(&$entity, $type, $op) {
  $entity->my_key = my_value_callback($type, $entity, $op);
}

/**
 * Allows modules to respond after an item is sent to the AWS Queue.
 *
 * @param string $type
 *   The Entity type.
 * @param object $entity
 *   The Entity object.
 * @param string $op
 *   The Entity CRUD operation. Can be one of:
 *   - insert
 *   - update
 *   - delete
 */
function hook_aws_sqs_entity_send_item($type, $entity, $op) {
  // Example: Set custom message.
  $args = array('%op' => $op, '%type' => $type, '%title' => entity_label($type, $entity));
  drupal_set_message(t('Congrats! You posted a SQS %op message for the %type: %title', $args));
}

/**
 * @} End of "addtogroup hooks".
 */
