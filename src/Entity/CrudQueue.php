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

        return $queue;
      }
    }

    return FALSE;
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
    $data = array($this->type, $this->entity, $this->op);
    drupal_alter('aws_sqs_entity_send_item', $data);

    // Always a required step before attempting to create a queue item.
    $this->createQueue();
    if ($result = $this->createItem($data)) {
      // Pass original keys to notification hook. This hook could allow, for
      // example, various kinds of reporting.
      module_invoke_all('aws_sqs_entity_send_item', $this->type, $this->entity, $this->op);
    }

    return $result;
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
    return variable_get('aws_sqs_entity_rules');
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

}
