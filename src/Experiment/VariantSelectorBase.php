<?php

namespace Drupal\rl\Experiment;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\rl\Service\CacheManager;
use Drupal\rl\Service\ExperimentManagerInterface;

/**
 * Base class for "load experiment, score arms, return winner" selectors.
 *
 * Consumer modules build a thin selector that subclasses this base, declares
 * which entity type to load and which property to match on, and that's it.
 * The Thompson scoring + per-request caching + page cache override pattern
 * is identical across consumers and lives here.
 */
abstract class VariantSelectorBase {

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
   * Per-request cache of selection results, keyed by lookup key.
   *
   * Value is either a result array (with experiment_id, arm_id, text) or
   * FALSE if a previous lookup found no match.
   *
   * @var array<string, array|false>
   */
  protected array $resultCache = [];

  /**
   * Constructs a VariantSelectorBase.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ExperimentManagerInterface $experiment_manager,
    CacheManager $cache_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->experimentManager = $experiment_manager;
    $this->cacheManager = $cache_manager;
  }

  /**
   * Page cache TTL applied while an experiment is active on the page.
   *
   * Subclasses may override to expose a different TTL.
   */
  protected function cacheTtl(): int {
    return 60;
  }

  /**
   * The entity type ID of the experiment config entity to load.
   */
  abstract protected function entityTypeId(): string;

  /**
   * The fully-qualified class name of the experiment entity.
   *
   * Used for narrow type assertions on loaded entities.
   */
  abstract protected function entityClass(): string;

  /**
   * The property name on the experiment entity that holds the lookup target.
   *
   * For rl_page_title this is `path`; for rl_menu_link it is
   * `menu_link_plugin_id`.
   */
  abstract protected function targetProperty(): string;

  /**
   * Look up the variant selection for a given target value.
   *
   * @param string $target
   *   The target value (path, plugin ID, etc.) to look up.
   *
   * @return array|null
   *   Array with keys experiment_id, arm_id, text (NULL means original);
   *   or NULL if no enabled experiment matches.
   */
  public function selectForTarget(string $target): ?array {
    if (isset($this->resultCache[$target])) {
      $cached = $this->resultCache[$target];
      return $cached === FALSE ? NULL : $cached;
    }

    $experiment = $this->loadExperimentByTarget($target);
    if ($experiment === NULL) {
      $this->resultCache[$target] = FALSE;
      return NULL;
    }

    $rl_experiment_id = $experiment->getRlExperimentId();
    $arm_ids = $experiment->getArmIds();
    $scores = $this->experimentManager->getThompsonScores($rl_experiment_id, NULL, $arm_ids);
    arsort($scores);
    $best_arm = (string) key($scores);

    // Shorten cache so variants rotate.
    $this->cacheManager->overridePageCacheIfShorter($this->cacheTtl());

    $result = [
      'experiment_id' => $rl_experiment_id,
      'arm_id' => $best_arm,
      'text' => $experiment->getArmText($best_arm),
    ];
    $this->resultCache[$target] = $result;
    return $result;
  }

  /**
   * Load an enabled experiment by target value, returning the typed entity.
   *
   * @return \Drupal\rl\Experiment\VariantExperimentInterface|null
   *   The matching experiment, or NULL if none is enabled for this target.
   */
  protected function loadExperimentByTarget(string $target): ?VariantExperimentInterface {
    $storage = $this->entityTypeManager->getStorage($this->entityTypeId());
    $matches = $storage->loadByProperties([
      $this->targetProperty() => $target,
      'enabled' => TRUE,
    ]);
    if (!$matches) {
      return NULL;
    }
    $entity = reset($matches);
    $class = $this->entityClass();
    if (!($entity instanceof VariantExperimentInterface) || !($entity instanceof $class)) {
      return NULL;
    }
    return $entity;
  }

}
