<?php

namespace Drupal\aws_sqs_entity\Entity;

use \Symfony\Component\Yaml\Yaml;
use \Symfony\Component\Serializer\Serializer;
use \Symfony\Component\Serializer\Encoder\JsonEncoder;
use \Drupal\aws_sqs_entity\Normalizer\EntityMetadataWrapperNormalizer;

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
  public function __construct($name, $type, $entity, $op) {
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
   * Iterates over all config keys to find Drupal Entity mapped values.
   *
   * @param $fields
   *   See $field_map param of setConfig().
   * @param $data
   *   The return value of getMessageBody().
   */
  protected function iterateConfigKeys($fields, &$data) {
    foreach ($fields as $key => $field) {
      $value = NULL;

      // Add recursion to handle YAML config field_map values that are
      // associative arrays of field values (example, customFields).
      if (!is_string($field) && is_array($field)) {
        $data[$key] = [];
        $this->iterateConfigKeys($field, $data[$key]);
        continue;
      }

      // Now that we have the Drupal Entity field/property, get each field item
      // delta value.
      // @todo This does not yet allow properly handling plugin normalization.
      //   But move one fully working step at a time.
      if ($this->fieldExists($field)) {
        if ($this->isListWrapper($field)) {
          foreach ($this->wrapper->$field->getIterator() as $delta => $itemWrapper) {
            $value[$delta] = $this->normalizeItemValue($itemWrapper);
          }
        }
        else {
          // This is an EntityValueWrapper.
          $value = $this->normalizeItemValue($this->wrapper->$field);
        }
      }

      $data[$key] = $value;
    }
  }

  /**
   * @param \EntityMetadataWrapper $itemWrapper
   *
   * @todo Discover correct plugins.
   */
  protected function normalizeItemValue($itemWrapper) {
    // @todo Normalize this.
//    return $itemWrapper->value();

    $normalizers = module_invoke_all('aws_sqs_entity_normalizers', $itemWrapper);
    $serializer = new Serializer($normalizers, []);
    return $serializer->normalize($itemWrapper);
  }

  /**
   * {@inheritdoc}
   *
   * Overrides parent method for Entity data => API schema mapping.
   *
   * @todo Note that a lot of this work is related to serialization, so sounds
   *   like it should be in this::serialize(), however the $data param is sent
   *   to ::serialize, so we would just ignore that entirely. Perhaps that is
   *   less confusing though, given the fact that Symfony/Component/Serializer
   *   handles the JSON encoding in the same operation as normalizing.
   *   Another problem with doing this in ::serialize() is it must be static
   *   (because of inheritance), which means if it calls ::iterateConfigKeys()
   *   that would need to be refactored to be static as well. As an alternative,
   *   we could do all the serializing/normalizing work in this method, and
   *   ::serialize() could be a straight pass-through. We would just need to
   *   document that well, so it's clear why we have this semantic mismatch.
   *
   * @see CrudQueue::getMessageBody()
   */
  protected function getMessageBody() {
    $data = [];
    $this->iterateConfigKeys($this->config['field_map'], $data);

    $normalizers = [new EntityMetadataWrapperNormalizer()];
    $encoders = [new JsonEncoder()];
    $serializer = new Serializer($normalizers, $encoders);
    return $serializer->serialize($data, 'json');
  }

  /**
   * {@inheritdoc}
   *
   * The $data array at this point should match the final YAML map, and contain
   * values from EntityMetadataWrapper item wrapper ::value().
   *
   * Use Symfony Serializer component instead of json_encode().
   * Also normalize the $data (Drupal Entity) object before JSON encoding.
   *
   * @todo Identify how to bypass stdClass issues with Serializer::serialize()
   *   method (currently ObjectNormalizer does not work properly with stdClass).
   *   Until then, use only Serializer::encode().
   */
  protected static function serialize($data, $context) {
    $encoders = [new JsonEncoder()];
    $serializer = new Serializer([], $encoders);
    return $serializer->encode($data, 'json');
  }



  /**
   * @todo We may not need this method. Keep commented for now in case we do.
   */
//  protected function getFieldType($field_name) {
//    return $this->fieldMap[$field_name]['type'];
//  }

}
