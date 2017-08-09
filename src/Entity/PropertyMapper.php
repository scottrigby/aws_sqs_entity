<?php

namespace Drupal\aws_sqs_entity\Entity;

use \Symfony\Component\Yaml\Yaml;
use \Symfony\Component\Serializer\Serializer;
use \Symfony\Component\Serializer\Encoder\JsonEncoder;
use \Drupal\aws_sqs_entity\Normalizer\AbstractEntityValueWrapperNormalizer;

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
   * @var \EntityDrupalWrapper
   */
  protected $wrapper;

  /**
   * @var array
   */
  protected $config = [];

  /**
   * @var array
   */
  protected $normalizers = [];

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

    $this->setWrapper();
    $this->setBundle();
    $this->setConfig();
    $this->setNormalizers();
  }

  protected function validateClass() {
    if (!module_exists('entity')) {
      throw new \Exception('Entity API must be enabled to use this class.');
    }
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
   * Sets and validates normalizers.
   *
   * From Symfony\Component\Serializer\Serializer->normalize:
   * > You must register at least one normalizer to be able to normalize
   *   objects.
   *
   * @todo Ensure normalizers extend AbstractEntityValueWrapperNormalizer.
   *
   * @see \Symfony\Component\Serializer\Exception\LogicException
   * @see \Symfony\Component\Serializer\Serializer::normalize
   */
  protected function setNormalizers() {
    $normalizers = module_invoke_all('aws_sqs_entity_value_wrapper_normalizers', $this->wrapper);

    foreach ($normalizers as $normalizer) {
      if ($normalizer instanceof AbstractEntityValueWrapperNormalizer) {
        $this->normalizers[] = $normalizer;
      }
    }

    if (empty($this->normalizers)) {
      throw new \Exception('You must must register at least one valid normalizer to use this class. See hook_aws_sqs_entity_value_wrapper_normalizers().');
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
  protected function yamlPropertyMapper($fields, &$data) {
    foreach ($fields as $key => $field) {
      $value = NULL;

      // Add recursion to handle YAML config field_map values that are
      // associative arrays of field values (example, customFields).
      if (!is_string($field) && is_array($field)) {
        $data[$key] = [];
        $this->yamlPropertyMapper($field, $data[$key]);
        continue;
      }

      // Support dot notation for a trail of nested property definitions.
      $property_trail = [];
      if (strpos($field, '.') !== FALSE) {
        $property_trail = explode('.', $field);
        $field = $property_trail[0];
      }

      // Now that we have the Drupal Entity field/property, get each field
      // item(s) value.
      if (isset($this->wrapper->$field)) {
        $value = $this->EntityMetadataWrapper($this->wrapper->$field, $property_trail);
      }

      $data[$key] = $value;
    }
  }

  /**
   * @param \EntityMetadataWrapper $wrapper
   * @param array $property_trail
   * @return array|object|string|\Symfony\Component\Serializer\Normalizer\scalar
   */
  protected function EntityMetadataWrapper(\EntityMetadataWrapper $wrapper, $property_trail) {
    $value = '';
    if (is_a($wrapper, 'EntityListWrapper')) {
      $value = $this->EntityListWrapper($wrapper, $property_trail);
    }
    elseif (is_a($wrapper, 'EntityValueWrapper')) {
      $value = $this->EntityValueWrapper($wrapper, $property_trail);
    }
    return $value;
  }

  /**
   * @param \EntityListWrapper $wrapper
   * @param array $property_trail
   * @return array
   *
   * @todo Will there be cases where an iterated \EntityListWrapper item wrapper
   *   is not an instance of either \EntityValueWrapper or
   *   \EntityStructureWrapper? If so, we may want to expose some other way of
   *   allowing modules to address this. Ultimately though, modules may already
   *   extend this class with their own logic.
   *
   * @see \Symfony\Component\Serializer\Serializer::normalize()
   * @see \Drupal\aws_sqs_entity\Normalizer\AbstractEntityValueWrapperNormalizer::supportsNormalization()
   */
  protected function EntityListWrapper(\EntityListWrapper $wrapper, $property_trail) {
    $value = [];
    foreach ($wrapper->getIterator() as $delta => $itemWrapper) {
      // If the item wrapper doesn't extend one of these two types, our base
      // AbstractEntityValueWrapperNormalizer class won't support it.
      if ($itemWrapper instanceof \EntityValueWrapper || $itemWrapper instanceof \EntityStructureWrapper) {
        $value[$delta] = $this->EntityValueWrapper($itemWrapper, $property_trail);
      }
    }
    return $value;
  }

  /**
   * @param \EntityValueWrapper $wrapper
   *   Note this may not always be an instance of EntityValueWrapper. In some
   *   cases such as a taxonomy_term or entity reference, the value is another
   *   instance of EntityDrupalWrapper, so let's type hint the parent abstract
   *   EntityMetadataWrapper.
   * @param array $property_trail
   *   An array of Drupal Entity mapped value properties defined in YAML. This
   *   allows defining nested properties, such as a compound field property, or
   *   a field on a referenced Entity or Field Collection. Determining how these
   *   should be rendered is the job of the supporting Normalizer.
   *
   * @return array|object|\Symfony\Component\Serializer\Normalizer\scalar
   */
  protected function EntityValueWrapper(\EntityMetadataWrapper $wrapper, $property_trail) {
    $serializer = new Serializer($this->normalizers, []);
    $context = [
      'wrapper' => $this->wrapper,
      'config' => $this->config,
      'property_trail' => $property_trail,
    ];

    // In addition to passing context to the standard $context param, we also
    // pass to the Serializer::normalize() $format param, so the
    // Serializer::supportsNormalization() method can better determine whether
    // the normalizer class should be chosen by Serializer::getNormalizer()
    // (sadly, it does not have the luxury of receiving the $context param).
    // Note that in our implementation of Serializer here, we would normally
    // pass NULL to the $format param anyway, so this won't hurt anything,
    // except possible confusion to future developers - hence this note.
    //
    // Alternatively, we may require the YAML config to specify an API property
    // type in a YAML type/value map. That would make the YAML a lot less
    // readable though, and also we would need to differentiate the normal
    // type/value YAML map from intentionally nested YAML maps we now support.
    $format = $context;

    return $serializer->normalize($wrapper, $format, $context);
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
  protected static function serialize($data) {
    $serializer = new Serializer([], [new JsonEncoder()]);
    return $serializer->encode($data, 'json');
  }

}
