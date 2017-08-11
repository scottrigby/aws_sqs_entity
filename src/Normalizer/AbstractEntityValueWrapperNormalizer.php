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
   * @param array $format:
   *   A copy of the $context param passed to Serializer::normalize(). See
   *   reasoning in PropertyMapper::EntityValueWrapper(). Contains:
   *   - wrapper: EntityMetadataWrapper for the CRUD-triggering Entity.
   *   - config: YAML config for the CRUD-triggering Entity.
   *   - property_trail: Trail of nested properties declared in the YAML config
   *     by optional dot-notation.
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
   * @see \Drupal\aws_sqs_entity\Entity\PropertyMapper::EntityValueWrapper()
   */
  public function supportsNormalization($data, $format = null) {
    return $data instanceof \EntityValueWrapper || $data instanceof \EntityStructureWrapper;
  }

  /**
   * Gets the CRUD-triggering Entity.
   *
   * @param array $context
   *   Either the $context param passed to Serializer::normalize(), or the
   *   $format param passed to Serializer::supportsNormalization() by
   *   PropertyMapper::EntityValueWrapper().
   * @return \EntityDrupalWrapper
   *
   * @see supportsNormalization()
   */
  protected static function getParent($context) {
    return $context['wrapper'];
  }

  /**
   * @param array $context
   * @return string
   */
  protected static function getParentEntityType($context) {
    return self::getParent($context)->type();
  }

  /**
   * @param array $context
   * @return string
   */
  protected static function getParentBundle($context) {
    return self::getParent($context)->getBundle();
  }

  /**
   * @param \EntityMetadataWrapper $data
   * @return string
   */
  protected static function getProperty($data) {
    return $data->info()['name'];
  }

  /**
   * Gets the Drupal field/property type of the first item in the
   * $property_trail - the Drupal data origin for the wrapper passed to this
   * normalizer in the $data array.
   *
   * @param $data
   * @return string|null
   */
  protected static function getSourcePropertyType($data, $context) {
    $fieldMap = field_info_field_map();
    $property = self::getProperty($data);
    $entity_type = self::getParentEntityType($context);
    $bundle = self::getParentBundle($context);

    $property_type = NULL;
    if (isset($fieldMap[$property]['bundles'][$entity_type]) && in_array($bundle, $fieldMap[$property]['bundles'][$entity_type])) {
      $property_type = $fieldMap[$property]['type'];
    }

    return $property_type;
  }

}
