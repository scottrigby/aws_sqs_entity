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
   * Recursively iterates over each destination (external) property to find
   * mapped source (Drupal Entity) property values.
   *
   * @param array $field_map
   *   See $field_map param of setConfig().
   * @param array $data
   *   The return value of getMessageBody().
   * @param array $context
   *   By reference. Associative array containing:
   *   - item_type: Destination item type corresponding to the triggering
   *     Entity.
   *   - wrapper: \EntityDrupalWrapper for triggering entity.
   *   - config: YAML config for triggering Entity.
   *   - source_prop_trail: An array of source property definitions temporarily
   *     assigned by reference for the current destination property, for the
   *     purpose of setting $context['final_source_prop_value'].
   */
  protected function yamlPropertyMapper(array $field_map, array &$data, array &$context) {
    foreach ($field_map as $dest_prop => $source_prop) {
      // Pass through strings as the value if they're not an Entity property
      // recognized by EMD->FIELD.
      $value = $source_prop;

      // Add recursion to handle nested destination properties in YAML config.
      // Relevant example YAML block from
      // hook_aws_sqs_entity_property_mapper_config_paths():
      // @code
      // field_map:
      //   nestedMap:
      //     nested1: uuid
      //     nested2: vid
      //     nested3: title
      // @endcode
      if (!is_string($source_prop) && is_array($source_prop)) {
        $data[$dest_prop] = [];
        $this->yamlPropertyMapper($source_prop, $data[$dest_prop], $context);
        continue;
      }

      // Add current destination property to context for normalizers.
      $context['final_dest_prop'] = $dest_prop;

      // If the Source property is a dot-concatenated string, this syntax
      // signifies a trail of properties to discover within the source
      // \EntityMetadataWrapper object created for the triggering Entity. In
      // either case $context['source_prop_trail'] is set to an array of these
      // properties. Example YAML block:
      // @code
      // field_map:
      //   destProperty: field_my_field_collection.field_my_entityreference.uuid
      // @endcode
      $context['source_prop_trail'] = explode('.', $source_prop);

      // Check if there is a valid normalized value, so that - if there is not -
      // plain strings (above) will pass through to the final $data array.
      // @todo Support ORing (with "|") before setting final_source_prop_value.
      $this->marshalWrapperClass($this->wrapper, $context);
      if (isset($context['final_source_prop_value'])) {
        $value = $context['final_source_prop_value'];
      }

      $data[$dest_prop] = $value;
    }
  }

  /**
   * This is Magic: https://giphy.com/gifs/shia-labeouf-12NUbkX6p4xOO4
   *
   * Marshals to the correct method matching the current \EntityMetadataWrapper
   * extension class, which follows the source property trail to the method for
   * the final wrapper class, which sets the final source property value in the
   * context array passed by reference.
   *
   * Note that this magic marshalling is not permissive of malformed source
   * property strings. Those must map to an actual Drupal Entity structure, or
   * an Exception will likely be thrown by the Entity API (just as it is when
   * hard-coding EMD calls).
   *
   * Also note we use this strategy because \EntityMetadataWrapper (EMD) is the
   * best tool we have for systematically finding values from Drupal Entities,
   * prior to Typed Data in Drupal 8.
   *
   * Calling this function from the triggering Entity initiates a recursive
   * search through the \EntityMetadataWrapper object's lazy-loaded children,
   * each of which may be a single object – or array of objects – of the types
   * controlled by entity_metadata_wrapper():
   * - \EntityListWrapper: Contains an array of one the types below.
   * - \EntityStructureWrapper:
   * - \EntityDrupalWrapper: An extension of \EntityStructureWrapper
   * - \EntityValueWrapper:
   * @see entity_metadata_wrapper()
   *
   * Along the way, each EMW-class-named method will check if it's the last
   * wrapper in the source property trail (the final wrapper will correspond to
   * the last concatenated string source property, so if
   * $context['source_prop_trail'] is empty, it's the last wrapper). If not the
   * last wrapper, it will hand off to the next child again via this
   * MarshallWrapperClass method. The final wrapper will set
   * $context['final_source_prop_value'] with the wrapper's normalized value.
   *
   * The exception is EntityListWrapper, which must store the
   * $context['final_source_prop_value'] value from each of it's children, and
   * at the end reset that to the stored array to pass up to it's calling class.
   * Note that in this case, the $context['final_dest_prop'] will be temporarily
   * useful only to the normalizer called from each EntityListWrapper item.
   *
   * @param \EntityMetadataWrapper $wrapper
   * @param array $context
   *   By reference. Associative array containing keys from yamlPropertyMapper
   *   $config param, but additionally:
   *   - final_source_prop_value: A single or array of normalized values for the
   *     final source property in $config['source_prop_trail'] temporarily
   *     assigned by reference for the current destination property.
   *
   * Here are the PropertyMapper methods named to match each valid extension of
   * \EntityMetadataWrapper:
   * @see \EntityListWrapper
   * @see EntityListWrapper()
   * @see \EntityStructureWrapper
   * @see EntityStructureWrapper()
   * @see \EntityDrupalWrapper
   * @see EntityDrupalWrapper()
   * @see \EntityValueWrapper
   * @see EntityValueWrapper()
   *
   * @todo We may also want to use EMW for sitewide properties in YAML config:
   * @see entity_metadata_site_wrapper()
   * @see entity_metadata_system_entity_property_info()
   */
  protected function marshalWrapperClass(\EntityMetadataWrapper $wrapper, array &$context) {
    $class = get_class($wrapper);
    if (is_callable([$this, $class])) {
      $this->{$class}($wrapper, $context);
    }
  }

  /**
   * @param \EntityListWrapper $wrapper
   * @param array $context
   */
  protected function EntityListWrapper(\EntityListWrapper $wrapper, array &$context) {
    $value = [];
    foreach ($wrapper->getIterator() as $delta => $itemWrapper) {
      $this->marshalWrapperClass($wrapper, $context);
      if (isset($context['final_source_prop_value'])) {
        $value[] = $context['final_source_prop_value'];
        unset($context['final_source_prop_value']);
      }
    }
    $context['final_source_prop_value'] = $value;
  }

  /**
   * @param \EntityMetadataWrapper $wrapper
   * @param array $context
   */
  protected function EntityValueWrapper(\EntityMetadataWrapper $wrapper, array &$context) {
    $this->marshalOrSetFinalSourcePropValue($wrapper, $context);
  }

  /**
   * @param \EntityStructureWrapper $wrapper
   * @param array $context
   */
  protected function EntityStructureWrapper(\EntityStructureWrapper $wrapper, array &$context) {
    $this->marshalOrSetFinalSourcePropValue($wrapper, $context);
  }

  /**
   * @param \EntityDrupalWrapper $wrapper
   * @param array $context
   */
  protected function EntityDrupalWrapper(\EntityDrupalWrapper $wrapper, array &$context) {
    $this->marshalOrSetFinalSourcePropValue($wrapper, $context);
  }

  /**
   * @param \EntityMetadataWrapper $wrapper
   * @param array $context
   */
  protected function marshalOrSetFinalSourcePropValue(\EntityMetadataWrapper $wrapper, array &$context) {
    if (!empty($context['source_prop_trail'])) {
      $next_source_prop = array_shift($context['source_prop_trail']);

      if (isset($wrapper->{$next_source_prop})) {
        $this->marshalWrapperClass($wrapper->{$next_source_prop}, $context);
      }
    }
    else {
      $context['final_source_prop_value'] = $this->normalize($wrapper, $context);
    }
  }

  /**
   * @param \EntityMetadataWrapper $wrapper
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
   * @see \Symfony\Component\Serializer\Serializer::normalize()
   * @see \Drupal\aws_sqs_entity\Normalizer\AbstractEntityValueWrapperNormalizer::supportsNormalization()
   *
   * @return array|object|\Symfony\Component\Serializer\Normalizer\scalar
   */
  protected function normalize(\EntityMetadataWrapper $wrapper, array &$context) {
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
