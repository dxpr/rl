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
   * The UCB1 calculator.
   *
   * @var \Drupal\rl\Service\UCB1Calculator
   */
  protected $ucb1Calculator;

  /**
   * Constructs a new ExperimentManager.
   *
   * @param \Drupal\rl\Storage\ExperimentDataStorageInterface $storage
   *   The experiment data storage.
   * @param \Drupal\rl\Service\UCB1Calculator $ucb1_calculator
   *   The UCB1 calculator.
   */
  public function __construct(ExperimentDataStorageInterface $storage, UCB1Calculator $ucb1_calculator) {
    $this->storage = $storage;
    $this->ucb1Calculator = $ucb1_calculator;
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
  public function getUCB1Scores($experiment_uuid, $alpha = 2.0) {
    $arms_data = $this->getAllArmsData($experiment_uuid);
    $total_turns = $this->getTotalTurns($experiment_uuid);

    return $this->ucb1Calculator->calculateUCB1Scores($arms_data, $total_turns, $alpha);
  }

}