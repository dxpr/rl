<?php

namespace Drupal\rl\Experiment;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Url;
use Drupal\rl\Service\ExperimentManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base list builder for variant experiments with live RL stats.
 *
 * Consumer subclasses define the entity class plus a column for the target
 * (path, plugin id, etc.) and inherit:
 * - The header structure: Label | Target | Variants | Impressions | Leader |
 *   Posterior mean | Status | Operations.
 * - The pre-fetch-then-render pattern that avoids N+1 queries on stats lookup.
 * - The lazy fallback in getDefaultOperations() so the Report link still
 *   works when the list builder is invoked outside a normal render() flow.
 * - The Report operation gating on `total_turns > 0` to avoid 404s.
 */
abstract class VariantExperimentListBuilderBase extends ConfigEntityListBuilder {

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
   * The fully-qualified class name of the experiment entity.
   */
  abstract protected function entityClass(): string;

  /**
   * Header label for the per-module target column.
   */
  abstract protected function targetColumnLabel(): string;

  /**
   * Empty-list message shown when no experiments exist yet.
   */
  abstract protected function emptyMessage(): string;

  /**
   * Render the per-row target value for the entity.
   */
  abstract protected function targetColumnValue(EntityInterface $entity): string;

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Label');
    $header['target'] = $this->targetColumnLabel();
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
    foreach ($this->load() as $entity) {
      if ($entity instanceof VariantExperimentInterface) {
        $rl_id = $entity->getRlExperimentId();
        $this->statsCache[$rl_id] = $this->computeStats($rl_id);
      }
    }
    $build = parent::render();
    $build['table']['#empty'] = $this->emptyMessage();
    return $build;
  }

  /**
   * Compute stats for a single experiment (one DB lookup per call).
   *
   * @return array
   *   Stats with keys: turns (int), leader_arm (string|null),
   *   leader_score (float, the Beta posterior mean of the leading arm).
   */
  protected function computeStats(string $rl_experiment_id): array {
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
    if (!$entity instanceof VariantExperimentInterface) {
      return parent::buildRow($entity);
    }
    $rl_id = $entity->getRlExperimentId();
    if (!isset($this->statsCache[$rl_id])) {
      $this->statsCache[$rl_id] = $this->computeStats($rl_id);
    }
    $stats = $this->statsCache[$rl_id];

    $row['label'] = $entity->label();
    $row['target'] = $this->targetColumnValue($entity);
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
    if (!$entity instanceof VariantExperimentInterface) {
      return $operations;
    }
    $rl_id = $entity->getRlExperimentId();
    // Lazy fetch in case this method is invoked outside the normal render()
    // pipeline (custom dashboards, Drush, tests).
    if (!isset($this->statsCache[$rl_id])) {
      $this->statsCache[$rl_id] = $this->computeStats($rl_id);
    }
    $stats = $this->statsCache[$rl_id];
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
