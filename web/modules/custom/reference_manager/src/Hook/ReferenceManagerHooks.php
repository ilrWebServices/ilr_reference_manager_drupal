<?php
declare(strict_types=1);

namespace Drupal\reference_manager\Hook;

use Drupal\Core\Hook\Attribute\Hook;

class ReferenceManagerHooks {

  /**
   * Implements hook_preprocess_node_add_list().
   */
  #[Hook('preprocess_node_add_list')]
  public function preprocessNodeAddList(array &$variables): void {
    $types_to_remove = [
      'refman_schema_organization',
      'refman_schema_person',
      'refman_schema_publication_issue',
      'refman_schema_publication_volume',
    ];

    foreach ($types_to_remove as $type_to_remove) {
      if (!isset($variables['content'][$type_to_remove])) {
        continue;
      }
      unset($variables['content'][$type_to_remove]);
    }
  }

  /**
   * Implements hook_menu_links_discovered_alter().
   */
  #[Hook('menu_links_discovered_alter')]
  public function hook_menu_links_discovered_alter(array &$links): void {
    $types_to_remove = [
      'navigation.content.node_type.refman_schema_organization',
      'navigation.content.node_type.refman_schema_person',
      'navigation.content.node_type.refman_schema_publication_issue',
      'navigation.content.node_type.refman_schema_publication_volume',
    ];

    foreach ($types_to_remove as $type_to_remove) {
      if (!isset($links[$type_to_remove])) {
        continue;
      }
      unset($links[$type_to_remove]);
    }
  }

}
