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
   */
  public function supportsNormalization($data, $format = null) {
    return $data instanceof \EntityValueWrapper;
  }

}
