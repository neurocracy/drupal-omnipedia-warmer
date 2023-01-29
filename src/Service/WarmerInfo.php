<?php

declare(strict_types=1);

namespace Drupal\omnipedia_warmer\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeStorageInterface;
use Drupal\omnipedia_core\Entity\Node;
use Drupal\omnipedia_user\Service\PermissionHashesInterface;
use Drupal\omnipedia_warmer\Service\WarmerInfoInterface;

/**
 * The Omnipedia warmer info service.
 */
class WarmerInfo implements WarmerInfoInterface {

  /**
   * The Drupal node entity storage.
   *
   * @var \Drupal\node\NodeStorageInterface
   */
  protected NodeStorageInterface $nodeStorage;

  /**
   * The Omnipedia permission hashes service.
   *
   * @var \Drupal\omnipedia_user\Service\PermissionHashesInterface
   */
  protected PermissionHashesInterface $permissionHashes;

  /**
   * Service constructor; saves dependencies.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The Drupal entity type manager.
   *
   * @param \Drupal\omnipedia_user\Service\PermissionHashesInterface $permissionHashes
   *   The Omnipedia permission hashes service.
   */
  public function __construct(
    EntityTypeManagerInterface  $entityTypeManager,
    PermissionHashesInterface   $permissionHashes
  ) {

    $this->nodeStorage      = $entityTypeManager->getStorage('node');
    $this->permissionHashes = $permissionHashes;

  }

  /**
   * {@inheritdoc}
   *
   * Note that this does not currently do any caching so it's recommended that
   * you only call this once when populating the queue.
   *
   * Also note that this does not do access checking by design as running via
   * Drush can result in unexpected results being omitted as Drush seems to be
   * seen as an anonymous user by Drupal for the purposes of access checking.
   */
  public function getIdsToWarm(): array {

    /** @var string[] */
    $permissionHashes = $this->permissionHashes->getPermissionHashes();

    // This builds and executes a \Drupal\Core\Entity\Query\QueryInterface to
    // get all available wiki node IDs (nids).
    /** @var array */
    $queryResult = ($this->nodeStorage->getQuery())
      ->condition('type', Node::getWikiNodeType())
      // Disable access checking so that this works as expected when invoked via
      // Drush at the commandline.
      ->accessCheck(false)
      ->execute();

    $ids = [];

    foreach ($queryResult as $revisionId => $nid) {

      foreach ($permissionHashes as $roles => $hash) {

        $ids[$nid . ':' . $roles] = [
          'nid'   => $nid,
          'roles' => \explode(',', $roles),
          'hash'  => $hash,
        ];

      }

    }

    return $ids;

  }

}
