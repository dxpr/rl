<?php

namespace Drupal\content_optimizer;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Url;
use Drupal\rl\Service\ExperimentManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * List builder for Content Optimizer Experiment entities.
 */
class ExperimentListBuilder extends EntityListBuilder {

  /**
   * The RL experiment manager.
   *
   * @var \Drupal\rl\Service\ExperimentManagerInterface
   */
  protected ExperimentManagerInterface $experimentManager;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->experimentManager = $container->get('rl.experiment_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['target'] = $this->t('Target');
    $header['field'] = $this->t('Field');
    $header['variants'] = $this->t('Variants');
    $header['impressions'] = $this->t('Impressions');
    $header['leader'] = $this->t('Leader');
    $header['score'] = $this->t('Conv. Score');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\content_optimizer\Entity\Experiment $entity */
    $row['target'] = $entity->getExperimentLabel();
    $row['field'] = $entity->get('target_field')->value;

    $variants = $entity->getVariants();
    // +1 for original.
    $row['variants'] = count($variants) + 1;

    $rl_experiment_id = $entity->getRlExperimentId();
    $arms_data = $this->experimentManager->getAllArmsData($rl_experiment_id);
    $total_turns = $this->experimentManager->getTotalTurns($rl_experiment_id);

    $row['impressions'] = $total_turns;

    // Find the leading arm.
    if (!empty($arms_data)) {
      $best_arm = NULL;
      $best_score = -1;
      foreach ($arms_data as $arm) {
        $alpha = $arm->rewards + 1;
        $beta = max(1, $arm->turns - $arm->rewards + 1);
        $score = $alpha / ($alpha + $beta);
        if ($score > $best_score) {
          $best_score = $score;
          $best_arm = $arm;
        }
      }
      if ($best_arm && $total_turns > 0) {
        $arm_text = $entity->getArmText($best_arm->arm_id);
        $row['leader'] = $arm_text ?? $this->t('(original)');
        $row['score'] = number_format($best_score * 100, 1) . '%';
      }
      else {
        $row['leader'] = $this->t('(no data)');
        $row['score'] = '--';
      }
    }
    else {
      $row['leader'] = $this->t('(no data)');
      $row['score'] = '--';
    }

    $row['status'] = $entity->isPublished() ? $this->t('Active') : $this->t('Disabled');

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);

    /** @var \Drupal\content_optimizer\Entity\Experiment $entity */
    $rl_experiment_id = $entity->getRlExperimentId();
    $operations['view_report'] = [
      'title' => $this->t('Report'),
      'url' => Url::fromRoute('rl.reports.experiment_detail', [
        'experiment_id' => $rl_experiment_id,
      ]),
      'weight' => 20,
    ];

    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();
    $build['#empty'] = $this->t('No experiments yet. Create one to start A/B testing content.');
    return $build;
  }

}
