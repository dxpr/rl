<?php

namespace Drupal\rl\Experiment;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\rl\Decorator\ExperimentDecoratorInterface;

/**
 * Base class for variant experiment RL report decorators.
 *
 * Both rl_page_title and rl_menu_link map a hash-based RL experiment ID
 * back to a config entity to provide human-readable labels in /admin/reports/rl.
 * The lookup pattern (lazy O(N once)/O(1) map) and the v1..vN arm text
 * extraction are identical; only the prefix and the original-arm rendering
 * differ. Subclasses provide those.
 */
abstract class VariantExperimentDecoratorBase implements ExperimentDecoratorInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Map of RL experiment ID to entity, built lazily on first lookup.
   *
   * @var array<string, \Drupal\rl\Experiment\VariantExperimentInterface>|null
   */
  protected ?array $experimentMap = NULL;

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
   * The entity type ID of the experiment config entity.
   */
  abstract protected function entityTypeId(): string;

  /**
   * The fully-qualified class name of the experiment entity.
   */
  abstract protected function entityClass(): string;

  /**
   * Build a render array describing the experiment for reports.
   *
   * @param \Drupal\rl\Experiment\VariantExperimentInterface $experiment
   *   The experiment entity (also an instance of entityClass()).
   *
   * @return array
   *   A render array.
   */
  abstract protected function buildExperimentDisplay(VariantExperimentInterface $experiment): array;

  /**
   * Build a render array describing the v0 (original) arm.
   *
   * @param \Drupal\rl\Experiment\VariantExperimentInterface $experiment
   *   The experiment entity (also an instance of entityClass()).
   *
   * @return array
   *   A render array.
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
    // The trait's getArmText() returns NULL for v0 (original, not stored).
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
   * Load an experiment by RL experiment ID using a lazy O(N once)/O(1) map.
   *
   * @return \Drupal\rl\Experiment\VariantExperimentInterface|null
   *   The experiment, or NULL if no match.
   */
  protected function loadExperiment(string $experiment_id): ?VariantExperimentInterface {
    if (!str_starts_with($experiment_id, $this->experimentIdPrefix())) {
      return NULL;
    }
    if ($this->experimentMap === NULL) {
      $this->experimentMap = [];
      $storage = $this->entityTypeManager->getStorage($this->entityTypeId());
      $class = $this->entityClass();
      foreach ($storage->loadMultiple() as $experiment) {
        if ($experiment instanceof VariantExperimentInterface && $experiment instanceof $class) {
          $this->experimentMap[$experiment->getRlExperimentId()] = $experiment;
        }
      }
    }
    return $this->experimentMap[$experiment_id] ?? NULL;
  }

}
