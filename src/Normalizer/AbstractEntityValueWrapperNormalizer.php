<?php

namespace Drupal\aws_sqs_entity\Normalizer;

use \Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class AbstractEntityValueWrapperNormalizer implements NormalizerInterface {

  /**
   * {@inheritdoc}
   *
   * Each normalizer that extends this class should return an actual value, not
   * another EntityValueWrapper (which would render as an empty object when JSON
   * encoded).
   *
   * @param array $context
   *   See $context param of PropertyMapper::serialize.
   *
   * @see \Drupal\aws_sqs_entity\Entity\PropertyMapper::serialize()
   */
  public function normalize($object, $format = null, array $context = array()) {
    // Example unaltered return value from \EntityValueWrapper object.
    return $object->value();
  }

  /**
   * {@inheritdoc}
   *
   * Additionally the $data param contains:
   * - normalizerContext: The $context param passed to Serializer::normalize()
   *   stuffed into the $wrapper param so Serializer::supportsNormalization()
   *   methods can use this context to determine whether the class supports
   *   the field. This contains:
   *   - wrapper: EntityMetadataWrapper for the CRUD-triggering Entity.
   *   - config: YAML config for the CRUD-triggering Entity.
   *   - property_trail: Optional additional trail of nested properties,
   *     declared in the YAML config by dot-notation.
   *   - field_map: Return value of field_info_field_map() for convenience.
   *
   * @see \Drupal\aws_sqs_entity\Entity\PropertyMapper::EntityValueWrapper()
   *
   * We must also support instances of EntityStructureWrapper, or item wrappers
   * for field types such as taxonomy_term or entity references would not be
   * supported by this normalizer, and get stuck in very deeply nested loops
   * of the "instanceof \Traversable" check within Serializer::normalize().
   *
   * @todo Consider renaming this base class, since we now support not only
   *   \EntityValueWrapper but now also \EntityStructureWrapper.
   *
   * @see \Symfony\Component\Serializer\Serializer::normalize()
   */
  public function supportsNormalization($data, $format = null) {
    return $data instanceof \EntityValueWrapper || $data instanceof \EntityStructureWrapper;
  }

  /**
   * Gets the CRUD-triggering Entity.
   *
   * @param $data
   * @return \EntityDrupalWrapper
   */
  protected static function getParent($data) {
    return $data->info()['parent'];
  }

  /**
   * @param $data
   * @return string
   */
  protected static function getParentEntityType($data) {
    return self::getParent($data)->type();
  }

  /**
   * @param $data
   * @return string
   */
  protected static function getParentBundle($data) {
    return self::getParent($data)->getBundle();
  }

  /**
   * @param $data
   * @return string
   */
  protected static function getProperty($data) {
    return $data->info()['name'];
  }

  /**
   * @param $data
   * @return array
   */
  protected static function getFieldMap() {
    return field_info_field_map();
  }

  /**
   * Gets the Drupal field/property type of the first item in the
   * $property_trail - the Drupal data origin for the wrapper passed to this
   * normalizer in the $data array.
   *
   * @param $data
   * @return string|null
   */
  protected static function getPropertyType($data) {
    $fieldMap = self::getFieldMap();
    $property = self::getProperty($data);
    $entity_type = self::getParentEntityType($data);
    $bundle = self::getParentBundle($data);

    $property_type = NULL;
    if (isset($fieldMap[$property]['bundles'][$entity_type]) && in_array($bundle, $fieldMap[$property]['bundles'][$entity_type])) {
      $property_type = $fieldMap[$property]['type'];
    }

    return $property_type;
  }

}
