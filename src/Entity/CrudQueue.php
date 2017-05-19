<?php

/**
 * Defines \Drupal\aws_sqs_entity\Entity\CrudQueue.
 */

namespace Drupal\aws_sqs_entity\Entity;

/**
 * Class CrudQueue
 * @package Drupal\aws_sqs_entity\Entity
 *
 * Encapsulates AWS SQS Entity module's Entity CRUD queue logic.
 */
class CrudQueue extends \AwsSqsQueue {

  /**
   * @var string $type
   *   The queue item Entity type.
   */
  protected $type;

  /**
   * @var object $entity
   *   The queue item Entity object.
   */
  protected $entity;

  /**
   * @var string $op
   *   The queue item Entity CRUD operation. Can be one of:
   *   - insert
   *   - update
   *   - delete
   */
  protected $op;

  /**
   * @var array $messageAttributes
   *   An Associative array of <String> keys mapping to (associative-array)
   *   values. Each array key should be changed to an appropriate <String>. Each
   *   message attribute consists of a Name, Type, and Value.
   *
   * For more information, see @link http://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/SQSMessageAttributes.html#SQSMessageAttributesNTV Message Attribute Items. @endlink
   */
  protected $messageAttributes;

  /**
   * {@inheritdoc}
   *
   * This method should not be called directly. Instead, use getQueue().
   *
   * Example:
   * @code
   * $queue = \Drupal\aws_sqs_entity\Entity\CrudQueue::getQueue($type, $entity, $op);
   * @endcode
   */
  public function __construct($name) {
    parent::__construct($name);
  }

  /**
   * Returns a CrudQueue object loaded with Entity CRUD information.
   *
   * @todo Ensure the CrudQueue class is always be created with this method so
   *   that it is properly loaded with Entity CRUD info. As long as this class
   *   extends \AwsSqsQueue we can not protect the constructor method, and do
   *   not want to (it would need to be called by DrupalQueue::get()).
   *
   *   We might instead change our approach entirely, and use this class as a
   *   wrapper, only to encapsulate our module's logic. If so, we could always
   *   set queue_class_$name variable to AwsSqsQueue when our wrapper is called.
   *
   * Additionally sets queue_class_$name during queue creation when this class
   * (or any class that extends it) is called using CrudQueue::getQueue(). We do
   * not set this variable in __construct() because it must be set before
   * DrupalQueue::get() (which in turn uses our class defined in that variable).
   * Note that currently the way aws_sqs.module handles this is to allow
   * overriding the global queue_default_class variable. But that is overkill as
   * a Drupal site may want to use multiple types of queues. Our getQueue()
   * method allows that.
   * @todo Create a d.o patch with similar functionality for aws_sqs.module.
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
   *
   * @return \Drupal\aws_sqs_entity\Entity\CrudQueue|false
   *
   * @see DrupalQueue::get()
   */
  static public function getQueue($type, $entity, $op) {
    if ($name = variable_get('aws_sqs_entity_queue_name')) {
      // Our calling class must be set as the value of queue_class_$name
      // variable before calling DrupalQueue::get() below. Otherwise get() will
      // return SystemQueue or a class defined by queue_default_class variable.
      variable_set('queue_class_' . $name, get_called_class());
      $queue = \DrupalQueue::get($name);
      if ($queue instanceof CrudQueue) {
        $queue->type = $type;
        $queue->entity = $entity;
        $queue->op = $op;

        // Set message attributes.
        $queue->setMessageAttribute('entityType', 'String', 'StringValue', $type);
        switch ($op) {
          case 'insert':
          case 'update':
            $queue->setMessageAttribute('action', 'String', 'StringValue', 'post');
            break;
          case 'delete':
            $queue->setMessageAttribute('action', 'String', 'StringValue', 'delete');
            break;
        }

        return $queue;
      }
    }

    return FALSE;
  }

  /**
   * Adds an AWS SQS queue item message attribute to the $messageAttributes var.
   *
   * Name, type, and value must not be empty or null. In addition, the message
   * body should not be empty or null. All parts of the message attribute,
   * including name, type, and value, are included in the message size
   * restriction, which is currently 256 KB (262,144 bytes).
   *
   * @param string $key
   *   Custom attribute key name.
   * @param string $dataType
   *   Amazon SQS supports the following logical data types: String, Number, and
   *   Binary. In addition, you can append your own custom labels:
   *   - String.<Custom Type> (Optional)
   *   - Number.<Custom Type> (Optional)
   *   - Binary.<Custom Type> (Optional)
   * @param string $valueType
   *   Can be one of:
   *   - StringValue: (string) Strings are Unicode with UTF8 binary encoding.
   *   - BinaryValue: (string) Binary type attributes can store any binary data,
   *     for example, compressed data, encrypted data, or images.
   * @param string $value
   *
   * @see $messageAttributes
   */
  protected function setMessageAttribute($key, $dataType, $valueType, $value) {
    $this->messageAttributes[$key] = array(
      $valueType => $value,
      // DataType is required.
      'DataType' => $dataType,
    );
  }

  /**
   * Attempt to send an Entity item to the AWS queue.
   *
   * This convenience method wraps various required checks before creating a
   * queue item. If successful, it also invokes hooks.
   *
   * @see hook_aws_sqs_entity_send_item()
   * @see hook_aws_sqs_entity_send_item_alter()
   *
   * @return bool
   *   TRUE if the item is sent successfully, FALSE otherwise.
   */
  public function sendItem() {
    if (!$this->checkRules()) {
      return FALSE;
    }

    // This hook could allow, for example, transforming Drupal's internal Entity
    // schema into a different expected schema.
    // @todo Implement this alter hook for Entity data => API schema mapping.
    $data = clone $this->entity;
    drupal_alter('aws_sqs_entity_send_item', $data, $this->type, $this->op);

    // Always a required step before attempting to create a queue item.
    $this->createQueue();
    if ($result = $this->createItem($data)) {
      // Pass original keys to notification hook. This hook could allow, for
      // example, various kinds of reporting.
      module_invoke_all('aws_sqs_entity_send_item', $this->type, $this->entity, $this->op);

      if (variable_get('aws_sqs_entity_display_message')) {
        list($id,, $bundle) = entity_extract_ids($this->type, $this->entity);
        $vars = array(
          '%label' => entity_label($this->type, $this->entity),
          '%type' => $this->type,
          '%id' => $id,
          '%bundle' => $bundle,
          '%op' => $this->op,
          '%queue_name' => $this->getName()
        );
        drupal_set_message(t(variable_get('aws_sqs_entity_display_message_pattern', ''), $vars));
      }
      $created = TRUE;
    }

    if (variable_get('aws_sqs_entity_debug_message') || variable_get('aws_sqs_entity_debug_watchdog')) {
      // @todo Allow this debug pattern to be configured.
      $debug_info = array(
        t('Message status') => $result ? t('Success') : t('Failure'),
        'QueueUrl'    => $this->getQueueUrl(),
        'MessageAttributes' => $this->messageAttributes,
        'MessageBody' => $this->serialize($data),
        t('Unserialized body') => $data,
      );

      if (variable_get('aws_sqs_entity_debug_watchdog')) {
        $vars['queue_name'] = $this->getName();
        $message = !empty($created) ? t('Success: An AWS SQS item was created in queue %queue_name:', $vars) : t('Failure: An AWS SQS item was not created in queue %queue_name:', $vars);
        watchdog('aws_sqs_entity', $message . '<pre>' . print_r($debug_info, TRUE) . '</pre>');
      }

      if (variable_get('aws_sqs_entity_debug_message')) {
        switch (variable_get('aws_sqs_entity_debug_message_style')) {
          case 'drupal_set_message':
            drupal_set_message('<pre>' . print_r($debug_info, TRUE) . '</pre>');
            break;
          case 'dpm':
            if (module_exists('devel')) {
              dpm($debug_info);
            }
            else {
              drupal_set_message(t('You have selected Devel DPM for Debug message style, but the Devel module is no longer enabled.'), 'warning');
            }
            break;
        }
      }
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   *
   * Overrides parent method to allow setting MessageAttributes.
   *
   * @todo Patch aws_sqs module to allow setting MessageAttributes.
   *
   * See @link http://docs.aws.amazon.com/aws-sdk-php/v2/api/class-Aws.Sqs.SqsClient.html#_sendMessage SqsClient sendMessage Basic formatting example. @endlink
   *
   * @see \AwsSqsQueue::createItem()
   */
  public function createItem($data) {

    // Encapsulate our data
    $serialized_data = $this->serialize($data);

    // Check to see if someone is trying to save an item originally retrieved
    // from the queue. If so, this really should have been submitted as
    // $item->data, not $item. Reformat this so we don't save metadata or
    // confuse item_ids downstream.
    if (is_object($data) && property_exists($data, 'data') && property_exists($data, 'item_id')) {
      $text = t('Do not re-queue whole items retrieved from the SQS queue. This included metadata, like the item_id. Pass $item->data to createItem() as a parameter, rather than passing the entire $item. $item->data is being saved. The rest is being ignored.');
      $data = $data->data;
      watchdog('aws_sqs', $text, array(), WATCHDOG_ERROR);
    }

    // @todo Add a check here for message size? Log it?

    // Create a new message object
    //$result = $this->client->sendMessage(array(
    //  'QueueUrl'    => $this->queueUrl,
    //  'MessageBody' => $serialized_data,
    //));
    // Add MessageAttributes - the only reason we're overriding this method.
    $args = array(
      'QueueUrl'    => $this->getQueueUrl(),
      'MessageBody' => $serialized_data,
    );
    if (!empty($this->messageAttributes)) {
      $args['MessageAttributes'] = $this->messageAttributes;
    }
    $result = $this->getClient()->sendMessage($args);

    return (bool) $result;
  }

  /**
   * Sets Entity SQS rules.
   *
   * @param array $rules
   *   An associative array of Entity types, bundles, and CRUD operations which
   *   should trigger an AWS SQS message.
   *
   * Example:
   * @code
   * $rules['node']['article'] = ['insert', 'update', 'delete'];
   * $rules['node']['page'] = ['insert', 'update'];
   * $rules['taxonomy_term']['keywords'] = ['insert', 'update'];
   * $queue = new \Drupal\aws_sqs_entity\Entity\CrudQueue('aws_test', 'us-east-2');
   * $queue->setRules($rules);
   * @endcode
   *
   * @see getRules()
   */
  public static function setRules($rules) {
    variable_set('aws_sqs_entity_rules', $rules);
  }

  /**
   * Gets Entity SQS rules.
   *
   * @return array
   *   An associative array of Entity types, bundles, and CRUD operations which
   *   should trigger an AWS SQS message. See
   *
   * @see setRules()
   */
  public static function getRules() {
    return variable_get('aws_sqs_entity_rules', array());
  }

  /**
   * Checks rules to see if an Entity CRUD operation should trigger a SQS message.
   *
   * @return bool
   *   Whether or not the CRUD operation should trigger a SQS message for the
   *   given Entity.
   */
  public function checkRules() {
    $rules = CrudQueue::getRules();
    list(,, $bundle) = entity_extract_ids($this->type, $this->entity);
    return !empty($rules[$this->type][$bundle]) && in_array($this->op, $rules[$this->type][$bundle]);
  }

  /**
   * {@inheritdoc}
   *
   * Allows configuring serialization strategy, using the Drupal variable
   * aws_sqs_entity_serialize_callback. Defaults to JSON for interoperability
   * with external systems, or to the parent method if a non-callable variable
   * is set.
   *
   * @todo Add option to pass args, for example:
   *   @code json_encode($data, JSON_PRETTY_PRINT) @endcode. Sadly
   *   drupal_json_encode() does not include a JSON_PRETTY_PRINT option.
   *
   * @see \AwsSqsQueue::serialize()
   * @see CrudQueue::unserialize()
   */
  protected static function serialize($data) {
    $name = 'aws_sqs_entity_serialize_callback';
    $default = 'drupal_json_encode';
    return ($callback = variable_get($name, $default)) && is_callable($callback) ? $callback($data) : parent::serialize($data);
  }

  /**
   * {@inheritdoc}
   *
   * Allows configuring serialization strategy, using the Drupal variable
   * aws_sqs_entity_unserialize_callback. Defaults to JSON for interoperability
   * with external systems, or to the parent method if a non-callable variable
   * is set.
   *
   * @see \AwsSqsQueue::serialize()
   * @see CrudQueue::serialize()
   */
  protected static function unserialize($data) {
    $name = 'aws_sqs_entity_unserialize_callback';
    $default = 'drupal_json_decode';
    return ($callback = variable_get($name, $default)) && is_callable($callback) ? $callback($data) : parent::unserialize($data);
  }

}
