<?php

namespace Drupal\rl\Storage;

use Drupal\Core\Database\Connection;

/**
 * Storage handler for experiment data.
 */
class ExperimentDataStorage implements ExperimentDataStorageInterface {
  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a new ExperimentDataStorage.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public function recordTurn($experiment_id, $arm_id) {
    $timestamp = \Drupal::time()->getRequestTime();

    // Update arm data.
    $this->database->merge('rl_arm_data')
      ->keys(['experiment_id' => $experiment_id, 'arm_id' => $arm_id])
      ->fields([
        'turns' => 1,
        'created' => $timestamp,
        'updated' => $timestamp,
      ])
      ->expression('turns', 'turns + :inc', [':inc' => 1])
      ->expression('updated', ':timestamp', [':timestamp' => $timestamp])
      ->execute();

    // Update total turns.
    $this->database->merge('rl_experiment_totals')
      ->key('experiment_id', $experiment_id)
      ->fields([
        'total_turns' => 1,
        'created' => $timestamp,
        'updated' => $timestamp,
      ])
      ->expression('total_turns', 'total_turns + :inc', [':inc' => 1])
      ->expression('updated', ':timestamp', [':timestamp' => $timestamp])
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function recordTurns($experiment_id, array $arm_ids) {
    $timestamp = \Drupal::time()->getRequestTime();
    $arm_count = count($arm_ids);

    // Record a turn for each arm (each arm gets exposure).
    foreach ($arm_ids as $arm_id) {
      $this->database->merge('rl_arm_data')
        ->keys(['experiment_id' => $experiment_id, 'arm_id' => $arm_id])
        ->fields([
          'turns' => 1,
          'created' => $timestamp,
          'updated' => $timestamp,
        ])
        ->expression('turns', 'turns + :inc', [':inc' => 1])
        ->expression('updated', ':timestamp', [':timestamp' => $timestamp])
        ->execute();
    }

    // Record total turns = number of arms shown (sum of individual turns).
    $this->database->merge('rl_experiment_totals')
      ->key('experiment_id', $experiment_id)
      ->fields([
        'total_turns' => $arm_count,
        'created' => $timestamp,
        'updated' => $timestamp,
      ])
      ->expression('total_turns', 'total_turns + :inc', [':inc' => $arm_count])
      ->expression('updated', ':timestamp', [':timestamp' => $timestamp])
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function recordReward($experiment_id, $arm_id) {
    $timestamp = \Drupal::time()->getRequestTime();

    $this->database->merge('rl_arm_data')
      ->keys(['experiment_id' => $experiment_id, 'arm_id' => $arm_id])
      ->fields([
        'rewards' => 1,
        'created' => $timestamp,
        'updated' => $timestamp,
      ])
      ->expression('rewards', 'rewards + :inc', [':inc' => 1])
      ->expression('updated', ':timestamp', [':timestamp' => $timestamp])
      ->execute();

    // Also update experiment totals timestamp.
    $this->database->merge('rl_experiment_totals')
      ->key('experiment_id', $experiment_id)
      ->fields([
        'total_turns' => 0,
        'created' => $timestamp,
        'updated' => $timestamp,
      ])
      ->expression('updated', ':timestamp', [':timestamp' => $timestamp])
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getArmData($experiment_id, $arm_id) {
    return $this->database->select('rl_arm_data', 'ad')
      ->fields('ad', ['arm_id', 'turns', 'rewards', 'created', 'updated'])
      ->condition('experiment_id', $experiment_id)
      ->condition('arm_id', $arm_id)
      ->execute()
      ->fetchObject();
  }

  /**
   * {@inheritdoc}
   */
  public function getAllArmsData($experiment_id, $time_window_seconds = NULL) {
    $query = $this->database->select('rl_arm_data', 'ad')
      ->fields('ad', ['arm_id', 'turns', 'rewards', 'created', 'updated'])
      ->condition('experiment_id', $experiment_id);

    if ($time_window_seconds && $time_window_seconds > 0) {
      $cutoff_timestamp = \Drupal::time()->getRequestTime() - $time_window_seconds;
      $query->condition('updated', $cutoff_timestamp, '>=');
    }

    return $query->execute()->fetchAllAssoc('arm_id');
  }

  /**
   * {@inheritdoc}
   */
  public function getTotalTurns($experiment_id) {
    $result = $this->database->select('rl_experiment_totals', 'et')
      ->fields('et', ['total_turns'])
      ->condition('experiment_id', $experiment_id)
      ->execute()
      ->fetchField();

    return $result ? (int) $result : 0;
  }

}
