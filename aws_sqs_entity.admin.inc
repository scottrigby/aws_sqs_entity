<?php

/**
 * @file
 * Admin functions for AWS SQS Entity module.
 */

/**
 * Form constructor for AWS SQS Entity settings.
 *
 * @see aws_sqs_entity_settings_validate()
 * @see aws_sqs_entity_settings_submit()
 */
function aws_sqs_entity_settings($form, &$form_state) {
  $form['queue'] = array(
    '#type' => 'fieldset',
    '#title' => t('Queue settings'),
  );
  $form['queue']['aws_sqs_entity_queue_name'] = array(
    '#type' => 'textfield',
    '#title' => t('AWS SQS Entity queue name'),
    '#default_value' => variable_get('aws_sqs_entity_queue_name', ''),
    '#required' => TRUE,
    '#description' => t('AWS SQS Entity queue name.'),
  );

  $form['rules'] = array(
    '#type' => 'fieldset',
    '#title' => t('Rules'),
  );
  $rules = variable_get('aws_sqs_entity_rules', array());
  $form['rules']['aws_sqs_entity_rules'] = array(
    '#type' => 'textarea',
    '#title' => t('AWS SQS Entity rules'),
    // This could be longer, but is likely to be more than the default 6.
    '#rows' => 10,
    '#default_value' => aws_sqs_entity_yaml_dump($rules),
    '#description' => t('Configure the list of Entity types, bundles, and CRUD
   operations which should trigger an AWS SQS message. This should be an array
   of CRUD operations (insert, update and/or delete), keyed by bundle and Entity
   type, following <a href="@yaml-format">YAML</a> format. Example:
<pre><code>
node:
    page: [insert, update, delete]
    article: [insert, update]
taxonomy_term:
    tags: [insert, update, delete]
</code></pre>', array('@yaml-format' => 'http://symfony.com/doc/current/components/yaml/yaml_format.html#collections')),
  );

  $form['messages'] = array(
    '#type' => 'fieldset',
    '#title' => t('Messages'),
  );
  $form['messages']['aws_sqs_entity_display_message'] = array(
    '#type' => 'checkbox',
    '#default_value' => variable_get('aws_sqs_entity_display_message', 0),
    '#title' => t('Display a friendly message informing editors that Entity CRUD items were added to the Queue.'),
  );
  $default = t('An AWS SQS item has been created in the Queue %queue_name for %label (Entity type: %type, ID: %id, Bundle: %bundle), for CRUD operation: %op.');
  $form['messages']['aws_sqs_entity_display_message_pattern'] = array(
    '#type' => 'textarea',
    '#title' => t('Display message pattern'),
    // The default message is too big for textfield, but likely won't be more
    // than 2 lines.
    '#rows' => 2,
    '#default_value' => variable_get('aws_sqs_entity_display_message_pattern', $default),
    '#description' => t('Replacement tokens are %label (the Entity label), %type (the Entity type), %bundle (the Entity Bundle), %op (the Entity CRUD operation, %queue_name (the AWS SQS Entity queue name).'),
    '#states' => array(
      'visible' => array(
        ':input[name="aws_sqs_entity_display_message"]' => array('checked' => TRUE),
      ),
    ),
  );

  $form['debugging'] = array(
    '#type' => 'fieldset',
    '#title' => t('Debugging'),
  );
  $form['debugging']['aws_sqs_entity_debug_watchdog'] = array(
    '#type' => 'checkbox',
    '#default_value' => variable_get('aws_sqs_entity_debug_watchdog', 0),
    '#title' => t('Send verbose debug information to watchdog.'),
  );
  $form['debugging']['aws_sqs_entity_debug_message'] = array(
    '#type' => 'checkbox',
    '#default_value' => variable_get('aws_sqs_entity_debug_message', 0),
    '#title' => t('Display verbose debug information to the screen.'),
  );


  $debug_message_styles['drupal_set_message'] = t('Drupal message');
  if ($devel_enabled = module_exists('devel')) {
    $debug_message_styles['dpm'] = t('Devel DPM');
  }
  $form['debugging']['aws_sqs_entity_debug_message_style'] = array(
    '#type' => 'radios',
    '#title' => t('Debug message style'),
    '#default_value' => variable_get('aws_sqs_entity_debug_message_style', 'drupal_set_message'),
    '#options' => $debug_message_styles,
    '#description' => $devel_enabled ? t('Choose between standard Drupal message, and Devel DPM.') : t('If you enable the Devel module, you will be able to choose between standard Drupal message, and Devel DPM.'),
    '#states' => array(
      'visible' => array(
        ':input[name="aws_sqs_entity_debug_message"]' => array('checked' => TRUE),
      ),
    ),
  );

  $form['submit'] = array('#type' => 'submit', '#value' => t('Save configuration'));

  return $form;
}

/**
 * Validate handler for AWS SQS Entity settings.
 *
 * @see aws_sqs_entity_settings()
 * @see aws_sqs_entity_settings_submit()
 */
function aws_sqs_entity_settings_validate($form, &$form_state) {
  $rules = aws_sqs_entity_yaml_parse($form_state['values']['aws_sqs_entity_rules']);
  if ($messages = aws_sqs_entity_validate_rules($rules)) {
    form_set_error('aws_sqs_entity_rules', theme('item_list', array('items' => $messages)));
  }
  else {
    $form_state['values']['aws_sqs_entity_rules_array'] = $rules;
  }
}

/**
 * Validates AWS SQS Entity Rules configuration.
 *
 * @param array $rules
 *   An associative array of AWS SQS Entity rules.
 *
 * @todo Move this validation to CrudQueue class. It could return an array of
 *   failure messages.
 *
 * @return array
 *   An array of validation error messages.
 *
 * @see \Drupal\aws_sqs_entity\Entity\CrudQueue::setRules()
 */
function aws_sqs_entity_validate_rules($rules) {
  $info = entity_get_info();

  $messages = array();
  foreach ($rules as $type => $bundles) {
    $args['@type'] = $type;
    if (!array_key_exists($type, $info)) {
      $messages[] = t('@type is not a valid Entity type.', $args);
      continue;
    }
    foreach ($bundles as $bundle => $ops) {
      $args['@bundle'] = $bundle;
      if (!array_key_exists($bundle, $info[$type]['bundles'])) {
        $messages[] = t('@bundle is not a valid Bundle of Entity type @type.', $args);
        continue;
      }
      foreach ($ops as $op) {
        $args['@op'] = $op;
        if (!in_array($op, array('insert', 'update', 'delete'))) {
          $messages[] = t('@op is not a valid Entity CRUD operation.', $args);
          continue;
        }
      }
    }
  }

  return $messages;
}

/**
 * Submit handler for AWS SQS Entity settings.
 *
 * Note we don't use system_settings_form() because we want to easily override
 * the aws_sqs_entity_rules on form submission.
 *
 * @see aws_sqs_entity_settings()
 * @see aws_sqs_entity_settings_validate()
 */
function aws_sqs_entity_settings_submit($form, &$form_state) {
  $variables = array(
    'aws_sqs_entity_queue_name',
    'aws_sqs_entity_display_message',
    'aws_sqs_entity_display_message_pattern',
    'aws_sqs_entity_debug_watchdog',
    'aws_sqs_entity_debug_message',
    'aws_sqs_entity_debug_message_style',
  );
  foreach ($variables as $name) {
    $value = $form_state['values'][$name];
    variable_set($name, $value);
  }

  $rules = $form_state['values']['aws_sqs_entity_rules_array'];
  variable_set('aws_sqs_entity_rules', $rules);
}

/**
 * Converts config text to the expected rules array format.
 *
 * @param string $string
 *   A YAML-formatted config string, representing an associative array.
 *
 * @return array
 *   An associative array.
 *
 * @see aws_sqs_entity_yaml_parse()
 */
function aws_sqs_entity_yaml_parse($string) {
  $parser = new \Symfony\Component\Yaml\Parser();
  $array = $parser->parse($string);
  return $array;
}

/**
 * Converts AWS SQS Entity rules array into a human-readable config text string.
 *
 * @param array $array
 *   An associative array.
 *
 * @return string
 *   A YAML-formatted config string, representing an associative array.
 *
 * @see aws_sqs_entity_yaml_parse()
 */
function aws_sqs_entity_yaml_dump(array $array) {
  $dumper = new \Symfony\Component\Yaml\Dumper();
  $string = $dumper->dump($array, 2);
  return $string;
}
