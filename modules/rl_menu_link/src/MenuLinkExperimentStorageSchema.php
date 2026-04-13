<?php

namespace Drupal\rl_menu_link;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Storage schema for the rl_menu_link_experiment content entity.
 *
 * Mirrors the Redirect module's RedirectStorageSchema: a UNIQUE index on
 * the computed lookup_hash column gives O(1) duplicate detection and
 * runtime selection, plus a secondary (menu_link_plugin_id, langcode)
 * index so admin list queries and entity_predelete cleanup (which query
 * by plugin_id only) stay indexed.
 */
class MenuLinkExperimentStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $table = $entity_type->getBaseTable() ?: 'rl_menu_link_experiment';

    $schema[$table]['unique keys'] += [
      'rl_menu_link_lookup_hash' => ['lookup_hash'],
    ];
    $schema[$table]['indexes'] += [
      'rl_menu_link_plugin_langcode' => [['menu_link_plugin_id', 191], 'langcode'],
    ];

    return $schema;
  }

}
