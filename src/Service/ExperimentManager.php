<?php

namespace Drupal\rl\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a new ExperimentManager.
   *
   * @param \Drupal\rl\Storage\ExperimentDataStorageInterface $storage
   *   The experiment data storage.
   * @param \Drupal\rl\Service\ThompsonCalculator $ts_calculator
   *   The Thompson Sampling calculator.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(ExperimentDataStorageInterface $storage, ThompsonCalculator $ts_calculator, ConfigFactoryInterface $config_factory, Connection $database, LoggerChannelFactoryInterface $logger_factory) {
    $this->storage = $storage;
    $this->tsCalculator = $ts_calculator;
    $this->configFactory = $config_factory;
    $this->database = $database;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function recordTurn($experiment_id, $arm_id) {
    $this->storage->recordTurn($experiment_id, $arm_id);
  }

  /**
   * {@inheritdoc}
   */
  public function recordTurns($experiment_id, array $arm_ids) {
    $this->storage->recordTurns($experiment_id, $arm_ids);
  }

  /**
   * {@inheritdoc}
   */
  public function recordReward($experiment_id, $arm_id) {
    $this->storage->recordReward($experiment_id, $arm_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getArmData($experiment_id, $arm_id) {
    return $this->storage->getArmData($experiment_id, $arm_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getAllArmsData($experiment_id) {
    return $this->storage->getAllArmsData($experiment_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getTotalTurns($experiment_id) {
    return $this->storage->getTotalTurns($experiment_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getThompsonScores($experiment_id, $time_window_seconds = NULL, array $requested_arms = []) {
    $arms_data = $this->storage->getAllArmsData($experiment_id, $time_window_seconds);

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

    $scores = $this->tsCalculator->calculateThompsonScores($arms_data);

    // Debug logging if enabled.
    if ($this->configFactory->get('rl.settings')->get('debug_mode')) {
      $this->debugLogScores($experiment_id, $scores);
    }

    return $scores;
  }

  /**
   * Logs Thompson Sampling scores for debugging.
   *
   * @param string $experiment_id
   *   The experiment ID.
   * @param array $scores
   *   The calculated scores.
   */
  protected function debugLogScores($experiment_id, array $scores) {
    // Get human-readable experiment name.
    $experiment_name = $this->getExperimentName($experiment_id);

    // Format scores for logging.
    $score_pairs = [];
    foreach ($scores as $arm_id => $score) {
      $score_pairs[] = $arm_id . ':' . number_format($score, 4);
    }

    $this->loggerFactory->get('rl_debug')->info('Thompson Sampling scores calculated | Experiment: @name (@id) | Scores: @scores', [
      '@name' => $experiment_name ?: 'Unknown',
      '@id' => $experiment_id,
      '@scores' => implode(', ', $score_pairs),
    ]);
  }

  /**
   * Gets the human-readable name for an experiment.
   *
   * @param string $experiment_id
   *   The experiment ID.
   *
   * @return string|null
   *   The experiment name or NULL if not found.
   */
  protected function getExperimentName($experiment_id) {
    try {
      $result = $this->database->select('rl_experiment_registry', 'r')
        ->fields('r', ['experiment_name'])
        ->condition('experiment_id', $experiment_id)
        ->execute()
        ->fetchField();

      return $result ?: NULL;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

}
