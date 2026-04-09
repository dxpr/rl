<?php

namespace Drupal\rl_page_title\Decorator;

use Drupal\Component\Utility\Html;
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
   * Cache of experiments keyed by RL experiment ID.
   *
   * @var array<string, \Drupal\rl_page_title\Entity\PageTitleExperiment|false>
   */
  protected array $cache = [];

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
      '#markup' => Html::escape($experiment->label() ?: $experiment->getPath()) . ' <small>(' . Html::escape($experiment->getPath()) . ')</small>',
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
      return ['#markup' => '<em>' . Html::escape((string) t('(original title)')) . '</em>'];
    }
    return ['#markup' => Html::escape($text)];
  }

  /**
   * Load an experiment by RL experiment ID.
   */
  protected function loadExperiment(string $experiment_id): ?PageTitleExperiment {
    if (!str_starts_with($experiment_id, 'rl_page_title-')) {
      return NULL;
    }
    if (array_key_exists($experiment_id, $this->cache)) {
      return $this->cache[$experiment_id] ?: NULL;
    }
    // Hash-based IDs cannot be reversed; load all experiments and match.
    // This is acceptable because experiment count is low (config entities).
    $storage = $this->entityTypeManager->getStorage('rl_page_title_experiment');
    /** @var \Drupal\rl_page_title\Entity\PageTitleExperiment $experiment */
    foreach ($storage->loadMultiple() as $experiment) {
      if ($experiment->getRlExperimentId() === $experiment_id) {
        $this->cache[$experiment_id] = $experiment;
        return $experiment;
      }
    }
    $this->cache[$experiment_id] = FALSE;
    return NULL;
  }

}
