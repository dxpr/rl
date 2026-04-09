<?php

namespace Drupal\rl_page_title\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\rl\Service\CacheManager;
use Drupal\rl\Service\ExperimentManagerInterface;

/**
 * Selects the winning page title variant for the current request.
 *
 * Source-agnostic: works for any page Drupal serves, regardless of whether the
 * title comes from a node, a Views display, or a custom controller. The
 * matching is purely path-based via the resolved internal path.
 */
class TitleVariantSelector {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The RL experiment manager.
   *
   * @var \Drupal\rl\Service\ExperimentManagerInterface
   */
  protected ExperimentManagerInterface $experimentManager;

  /**
   * The RL cache manager.
   *
   * @var \Drupal\rl\Service\CacheManager
   */
  protected CacheManager $cacheManager;

  /**
   * The current path stack.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected CurrentPathStack $currentPath;

  /**
   * The path alias manager.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected AliasManagerInterface $aliasManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * Per-request cache of selection results, keyed by internal path.
   *
   * @var array<string, array|false>
   */
  protected array $resultCache = [];

  /**
   * Constructs a TitleVariantSelector.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ExperimentManagerInterface $experiment_manager,
    CacheManager $cache_manager,
    CurrentPathStack $current_path,
    AliasManagerInterface $alias_manager,
    LanguageManagerInterface $language_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->experimentManager = $experiment_manager;
    $this->cacheManager = $cache_manager;
    $this->currentPath = $current_path;
    $this->aliasManager = $alias_manager;
    $this->languageManager = $language_manager;
  }

  /**
   * Select the winning variant for the current request.
   *
   * @return array|null
   *   Array with keys experiment_id, arm_id, text (NULL means use original),
   *   or NULL if no active experiment matches the current page.
   */
  public function selectForCurrentPage(): ?array {
    $internal_path = $this->getCurrentInternalPath();
    return $this->selectForPath($internal_path);
  }

  /**
   * Select the winning variant for a specific internal path.
   */
  public function selectForPath(string $internal_path): ?array {
    if (isset($this->resultCache[$internal_path])) {
      $cached = $this->resultCache[$internal_path];
      return $cached === FALSE ? NULL : $cached;
    }

    $experiment = $this->loadExperimentByPath($internal_path);
    if (!$experiment) {
      $this->resultCache[$internal_path] = FALSE;
      return NULL;
    }

    $rl_experiment_id = $experiment->getRlExperimentId();
    $arm_ids = $experiment->getArmIds();
    $scores = $this->experimentManager->getThompsonScores($rl_experiment_id, NULL, $arm_ids);
    arsort($scores);
    $best_arm = (string) key($scores);

    // Shorten cache so variants rotate.
    $this->cacheManager->overridePageCacheIfShorter(60);

    $result = [
      'experiment_id' => $rl_experiment_id,
      'arm_id' => $best_arm,
      'text' => $experiment->getArmText($best_arm),
    ];
    $this->resultCache[$internal_path] = $result;
    return $result;
  }

  /**
   * Load an enabled experiment for a given internal path.
   *
   * @return \Drupal\rl_page_title\Entity\PageTitleExperiment|null
   */
  protected function loadExperimentByPath(string $internal_path) {
    $storage = $this->entityTypeManager->getStorage('rl_page_title_experiment');
    $matches = $storage->loadByProperties([
      'path' => $internal_path,
      'enabled' => TRUE,
    ]);
    return $matches ? reset($matches) : NULL;
  }

  /**
   * Get the resolved internal path for the current request.
   *
   * Strips language prefix if present, resolves aliases, normalizes.
   */
  protected function getCurrentInternalPath(): string {
    $raw = $this->currentPath->getPath();
    // CurrentPathStack returns the internal path already (no alias).
    $normalized = '/' . ltrim($raw, '/');
    return rtrim($normalized, '/') ?: '/';
  }

}
