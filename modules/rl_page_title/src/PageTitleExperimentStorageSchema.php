<?php

namespace Drupal\rl_page_title;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Storage schema for the rl_page_title_experiment content entity.
 *
 * Mirrors the Redirect module's RedirectStorageSchema: a UNIQUE index on
 * the computed lookup_hash column gives O(1) duplicate detection and runtime
 * selection, plus a secondary (path, langcode) index so admin list queries
 * and entity_predelete cleanup (which query by path only) stay indexed.
 *
 * The 191-character prefix on the path column is the MySQL utf8mb4 index
 * limit (767 bytes / 4 bytes per char). Long paths still store fully in
 * the base column; only the index is truncated.
 */
class PageTitleExperimentStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $table = $entity_type->getBaseTable() ?: 'rl_page_title_experiment';

    $schema[$table]['unique keys'] += [
      'rl_page_title_lookup_hash' => ['lookup_hash'],
    ];
    $schema[$table]['indexes'] += [
      'rl_page_title_path_langcode' => [['path', 191], 'langcode'],
    ];

    return $schema;
  }

}
