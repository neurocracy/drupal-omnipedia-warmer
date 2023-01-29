<?php

declare(strict_types=1);

namespace Drupal\omnipedia_warmer\Service;

/**
 * The Omnipedia warmer info service interface.
 */
interface WarmerInfoInterface {

  /**
   * Get IDs to warm.
   *
   * @return array
   *   An array of data indicating what nodes and with which roles that need to
   *   be warmed. Top level keys will be strings in the format of:
   *
   *   'nid:role1,role2'
   *
   *   Where 'nid' is a node ID and the combination of role machine names
   *   indicate the roles this specific node should be rendered for. Note that
   *   multiple instances of the nid will usually be found, one for each
   *   combination of roles.
   *
   *   Each of these top level keys will contain an array with the following
   *   keys:
   *
   *   - 'nid': The node ID to load and render.
   *
   *   - 'roles': An array containing one or more role machine names to render
   *     for.
   *
   *   - 'hash': The generated permissions hash for this combination of user
   *     roles.
   *
   * @see \Drupal\warmer\Plugin\WarmerInterface::buildIdsBatch()
   */
  public function getIdsToWarm(): array;

}
