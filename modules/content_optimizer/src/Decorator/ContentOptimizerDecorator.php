<?php

namespace Drupal\content_optimizer\Decorator;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\rl\Decorator\ExperimentDecoratorInterface;

/**
 * Decorates RL reports with human-readable variant text.
 */
class ContentOptimizerDecorator implements ExperimentDecoratorInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a ContentOptimizerDecorator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function decorateExperiment(string $experiment_id): ?array {
    if (!str_starts_with($experiment_id, 'content_optimizer-')) {
      return NULL;
    }

    // Load the experiment entity and return a label.
    $experiment = $this->findExperimentByRlId($experiment_id);
    if (!$experiment) {
      return NULL;
    }

    return ['#markup' => Html::escape($experiment->getExperimentLabel())];
  }

  /**
   * {@inheritdoc}
   */
  public function decorateArm(string $experiment_id, string $arm_id): ?array {
    if (!str_starts_with($experiment_id, 'content_optimizer-')) {
      return NULL;
    }

    $experiment = $this->findExperimentByRlId($experiment_id);
    if (!$experiment) {
      return NULL;
    }

    if ($arm_id === 'v0') {
      // Load the original entity field value.
      $entity_id = $experiment->get('target_entity_id')->value;
      $entity_type = $experiment->get('target_entity_type')->value;
      $field = $experiment->get('target_field')->value;
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
      if ($entity) {
        $label = $entity->label();
        return ['#markup' => Html::escape($label) . ' <small>(original)</small>'];
      }
      return ['#markup' => '<em>original</em>'];
    }

    $text = $experiment->getArmText($arm_id);
    if ($text !== NULL) {
      return ['#markup' => Html::escape($text)];
    }

    return NULL;
  }

  /**
   * Find a content_optimizer_experiment entity by its RL experiment ID.
   *
   * @param string $rl_experiment_id
   *   The RL experiment ID.
   *
   * @return \Drupal\content_optimizer\Entity\Experiment|null
   *   The experiment entity or NULL.
   */
  protected function findExperimentByRlId(string $rl_experiment_id) {
    // Parse experiment ID: content_optimizer-{type}-{id}-{field}
    $parts = explode('-', $rl_experiment_id, 4);
    if (count($parts) < 4 || $parts[0] !== 'content_optimizer') {
      return NULL;
    }

    $target_type = $parts[1];

    if ($target_type === 'path') {
      // Path-based experiments: look up by path.
      return NULL;
    }

    $target_id = $parts[2];
    $target_field = $parts[3];

    $experiments = $this->entityTypeManager
      ->getStorage('content_optimizer_experiment')
      ->loadByProperties([
        'target_entity_type' => $target_type,
        'target_entity_id' => $target_id,
        'target_field' => $target_field,
      ]);

    return $experiments ? reset($experiments) : NULL;
  }

}
