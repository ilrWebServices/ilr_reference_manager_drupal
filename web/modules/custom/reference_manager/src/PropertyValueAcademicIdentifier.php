<?php

namespace Drupal\reference_manager;

/**
 * A simple class to represent academic identifier property/value pairs.
 */
class PropertyValueAcademicIdentifier {

  public function __construct(
    public string $propertyId,
    public string $value
  ){}

}
