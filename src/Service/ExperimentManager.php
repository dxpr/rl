<?php

namespace Drupal\rl\Service;

use Drupal\rl\Storage\ExperimentDataStorageInterface;

/**
 * Service for managing reinforcement learning experiments.
 */
class ExperimentManager implements ExperimentManagerInterface {
  /**
   * The experiment data storage.
   *
   * @var \Drupal\rl\Storage\ExperimentDataStorageInterface
   */
  protected $storage;

  /**
   * The Thompson Sampling calculator.
   *
   * @var \Drupal\rl\Service\ThompsonCalculator
   */
  protected $tsCalculator;

  /**
   * Constructs a new ExperimentManager.
   *
   * @param \Drupal\rl\Storage\ExperimentDataStorageInterface $storage
   *   The experiment data storage.
   * @param \Drupal\rl\Service\ThompsonCalculator $ts_calculator
   *   The Thompson Sampling calculator.
   */
  public function __construct(ExperimentDataStorageInterface $storage, ThompsonCalculator $ts_calculator) {
    $this->storage = $storage;
    $this->tsCalculator = $ts_calculator;
  }

  /**
   * {@inheritdoc}
   */
  public function recordTurn($experiment_uuid, $arm_id) {
    $this->storage->recordTurn($experiment_uuid, $arm_id);
  }

  /**
   * {@inheritdoc}
   */
  public function recordTurns($experiment_uuid, array $arm_ids) {
    $this->storage->recordTurns($experiment_uuid, $arm_ids);
  }

  /**
   * {@inheritdoc}
   */
  public function recordReward($experiment_uuid, $arm_id) {
    $this->storage->recordReward($experiment_uuid, $arm_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getArmData($experiment_uuid, $arm_id) {
    return $this->storage->getArmData($experiment_uuid, $arm_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getAllArmsData($experiment_uuid) {
    return $this->storage->getAllArmsData($experiment_uuid);
  }

  /**
   * {@inheritdoc}
   */
  public function getTotalTurns($experiment_uuid) {
    return $this->storage->getTotalTurns($experiment_uuid);
  }

  /**
   * {@inheritdoc}
   */
  public function getThompsonScores($experiment_uuid) {
    return $this->getThompsonScoresWithWindow($experiment_uuid, NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function getThompsonScoresWithWindow($experiment_uuid, $time_window_seconds = NULL) {
    $arms_data = $this->storage->getAllArmsDataWithWindow($experiment_uuid, $time_window_seconds);

    if (empty($arms_data)) {
      return [];
    }

    return $this->tsCalculator->calculateThompsonScores($arms_data);
  }

}
