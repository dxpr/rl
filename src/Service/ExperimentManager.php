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
  public function getThompsonScores($experiment_uuid, $time_window_seconds = NULL, array $requested_arms = []) {
    $arms_data = $this->storage->getAllArmsData($experiment_uuid, $time_window_seconds);

    // If specific arms are requested, ensure they all have scores.
    // New arms get initialized with zero stats for maximum exploration.
    if (!empty($requested_arms)) {
      foreach ($requested_arms as $arm_id) {
        if (!isset($arms_data[$arm_id])) {
          // New arm: initialize with zero stats (0 turns, 0 rewards).
          // Thompson sampling will give these high exploration scores.
          $arms_data[$arm_id] = (object) [
            'arm_id' => $arm_id,
            'turns' => 0,
            'rewards' => 0,
          ];
        }
      }
    }

    // Complete cold start: no arms at all.
    if (empty($arms_data)) {
      // If no specific arms requested, we can't generate scores.
      if (empty($requested_arms)) {
        return [];
      }

      // If arms were requested, initialize them all as new.
      foreach ($requested_arms as $arm_id) {
        $arms_data[$arm_id] = (object) [
          'arm_id' => $arm_id,
          'turns' => 0,
          'rewards' => 0,
        ];
      }
    }

    return $this->tsCalculator->calculateThompsonScores($arms_data);
  }

}
