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
   * Returns a renderable array for a test page.
   */
  public function content(UserInterface $user): JsonResponse {
    $data = [];
    $node_storage = $this->entityTypeManager()->getStorage('node');

    $query = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $user->id())
      ->condition('status', 1)
      // TODO: Make a third-party setting on content types to set them as excluded (from here but also listings).
      ->condition('type', [
        'refman_schema_organization',
        'refman_schema_person',
        'refman_schema_publication_issue',
        'refman_schema_publication_volume',
      ], 'NOT IN');

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
