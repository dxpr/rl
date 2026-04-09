<?php

namespace Drupal\content_optimizer\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\rl\Service\CacheManager;
use Drupal\rl\Service\ExperimentManagerInterface;

/**
 * Selects the best-performing variant using Thompson Sampling.
 */
class VariantSelector {

  /**
   * The RL experiment manager.
   *
   * @var \Drupal\rl\Service\ExperimentManagerInterface
   */
  protected ExperimentManagerInterface $experimentManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The RL cache manager.
   *
   * @var \Drupal\rl\Service\CacheManager
   */
  protected CacheManager $cacheManager;

  /**
   * Constructs a VariantSelector.
   *
   * @param \Drupal\rl\Service\ExperimentManagerInterface $experiment_manager
   *   The RL experiment manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\rl\Service\CacheManager $cache_manager
   *   The RL cache manager.
   */
  public function __construct(ExperimentManagerInterface $experiment_manager, EntityTypeManagerInterface $entity_type_manager, CacheManager $cache_manager) {
    $this->experimentManager = $experiment_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->cacheManager = $cache_manager;
  }

  /**
   * Select the best variant for an entity field.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param int $entity_id
   *   The entity ID.
   * @param string $field
   *   The field name (default: 'title').
   *
   * @return array|null
   *   Array with keys 'experiment_id', 'arm_id', 'text' (NULL = use original),
   *   'experiment_entity_id', or NULL if no active experiment.
   */
  public function selectVariant(string $entity_type, int $entity_id, string $field = 'title'): ?array {
    $experiments = $this->entityTypeManager
      ->getStorage('content_optimizer_experiment')
      ->loadByProperties([
        'target_entity_type' => $entity_type,
        'target_entity_id' => $entity_id,
        'target_field' => $field,
        'enabled' => TRUE,
      ]);

    if (empty($experiments)) {
      return NULL;
    }

    /** @var \Drupal\content_optimizer\Entity\Experiment $experiment */
    $experiment = reset($experiments);
    $variants = $experiment->getVariants();

    if (empty($variants)) {
      return NULL;
    }

    $experiment_id = $experiment->getRlExperimentId();
    $arm_ids = $experiment->getArmIds();

    $scores = $this->experimentManager->getThompsonScores($experiment_id, NULL, $arm_ids);

    // Pick the highest-scoring arm.
    arsort($scores);
    $best_arm = key($scores);

    // Override page cache TTL so variants rotate.
    $config = \Drupal::config('content_optimizer.settings');
    $cache_ttl = $config->get('cache_ttl') ?? 60;
    $this->cacheManager->overridePageCacheIfShorter($cache_ttl);

    return [
      'experiment_id' => $experiment_id,
      'arm_id' => $best_arm,
      'text' => $experiment->getArmText($best_arm),
      'experiment_entity_id' => $experiment->id(),
    ];
  }

}
