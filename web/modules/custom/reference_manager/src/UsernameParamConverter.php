<?php

namespace Drupal\reference_manager;

use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Symfony\Component\Routing\Route;

/**
 * Parameter converter for upcasting usernames to full user objects.
 */
class UsernameParamConverter implements ParamConverterInterface {

  /**
   * Constructs a new UsernameParamConverter.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager
  ) {}

  /**
   * {@inheritdoc}
   */
  public function convert(mixed $value, mixed $definition, $name, array $defaults) {
    if (empty($value)) {
      return NULL;
    }

    $users = $this->entityTypeManager->getStorage('user')->loadByProperties([
      'name' => $value,
    ]);

    return $users ? reset($users) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route) {
    return (!empty($definition['type']) && $definition['type'] === 'user_by_username');
  }

}
