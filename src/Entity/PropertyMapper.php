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
    $file_pattern = join('.', [$this->type, $this->bundle, 'yml']);
    foreach ($paths as $path) {
      $filename = join('/', [$path, $file_pattern]);
      if (file_exists($filename)) {
        // @todo Look deeper at Symfony/Component/Yaml/Yaml::parse bitwise
        //   operators. Quick test of PARSE_OBJECT_FOR_MAP and PARSE_OBJECT
        //   fail. If we need this, it appears that we may have to add some
        //   special string value to match to provide this support, such as
        //   "EMPTY_OBJECT".
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

    $context = [
      'item_type' => $this->config['itemType'],
      'wrapper' => $this->wrapper,
      'config' => $this->config,
    ];

    if (isset($this->config['field_map'])) {
      $this->yamlPropertyMapper($this->config['field_map'], $data, $context);
    }

    return $data;
  }

  /**
   * Iterates over all config keys to find Drupal Entity mapped values.
   *
   * Receives config as array, and iterates over each YAML key (recursively, to
   * handle nested YAML maps).
   *
   * @param array $field_map
   *   See $field_map param of setConfig().
   * @param array $data
   *   The return value of getMessageBody().
   */
  protected function yamlPropertyMapper(array $field_map, array &$data, array &$context) {
    foreach ($field_map as $dest_prop => $source_prop) {
      // Pass through strings as the value if they're not an Entity property
      // recognized either by EntitymetadataWrapper->FIELD.
      $value = $source_prop;

      // Add recursion to handle YAML config field_map values that are
      // associative arrays of field values (example, customFields).
      if (!is_string($source_prop) && is_array($source_prop)) {
        $data[$dest_prop] = [];
        $this->yamlPropertyMapper($source_prop, $data[$dest_prop], $context);
        continue;
      }

      // @todo Support ORing (with "|") before deciding on the $source_prop.

      // Support dot notation for a trail of nested source property definitions.
      $context['source_prop_trail'] = [];
      if (strpos($source_prop, '.') !== FALSE) {
        $context['source_prop_trail'] = explode('.', $source_prop);
        $source_prop = $context['source_prop_trail'][0];
      }

      $context['dest_prop'] = $dest_prop;
      $context['source_prop'] = $source_prop;

      // Now that we have the Drupal Entity field/property, get each field
      // item(s) value.
      // @todo Check if the dot-notated source property trail item is a
      //   reference, or a column to be retreived with ->get().
      if (isset($this->wrapper->$source_prop)) {
        $value = $this->EntityMetadataWrapper($this->wrapper->$source_prop, $context);
      }

      $data[$dest_prop] = $value;
    }
  }

  /**
   * @param \EntityMetadataWrapper $wrapper
   * @param array $context
   * @return array|object|string|\Symfony\Component\Serializer\Normalizer\scalar
   */
  protected function EntityMetadataWrapper(\EntityMetadataWrapper $wrapper, array $context) {
    $value = '';
    if (is_a($wrapper, 'EntityListWrapper')) {
      $value = $this->EntityListWrapper($wrapper, $context);
    }
    // Certain field types are of EntityStructureWrapper wrapper type, such as
    // some compound fields (where each of field "column" items are of type
    // EntityValueWrapper).
    elseif (is_a($wrapper, 'EntityValueWrapper')
      || is_a($wrapper, 'EntityStructureWrapper')) {
      $value = $this->EntityValueWrapper($wrapper, $context);
    }
    return $value;
  }

  /**
   * @param \EntityListWrapper $wrapper
   * @param array $context
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
  protected function EntityListWrapper(\EntityListWrapper $wrapper, array $context) {
    $value = [];
    foreach ($wrapper->getIterator() as $delta => $itemWrapper) {
      // If the item wrapper doesn't extend one of these two types, our base
      // AbstractEntityValueWrapperNormalizer class won't support it.
      // Note that \EntityDrupalWrapper extends \EntityStructureWrapper, and
      // this covers all classes that extend \EntityMetadataWrapper.
      if ($itemWrapper instanceof \EntityValueWrapper) {
        $value[$delta] = $this->EntityValueWrapper($itemWrapper, $context);
      }
      elseif ($itemWrapper instanceof \EntityStructureWrapper) {
        $value[$delta] = $this->EntityStructureWrapper($itemWrapper, $context);
      }
    }
    return $value;
  }

  /**
   * @param \EntityMetadataWrapper $wrapper
   * @param array $context
   * @return array|object|\Symfony\Component\Serializer\Normalizer\scalar
   */
  protected function EntityValueWrapper(\EntityMetadataWrapper $wrapper, array $context) {
    return $this->normalize($wrapper, $context);
  }

  /**
   * @param \EntityStructureWrapper $wrapper
   * @param array $context
   * @return array|object|\Symfony\Component\Serializer\Normalizer\scalar
   */
  protected function EntityStructureWrapper(\EntityStructureWrapper $wrapper, array $context) {
    return $this->normalize($wrapper, $context);
  }

  /**
   * @param \EntityValueWrapper $wrapper
   *   Note this may not always be an instance of EntityValueWrapper. In some
   *   cases such as a taxonomy_term or entity reference, the value is another
   *   instance of EntityDrupalWrapper. Certain fields are also instances of
   *   EntityStructureWrapper. So let's type hint the parent abstract
   *   EntityMetadataWrapper.
   * @param array $context
   *   A context array, containing:
   *   - item_type: The YAML config "item_type" value.
   *   - wrapper: The CRUD-triggering EntityMetadataWrapper.
   *   - config: Parsed YAML config matching the CRUD-triggering Entity.
   *   - source_prop_trail: An array of Drupal Entity mapped value properties
   *     defined in YAML. This allows defining nested properties, such as a
   *     compound field property, or a field on a referenced Entity or Field
   *     Collection. Determining how these should be rendered is the job of the
   *     supporting Normalizer.
   *
   * @return array|object|\Symfony\Component\Serializer\Normalizer\scalar
   */
  protected function normalize(\EntityMetadataWrapper $wrapper, array $context) {
    $serializer = new Serializer($this->normalizers, []);

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
