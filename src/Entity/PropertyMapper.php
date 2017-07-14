<?php

namespace Drupal\aws_sqs_entity\Entity;

use \Symfony\Component\Yaml\Yaml;
use \Symfony\Component\Serializer\Serializer;
use \Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Class PropertyMapper
 * @package Drupal\aws_sqs_entity\Entity
 *
 * Maps to YAML config, and normalizes Entity data before sending to the queue.
 */
class PropertyMapper extends CrudQueue {

  /**
   * @var string
   */
  protected $bundle;

  /**
   * @var array
   */
  protected $fieldMap = [];

  /**
   * @var \EntityDrupalWrapper
   */
  protected $wrapper;

  /**
   * @var array
   */
  protected $config = [];

  /**
   * {@inheritdoc}
   *
   * Additionally instantiate variables on this class needed for schema mapping.
   */
  public function __construct(string $name, string $type, \stdClass $entity, string $op) {
    // Bail now if we don't have Entity API enabled.
    $this->validateClass();

    // Allow parent method to set vars so we don't have to again here.
    parent::__construct($name, $type, $entity, $op);

    $this->setFieldMap();
    $this->setWrapper();
    $this->setBundle();
    $this->setConfig();
  }

  protected function validateClass() {
    if (!module_exists('entity')) {
      throw new \Exception('Entity API must be enabled to use this class: ' . __CLASS__);
    }
  }

  protected function setFieldMap() {
    $this->fieldMap = field_info_field_map();
  }

  protected function setWrapper() {
    $this->wrapper = entity_metadata_wrapper($this->type, $this->entity);
  }

  protected function setBundle() {
    $this->bundle = $this->wrapper->getBundle();
  }

  /**
   * Sets the config for the current bundle given a matching filepath.
   *
   * @see hook_aws_sqs_entity_property_mapper_config_paths()
   */
  protected function setConfig() {
    $paths = module_invoke_all('aws_sqs_entity_property_mapper_config_paths');
    $file_pattern = join('.', ['aws_sqs_entity', 'property_mapper', $this->type, $this->bundle, 'yml']);
    foreach ($paths as $path) {
      $filename = join('/', [$path, $file_pattern]);
      if (file_exists($filename)) {
        $this->config = Yaml::parse(file_get_contents($filename));
        // The first file found wins.
        break;
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * Overrides parent method for Entity data => API schema mapping.
   *
   * Note we can not use the recursive power of AbstractObjectNormalizer because
   * it does not know how to handle stdClass objects, nor the magic getters and
   * setters of EntityMetadataWrapper objects. Instead we handle Entity mapping
   * recursion ourselves, and invoke a hook for custom normalizers to return a
   * custom value for each passed EntityValueWrapper.
   * @see \Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer
   * @see \EntityValueWrapper
   * @see \Drupal\aws_sqs_entity\Normalizer\AbstractEntityValueWrapperNormalizer
   *
   * Note also that while ::serialize() may seem like the place to do all this
   * serializer work, we can not get important context information into that
   * method easily, because:
   * - ::serialize() must be static due to class inheritance, so we can not
   *   access $this.
   * - We can not easily pass context to ::serialize() because it is called by
   *   AwsSqsQueue::createItem(), which would have to be overwridden just to do
   *   this (and even then, $context would need to be passed by overloading the
   *   method, again because of class inheritance). That is a lot of extra code
   *   to maintain just to keep that method's semantic value here.
   * Instead we handle the serializer normalizers in this method because it has
   * access to $this for context, and save ::serialize() for JSON encoding only.
   *
   * @see CrudQueue::getMessageBody()
   */
  protected function getMessageBody() {
    $data = [];

    if (isset($this->config['field_map'])) {
      $this->yamlPropertyMapper($this->config['field_map'], $data);
    }

    return $data;
  }

  /**
   * Iterates over all config keys to find Drupal Entity mapped values.
   *
   * Receives config as array, and iterates over each YAML key (recursively, to
   * handle nested YAML maps).
   *
   * @param $fields
   *   See $field_map param of setConfig().
   * @param $data
   *   The return value of getMessageBody().
   */
  protected function yamlPropertyMapper(array $fields, array &$data) {
    foreach ($fields as $key => $field) {
      $value = NULL;

      // Add recursion to handle YAML config field_map values that are
      // associative arrays of field values (example, customFields).
      if (!is_string($field) && is_array($field)) {
        $data[$key] = [];
        $this->yamlPropertyMapper($field, $data[$key]);
        continue;
      }

      // Now that we have the Drupal Entity field/property, get each field
      // item(s) value.
      if ($this->fieldExists($field)) {
        $value = $this->EntityMetadataWrapper($this->wrapper->$field);
      }

      $data[$key] = $value;
    }
  }

  /**
   * @param \EntityMetadataWrapper $wrapper
   * @return array|object|string|\Symfony\Component\Serializer\Normalizer\scalar
   */
  protected function EntityMetadataWrapper(\EntityMetadataWrapper $wrapper) {
    $value = '';
    if (is_a($wrapper, 'EntityListWrapper')) {
      $value = $this->EntityListWrapper($wrapper);
    }
    elseif (is_a($wrapper, 'EntityValueWrapper')) {
      $value = $this->EntityValueWrapper($wrapper);
    }
    return $value;
  }

  /**
   * @param \EntityListWrapper $wrapper
   * @return array
   */
  protected function EntityListWrapper(\EntityListWrapper $wrapper) {
    $value = [];
    foreach ($wrapper->getIterator() as $delta => $itemWrapper) {
      $value[$delta] = $itemWrapper->EntityValueWrapper();
    }
    return $value;
  }

  /**
   * @param \EntityValueWrapper $wrapper
   * @return array|object|\Symfony\Component\Serializer\Normalizer\scalar
   *
   * @todo Ensure normalizers extend AbstractEntityValueWrapperNormalizer.
   */
  protected function EntityValueWrapper(\EntityValueWrapper $wrapper) {
    $normalizers = module_invoke_all('hook_aws_sqs_entity_value_wrapper_normalizers', $this->wrapper);
    $serializer = new Serializer($normalizers, []);
    $context = [
      'wrapper' => $this->wrapper,
      'config' => $this->config,
    ];
    return $serializer->normalize($this->wrapper, null, $context);
  }

  /**
   * {@inheritdoc}
   *
   * The $data array keys should match the YAML config map, and contain values
   * from EntityMetadataWrapper item wrapper ::value() set by each normalizer.
   *
   * @see getMessageBody()
   * @see AwsSqsQueue::serialize()
   */
  protected static function serialize(array $data) {
    $serializer = new Serializer([], [new JsonEncoder()]);
    return $serializer->encode($data, 'json');
  }

}
