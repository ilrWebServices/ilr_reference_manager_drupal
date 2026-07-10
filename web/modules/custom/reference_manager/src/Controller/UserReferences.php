<?php

namespace Drupal\reference_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\schemadotorg_jsonld\SchemaDotOrgJsonLdBuilderInterface;
use Drupal\schemadotorg_jsonld\SchemaDotOrgJsonLdManagerInterface;

/**
 * The UserReferences controller.
 */
class UserReferences extends ControllerBase {

  public function __construct(
    protected SchemaDotOrgJsonLdManagerInterface $manager,
    protected SchemaDotOrgJsonLdBuilderInterface $builder
  ) {}

  /**
   * Returns JSON-LD of references for a given user.
   */
  public function content(UserInterface $user): JsonResponse {
    $data = [];
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $node_type_storage = $this->entityTypeManager()->getStorage('node_type');

    $node_types_to_exclude = $node_type_storage->getQuery()
      ->condition('third_party_settings.reference_manager.refman_ignore_node_bundle', 1)
      ->execute();

    $query = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $user->id())
      ->condition('type', $node_types_to_exclude, 'NOT IN')
      ->condition('status', 1);

    $nids = $query->execute();
    $nodes = $node_storage->loadMultiple($nids);
    $bubbleable_metadata = new BubbleableMetadata();

    foreach ($nodes as $node) {
      /** @see \Drupal\schemadotorg_jsonld_endpoint\Controller\SchemaDotOrgJsonLdEndpointController::getEntity() */
      // TODO: Revisit why the above function does the following in $this->renderer->executeInRenderContext().
      $entity_route_match = $this->manager->getEntityRouteMatch($node);

      if ($entity_route_match) {
        $ld_data = $this->builder->build($entity_route_match, $bubbleable_metadata);
      }
      else {
        $ld_data = $this->builder->buildEntity(
          entity: $node,
          bubbleable_metadata: $bubbleable_metadata,
        );
        if ($ld_data) {
          $ld_data = ['@context' => 'https://schema.org'] + $ld_data;
        }
      }

      $data[] = $ld_data;
    }

    return new JsonResponse($data);
  }

}
