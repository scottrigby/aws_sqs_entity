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
 * Allows modules to declare paths to their own property mapping YAML configs.
 *
 * @return array
 *   An associative array of full system paths to directories containing YAML
 *   property mapper configs.
 *
 * The first config file matching the requested pattern will end the search, so
 * if you wish to take precedence over another module's config file for the same
 * bundle, implement hook_module_implements_alter().
 *
 * Config file name pattern must be:
 * - aws_sqs_entity.property_mapper.{ENTITY_TYPE}.{BUNDLE}.yml
 *
 * Each YAML file should contain:
 * - itemType: The name of the external API resource.
 * - field_map: An associative array of fields or properties on the Drupal
 *   Entity, keyed by the mapped external API resource fields. Values may be
 *   an associative array of nested field_map key/value pairs.
 *
 * Example YAML config:
 * - my_module/path/to/config/aws_sqs_entity.property_mapper.node.article.yaml:
 * @code
 * itemType: post
 * field_map:
 *   uuid: uuid
 *   revision: vid
 *   title: title
 *   tags: field_tags
 *   published: status
 *   image: field_image
 *   description: body
 *   nestedMap:
 *     nested1: uuid
 *     nested2: vid
 *     nested3: title
 * @endcode
 */
function hook_aws_sqs_entity_property_mapper_config_paths() {
  return [DRUPAL_ROOT . '/' . drupal_get_path('module', 'my_module') . '/path/to/config'];
}

/**
 * Allows modules to declare property mapper normalizers.
 *
 * @return array
 *   An array of fully namespaced normalizer class names. Must extend
 *   AbstractEntityValueWrapperNormalizer.
 *
 * @see \Drupal\aws_sqs_entity\Normalizer\AbstractEntityValueWrapperNormalizer
 */
function hook_aws_sqs_entity_value_wrapper_normalizers() {
  return [
    '\Drupal\my_module\Normalizer\EndpointOneNormalizer',
    '\Drupal\my_module\Normalizer\EndpointTwoNormalizer',
  ];
}

/**
 * @} End of "addtogroup hooks".
 */
