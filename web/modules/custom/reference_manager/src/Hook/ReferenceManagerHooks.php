<?php
declare(strict_types=1);

namespace Drupal\reference_manager\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeTypeInterface;

class ReferenceManagerHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_preprocess_node_add_list().
   */
  #[Hook('preprocess_node_add_list')]
  public function preprocessNodeAddList(array &$variables): void {
    $node_type_storage = \Drupal::entityTypeManager()->getStorage('node_type');

    $node_types_to_exclude = $node_type_storage->getQuery()
      ->condition('third_party_settings.reference_manager.refman_ignore_node_bundle', 1)
      ->execute();

    foreach ($node_types_to_exclude as $type_to_remove) {
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
  public function menuLinksDiscoveredAlter(array &$links): void {
    $node_type_storage = \Drupal::entityTypeManager()->getStorage('node_type');

    $node_types_to_exclude = $node_type_storage->getQuery()
      ->condition('third_party_settings.reference_manager.refman_ignore_node_bundle', 1)
      ->execute();

    foreach ($node_types_to_exclude as $type_to_remove) {
      if (!isset($links['navigation.content.node_type.' . $type_to_remove])) {
        continue;
      }
      unset($links['navigation.content.node_type.' . $type_to_remove]);
    }
  }


  /**
   * Implements hook_form_FORM_ID_alter() for node_type_form.
   */
  #[Hook('form_node_type_form_alter')]
  public function nodeTypeFormAlter(array &$form, FormStateInterface $form_state): void {
    /** @var NodeTypeInterface $node_type */
    $node_type = $form_state->getFormObject()->getEntity();

    $form['refman_third_party_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Reference Manager settings'),
      '#group' => 'additional_settings',
    ];

    $form['refman_third_party_settings']['refman_ignore_node_bundle'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Ignore this content type'),
      '#description' => $this->t('If checked, this content type will not appear in API results. It will also be hidden from node add listings.'),
      '#default_value' => $node_type->getThirdPartySetting('reference_manager', 'refman_ignore_node_bundle', ''),
    ];

    // Add an entity builder to save the value.
    $form['#entity_builders'][] = [static::class, 'nodeTypeFormEntityBuild'];
  }

  /**
   * Callback for node_type entity form.
   *
   * @see self::form_node_type_form_alter
   */
  public static function nodeTypeFormEntityBuild($entity_type, NodeTypeInterface $type, &$form, FormStateInterface $form_state): void {
    $value = $form_state->getValue('refman_ignore_node_bundle');

    if (!empty($value)) {
      $type->setThirdPartySetting('reference_manager', 'refman_ignore_node_bundle', $value);
    }
    else {
      $type->unsetThirdPartySetting('reference_manager', 'refman_ignore_node_bundle');
    }
  }

}
