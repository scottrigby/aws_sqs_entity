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
   * @var string $messageId
   *   The SQS MessageId.
   */
  protected $messageId;

  /**
   * {@inheritdoc}
   *
   * This method should not be called directly. Instead, use getQueue().
   * Otherwise any overridden child class, if configured, would not be called.
   *
   * @see \Drupal\aws_sqs_entity\Entity\CrudQueue::getQueue()
   */
  public function __construct($name, $type, $entity, $op) {
    $this->type = $type;
    $this->op = $op;
    // Clone object so subsequent operations don't manipulate the original
    // Entity by reference.
    $this->entity = clone $entity;

    // Set message attributes.
    $this->setMessageAttribute('entityType', 'String', 'StringValue', $type);
    switch ($op) {
      case 'insert':
      case 'update':
      $this->setMessageAttribute('action', 'String', 'StringValue', 'post');
        break;
      case 'delete':
        $this->setMessageAttribute('action', 'String', 'StringValue', 'delete');
        break;
    }

    parent::__construct($name);
  }

  /**
   * Returns an instance of the configured CrudQueue class object.
   *
   * Note we do not call \DrupalQueue::get($name) because it doesn't allow
   * passing additional params - specifically, the Entity CRUD information we
   * need. Because of that, there is no need to bother setting the
   * queue_class_$name Drupal variable.
   *
   * We also do not call \AwsSqsQueue::get($name) because it hard-codes a call
   * to it's own class. We do not override that method here because we have only
   * one queue name, which we can retrieve from the Drupal variable
   * aws_sqs_entity_queue_name here. We also want to pass Entity CRUD
   * information, which parent::get() doesn't allow.
   *
   * Also note that aws_sqs.module attempts to handle this by allowing a global
   * override of the queue_default_class variable. But that is overkill as
   * Drupal sites often want to use multiple types of queues. Our getQueue()
   * method allows for this flexibility.
   *
   * The Drupal variable aws_sqs_entity_queue_class can be used to configure the
   * SQS Entity queue class, because we want to allow modules to extend this
   * class (rather than stuffing it full of alter hooks). In order to ensure SQS
   * Entity functionality, the configured class should extend this one.
   *
   * Usage example:
   * @code
   * variable_set('aws_sqs_entity_queue_class', 'MyCustomCrudsQueue');
   * $queue = \Drupal\aws_sqs_entity\Entity\CrudQueue::getQueue($type, $entity, $op);
   * @endcode
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
   * @param bool $skip_rules
   *   Optionally skip CrudQueue::checkRules().
   *
   * @see \Drupal\aws_sqs_entity\Entity\CrudQueue::__construct()
   *
   * @return \Drupal\aws_sqs_entity\Entity\CrudQueue|false
   */
  static public function getQueue(string $type, $entity, string $op, bool $skip_rules = TRUE) {
    $class = variable_get('aws_sqs_entity_queue_class', AWS_SQS_ENTITY_QUEUE_CLASS_DEFAULT);
    $name = variable_get('aws_sqs_entity_queue_name');
    $rules_pass = $skip_rules ? TRUE : self::checkRules($type, $entity, $op);

    if (!$class || !$name || !$rules_pass) {
      return FALSE;
    }

    // Get the fully loaded entity for Field Collections. This is a particularly
    // tricky bug when - with certain combinations of modules - some Entity
    // types such as field_collection, when EMD-wrapped, will fail either on
    // foreach getIterator(), or on value() - only when CRUD-triggered.
    // @todo Investigate this patch: https://www.drupal.org/node/1013428
    list($id) = entity_extract_ids($type, $entity);
    $entity = entity_load_single($type, $id);

    // @todo Pass any overloaded args.
    return new $class($name, $type, $entity, $op);
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
  public function setMessageAttribute($key, $dataType, $valueType, $value) {
    $this->messageAttributes[$key] = array(
      $valueType => $value,
      // DataType is required.
      'DataType' => $dataType,
    );
  }

  /**
   * Get the SQS Message Attributes.
   *
   * @return array
   *   The SQS Message Attributes.
   */
  protected function getMessageAttributes() {
    return $this->messageAttributes;
  }

  /**
   * Set the SQS Message ID.
   *
   * @param string $id
   *   The SQS Message ID.
   */
  protected function setMessageId($id) {
    $this->messageId = $id;
  }

  /**
   * Get the SQS Message ID.
   *
   * @return string|null
   *  The SQS Message ID of the last message. If no message sent yet, returns NULL.
   */
  protected function getMessageId() {
    return $this->messageId;
  }

  /**
   * Gets the $messageBody var, for the queue item message body content.
   *
   * Override this method if you want to normalize, or otherwise alter the queue
   * item message body. This could allow, for example, transforming Drupal's
   * internal Entity schema into a different expected schema.
   *
   * @return object
   */
  protected function getMessageBody() {
    return $this->entity;
  }

  /**
   * Attempt to send an Entity item to the AWS queue.
   *
   * This convenience method wraps various required checks before creating a
   * queue item. If successful, it also invokes hooks.
   *
   * @see hook_aws_sqs_entity_send_item()
   *
   * @return bool
   *   TRUE if the item is sent successfully, FALSE otherwise.
   */
  public function sendItem() {
    $result = FALSE;
    // Always a required step before attempting to create a queue item.
    $this->createQueue();

    $data = $this->getMessageBody();
    try {
      $result = $this->createItem($data);

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
    catch (\Aws\Sqs\Exception\SqsException $e) {
      // For SQS Exceptions, we can gather additional debugging data.
      $error['response_message'] = format_string(
        '@message Error type: @type.',
        [
          '@message' => $e->getMessage(),
          '@type' => $e->getAwsErrorType(),
        ]
      );
      $error['response_code'] = $e->getAwsErrorCode();
      $error['response_raw'] = $e->getResponse();
    }
    catch (\Exception $e) {
      $error['response_message'] = $e->getMessage();
      $error['response_code'] = $e->getCode();
    }

    // Pass original keys to notification hook. This hook could allow, for
    // example, various kinds of reporting.
    $item_info = [
      'type' => $this->type,
      'entity' => $this->entity,
      'op' => $this->op,
      'result' => $result,
      'data' => $data,
      'message' => [
        'attributes' => $this->getMessageAttributes(),
        'id' => $this->getMessageId(),
      ],
    ];
    if (isset($error)) {
      $debug_info['error'] = $error;
    }
    module_invoke_all('aws_sqs_entity_send_item', $item_info);

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
    $this->setMessageId($result->get('MessageId'));

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
   * @param string $type
   * @param object $entity
   * @param string $op
   *
   * @see getQueue()
   *
   * @return bool
   *   Whether or not the CRUD operation should trigger a SQS message for the
   *   given Entity.
   */
  public static function checkRules(string $type, $entity, string $op) {
    $rules = CrudQueue::getRules();
    list(,, $bundle) = entity_extract_ids($type, $entity);
    return !empty($rules[$type][$bundle]) && in_array($op, $rules[$type][$bundle]);
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
