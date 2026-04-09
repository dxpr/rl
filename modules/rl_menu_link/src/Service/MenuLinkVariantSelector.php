<?php

namespace Drupal\rl_menu_link\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\rl\Service\CacheManager;
use Drupal\rl\Service\ExperimentManagerInterface;

/**
 * Selects the winning variant for a menu link by its plugin ID.
 */
class MenuLinkVariantSelector {

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
   * Per-request cache, keyed by plugin ID. False = no experiment.
   *
   * @var array<string, array|false>
   */
  protected array $cache = [];

  /**
   * Constructs a MenuLinkVariantSelector.
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
   * Select the winning variant for a menu link plugin ID.
   *
   * @return array|null
   *   Array with experiment_id, arm_id, text, or NULL if no active experiment.
   */
  public function selectForPluginId(string $plugin_id): ?array {
    if (isset($this->cache[$plugin_id])) {
      $cached = $this->cache[$plugin_id];
      return $cached === FALSE ? NULL : $cached;
    }

    $experiment = $this->loadExperimentByPluginId($plugin_id);
    if (!$experiment) {
      $this->cache[$plugin_id] = FALSE;
      return NULL;
    }

    $rl_experiment_id = $experiment->getRlExperimentId();
    $arm_ids = $experiment->getArmIds();
    $scores = $this->experimentManager->getThompsonScores($rl_experiment_id, NULL, $arm_ids);
    arsort($scores);
    $best_arm = (string) key($scores);

    $this->cacheManager->overridePageCacheIfShorter(60);

    $result = [
      'experiment_id' => $rl_experiment_id,
      'arm_id' => $best_arm,
      'text' => $experiment->getArmText($best_arm),
    ];
    $this->cache[$plugin_id] = $result;
    return $result;
  }

  /**
   * Load an enabled experiment for a given plugin ID.
   *
   * @return \Drupal\rl_menu_link\Entity\MenuLinkExperiment|null
   */
  protected function loadExperimentByPluginId(string $plugin_id) {
    $storage = $this->entityTypeManager->getStorage('rl_menu_link_experiment');
    $matches = $storage->loadByProperties([
      'menu_link_plugin_id' => $plugin_id,
      'enabled' => TRUE,
    ]);
    return $matches ? reset($matches) : NULL;
  }

}
