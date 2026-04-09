<?php

namespace Drupal\rl_page_title;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Url;
use Drupal\rl\Service\ExperimentManagerInterface;
use Drupal\rl_page_title\Entity\PageTitleExperiment;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * List builder for Page Title experiments.
 */
class PageTitleExperimentListBuilder extends ConfigEntityListBuilder {

  /**
   * The RL experiment manager.
   *
   * @var \Drupal\rl\Service\ExperimentManagerInterface
   */
  protected ExperimentManagerInterface $experimentManager;

  /**
   * Pre-fetched RL stats keyed by RL experiment ID.
   *
   * @var array<string, array{turns:int, leader_arm:string|null, leader_score:float}>
   */
  protected array $statsCache = [];

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
    $header['label'] = $this->t('Label');
    $header['path'] = $this->t('Path');
    $header['variants'] = $this->t('Variants');
    $header['impressions'] = $this->t('Impressions');
    $header['leader'] = $this->t('Leader');
    $header['score'] = $this->t('Posterior mean');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    // Pre-fetch stats for all experiments to avoid N+1 queries in buildRow.
    $entities = $this->load();
    foreach ($entities as $entity) {
      if (!$entity instanceof PageTitleExperiment) {
        continue;
      }
      $rl_id = $entity->getRlExperimentId();
      $this->statsCache[$rl_id] = $this->computeStats($rl_id, $entity);
    }
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No page title experiments yet. Add one to start A/B testing.');
    return $build;
  }

  /**
   * Compute the impression count and leader for an experiment.
   *
   * @return array
   *   Stats for the experiment with keys: turns (int), leader_arm (string|null),
   *   leader_score (float, the Beta posterior mean of the leading arm).
   */
  protected function computeStats(string $rl_experiment_id, EntityInterface $entity): array {
    $turns = $this->experimentManager->getTotalTurns($rl_experiment_id);
    $leader_arm = NULL;
    $leader_score = 0.0;
    if ($turns > 0) {
      foreach ($this->experimentManager->getAllArmsData($rl_experiment_id) as $arm) {
        $alpha = $arm->rewards + 1;
        $beta = max(1, $arm->turns - $arm->rewards + 1);
        $score = $alpha / ($alpha + $beta);
        if ($score > $leader_score) {
          $leader_score = $score;
          $leader_arm = $arm->arm_id;
        }
      }
    }
    return [
      'turns' => (int) $turns,
      'leader_arm' => $leader_arm,
      'leader_score' => $leader_score,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    if (!$entity instanceof PageTitleExperiment) {
      return parent::buildRow($entity);
    }
    $rl_id = $entity->getRlExperimentId();
    $stats = $this->statsCache[$rl_id] ?? ['turns' => 0, 'leader_arm' => NULL, 'leader_score' => 0.0];

    $row['label'] = $entity->label();
    $row['path'] = $entity->getPath();
    // +1 to include the original (v0).
    $row['variants'] = count($entity->getVariants()) + 1;
    $row['impressions'] = $stats['turns'];

    if ($stats['turns'] > 0 && $stats['leader_arm']) {
      $arm_text = $entity->getArmText($stats['leader_arm']);
      $row['leader'] = $arm_text ?? $this->t('(original)');
      $row['score'] = number_format($stats['leader_score'] * 100, 1) . '%';
    }
    else {
      $row['leader'] = $this->t('(no data)');
      $row['score'] = '--';
    }

    $row['status'] = $entity->status() ? $this->t('Active') : $this->t('Disabled');

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);
    if (!$entity instanceof PageTitleExperiment) {
      return $operations;
    }
    $rl_id = $entity->getRlExperimentId();
    // Only expose the Report link once tracking data exists. The reports
    // controller 404s for experiments with no totals row.
    $stats = $this->statsCache[$rl_id] ?? ['turns' => 0];
    if ($stats['turns'] > 0) {
      $operations['report'] = [
        'title' => $this->t('Report'),
        'url' => Url::fromRoute('rl.reports.experiment_detail', [
          'experiment_id' => $rl_id,
        ]),
        'weight' => 30,
      ];
    }
    return $operations;
  }

}
