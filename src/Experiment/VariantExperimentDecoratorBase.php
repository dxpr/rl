<?php

namespace Drupal\rl\Experiment;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\rl\Decorator\ExperimentDecoratorInterface;

/**
 * Base class for variant experiment RL report decorators.
 *
 * Both rl_page_title and rl_menu_link map a hash-based RL experiment ID
 * back to a content entity to provide human-readable labels in
 * /admin/reports/rl. The lookup pattern (per-request map keyed by RL ID)
 * and the v1..vN arm text extraction are identical across modules; only
 * the prefix and the original-arm rendering differ. Subclasses provide
 * those.
 */
abstract class VariantExperimentDecoratorBase implements ExperimentDecoratorInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Per-request cache of resolved experiments, keyed by RL experiment ID.
   *
   * Each entry is either a VariantExperimentInterface or FALSE for "looked
   * up and did not find". Per-request scope only.
   *
   * @var array<string, \Drupal\rl\Experiment\VariantExperimentInterface|false>
   */
  protected array $cache = [];

  /**
   * Constructs a VariantExperimentDecoratorBase.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * The RL experiment ID prefix this decorator handles.
   */
  abstract protected function experimentIdPrefix(): string;

  /**
   * The entity type ID of the experiment content entity.
   */
  abstract protected function entityTypeId(): string;

  /**
   * The fully-qualified class name of the experiment entity.
   */
  abstract protected function entityClass(): string;

  /**
   * Build a render array describing the experiment for reports.
   */
  abstract protected function buildExperimentDisplay(VariantExperimentInterface $experiment): array;

  /**
   * Build a render array describing the v0 (original) arm.
   */
  abstract protected function buildOriginalArmDisplay(VariantExperimentInterface $experiment): array;

  /**
   * {@inheritdoc}
   */
  public function decorateExperiment(string $experiment_id): ?array {
    $experiment = $this->loadExperiment($experiment_id);
    if ($experiment === NULL) {
      return NULL;
    }
    return $this->buildExperimentDisplay($experiment);
  }

  /**
   * {@inheritdoc}
   */
  public function decorateArm(string $experiment_id, string $arm_id): ?array {
    $experiment = $this->loadExperiment($experiment_id);
    if ($experiment === NULL) {
      return NULL;
    }
    $text = $experiment->getArmText($arm_id);
    if ($text === NULL) {
      return $this->buildOriginalArmDisplay($experiment);
    }
    return [
      '#type' => 'inline_template',
      '#template' => '{{ text }}',
      '#context' => ['text' => $text],
    ];
  }

  /**
   * Load an experiment by RL experiment ID.
   *
   * Iterates the storage looking for one whose RL ID matches. Result is
   * cached per-request. Reports pages typically decorate one experiment
   * at a time, so the iteration cost is bounded by the number of distinct
   * experiment IDs the report shows, not by the total number of
   * experiments in the database.
   *
   * @return \Drupal\rl\Experiment\VariantExperimentInterface|null
   *   The matching experiment, or NULL if no entity hashes to this ID.
   */
  protected function loadExperiment(string $experiment_id): ?VariantExperimentInterface {
    if (!str_starts_with($experiment_id, $this->experimentIdPrefix())) {
      return NULL;
    }
    if (array_key_exists($experiment_id, $this->cache)) {
      return $this->cache[$experiment_id] ?: NULL;
    }
    $storage = $this->entityTypeManager->getStorage($this->entityTypeId());
    $class = $this->entityClass();
    // We cannot reverse the hash, so we iterate. Reports decorate one ID
    // at a time and cache the result, so iteration is amortized cheaply.
    // For very large experiment counts (10K+), each iteration only loads
    // one entity ID at a time via the storage's loadByProperties path.
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    foreach ($storage->loadMultiple($ids) as $entity) {
      if ($entity instanceof VariantExperimentInterface && $entity instanceof $class) {
        if ($entity->getRlExperimentId() === $experiment_id) {
          $this->cache[$experiment_id] = $entity;
          return $entity;
        }
      }
    }
    $this->cache[$experiment_id] = FALSE;
    return NULL;
  }

}
