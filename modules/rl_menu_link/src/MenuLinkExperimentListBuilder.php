<?php

namespace Drupal\rl_menu_link;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Url;
use Drupal\rl\Service\ExperimentManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * List builder for Menu Link experiments.
 */
class MenuLinkExperimentListBuilder extends ConfigEntityListBuilder {

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
    $header['label'] = $this->t('Label');
    $header['plugin_id'] = $this->t('Menu link plugin ID');
    $header['variants'] = $this->t('Variants');
    $header['impressions'] = $this->t('Impressions');
    $header['leader'] = $this->t('Leader');
    $header['score'] = $this->t('Conv. score');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\rl_menu_link\Entity\MenuLinkExperiment $entity */
    $row['label'] = $entity->label();
    $row['plugin_id'] = $entity->getMenuLinkPluginId();
    $row['variants'] = count($entity->getVariants()) + 1;

    $rl_experiment_id = $entity->getRlExperimentId();
    $total_turns = $this->experimentManager->getTotalTurns($rl_experiment_id);
    $row['impressions'] = $total_turns;

    $arms = $this->experimentManager->getAllArmsData($rl_experiment_id);
    if (!empty($arms) && $total_turns > 0) {
      $best_arm = NULL;
      $best_score = -1;
      foreach ($arms as $arm) {
        $alpha = $arm->rewards + 1;
        $beta = max(1, $arm->turns - $arm->rewards + 1);
        $score = $alpha / ($alpha + $beta);
        if ($score > $best_score) {
          $best_score = $score;
          $best_arm = $arm;
        }
      }
      $arm_text = $entity->getArmText($best_arm->arm_id);
      $row['leader'] = $arm_text ?? $this->t('(original)');
      $row['score'] = number_format($best_score * 100, 1) . '%';
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
    /** @var \Drupal\rl_menu_link\Entity\MenuLinkExperiment $entity */
    $operations['report'] = [
      'title' => $this->t('Report'),
      'url' => Url::fromRoute('rl.reports.experiment_detail', [
        'experiment_id' => $entity->getRlExperimentId(),
      ]),
      'weight' => 30,
    ];
    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No menu link experiments yet. Add one to start A/B testing.');
    return $build;
  }

}
