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
   * Note this method should not be called directly. Instead, use get().
   *
   * Example:
   * @code
   * $queue = \Drupal\aws_sqs_entity\Entity\CrudQueue::get($type, $entity, $op);
   * @endcode
   *
   * Additionally sets queue_class_$name during queue creation when this class
   * (or any class that extends it) is called directly. From that point forward,
   * getting the queue with DrupalQueue::get() will automatically use our set
   * class name.
   *
   * This allows us to do the following:
   * @code
   * class CustomCrud extends \Drupal\aws_sqs_entity\Entity\CrudQueue {}
   * // Creating a queue sets the queue_class_$name variable.
   * $queue = new \CustomCrud('aws_test', 'us-east-2');
   * // Getting the queue as an instance of the initially called class.
   * $queue = DrupalQueue::get('aws_test');
   * @endcode
   *
   * Note that currently the way aws_sqs.module handles this is to allow
   * overriding the global queue_default_class variable. But this is overkill as
   * a Drupal site may want to use multiple types of queues.
   *
   * @todo Create a d.o patch to include this functionality in aws_sqs.module.
   *
   * @see DrupalQueue::get()
   */
  public function __construct($name) {
    // @todo Return fully namespaced class name, and be sure DrupalQueue::get()
    //   calls it properly.
    variable_set('queue_class_' . $name, get_called_class());

    parent::__construct($name);
  }

  /**
   * Returns a CrudQueue object.
   *
   * Note this overrides \AwsSqsQueue::get() to add our module specific logic.
   * We also add logic here instead of overloading the constructor because we
   * can get the queue name ourselves, so it's unnecessary to ask modules to
   * pass in the required $name param to construct the queue class manually.
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
   * @see createQueue()
   * @see createItem()
   */
  static public function get($type, $entity, $op) {
    if ($name = variable_get('aws_sqs_entity_queue_name')) {
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
    return !empty($rules[$this->type][$bundle]) && in_array($this->op, $rules[$this->type][$bundle][$this->op]);
  }

}
