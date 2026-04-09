<?php

namespace Drupal\rl_page_title\Decorator;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\rl\Decorator\ExperimentDecoratorInterface;
use Drupal\rl_page_title\Entity\PageTitleExperiment;

/**
 * Decorates RL reports with human-readable page title experiment data.
 */
class PageTitleDecorator implements ExperimentDecoratorInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Map of RL experiment ID to entity, built lazily on first lookup.
   *
   * @var array<string, \Drupal\rl_page_title\Entity\PageTitleExperiment>|null
   */
  protected ?array $experimentMap = NULL;

  /**
   * Constructs a PageTitleDecorator.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function decorateExperiment(string $experiment_id): ?array {
    $experiment = $this->loadExperiment($experiment_id);
    if (!$experiment) {
      return NULL;
    }
    return [
      '#type' => 'inline_template',
      '#template' => '{{ label }} <small>({{ path }})</small>',
      '#context' => [
        'label' => $experiment->label() ?: $experiment->getPath(),
        'path' => $experiment->getPath(),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function decorateArm(string $experiment_id, string $arm_id): ?array {
    $experiment = $this->loadExperiment($experiment_id);
    if (!$experiment) {
      return NULL;
    }
    $text = $experiment->getArmText($arm_id);
    if ($text === NULL) {
      // v0 = original title, not stored in the experiment entity.
      return [
        '#type' => 'inline_template',
        '#template' => '<em>{{ "(original title)"|t }}</em>',
      ];
    }
    return [
      '#type' => 'inline_template',
      '#template' => '{{ text }}',
      '#context' => ['text' => $text],
    ];
  }

  /**
   * Load an experiment by RL experiment ID using a lazy O(N once)/O(1) map.
   */
  protected function loadExperiment(string $experiment_id): ?PageTitleExperiment {
    if (!str_starts_with($experiment_id, 'rl_page_title-')) {
      return NULL;
    }
    if ($this->experimentMap === NULL) {
      $this->experimentMap = [];
      $storage = $this->entityTypeManager->getStorage('rl_page_title_experiment');
      foreach ($storage->loadMultiple() as $experiment) {
        if ($experiment instanceof PageTitleExperiment) {
          $this->experimentMap[$experiment->getRlExperimentId()] = $experiment;
        }
      }
    }
    return $this->experimentMap[$experiment_id] ?? NULL;
  }

}
