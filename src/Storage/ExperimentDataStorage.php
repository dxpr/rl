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
  public function recordTurn($experiment_uuid, $arm_id) {
    $timestamp = \Drupal::time()->getRequestTime();

    // Update arm data.
    $this->database->merge('rl_arm_data')
      ->key(['experiment_uuid' => $experiment_uuid, 'arm_id' => $arm_id])
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
      ->key(['experiment_uuid' => $experiment_uuid])
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
  public function recordTurns($experiment_uuid, array $arm_ids) {
    foreach ($arm_ids as $arm_id) {
      $this->recordTurn($experiment_uuid, $arm_id);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function recordReward($experiment_uuid, $arm_id) {
    $timestamp = \Drupal::time()->getRequestTime();

    $this->database->merge('rl_arm_data')
      ->key(['experiment_uuid' => $experiment_uuid, 'arm_id' => $arm_id])
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
      ->key(['experiment_uuid' => $experiment_uuid])
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
  public function getArmData($experiment_uuid, $arm_id) {
    return $this->database->select('rl_arm_data', 'ad')
      ->fields('ad', ['arm_id', 'turns', 'rewards', 'created', 'updated'])
      ->condition('experiment_uuid', $experiment_uuid)
      ->condition('arm_id', $arm_id)
      ->execute()
      ->fetchObject();
  }

  /**
   * {@inheritdoc}
   */
  public function getAllArmsData($experiment_uuid, $time_window_seconds = NULL) {
    $query = $this->database->select('rl_arm_data', 'ad')
      ->fields('ad', ['arm_id', 'turns', 'rewards', 'created', 'updated'])
      ->condition('experiment_uuid', $experiment_uuid);

    if ($time_window_seconds && $time_window_seconds > 0) {
      $cutoff_timestamp = \Drupal::time()->getRequestTime() - $time_window_seconds;
      $query->condition('updated', $cutoff_timestamp, '>=');
    }

    return $query->execute()->fetchAllAssoc('arm_id');
  }

  /**
   * {@inheritdoc}
   */
  public function getTotalTurns($experiment_uuid) {
    $result = $this->database->select('rl_experiment_totals', 'et')
      ->fields('et', ['total_turns'])
      ->condition('experiment_uuid', $experiment_uuid)
      ->execute()
      ->fetchField();

    return $result ? (int) $result : 0;
  }

}
