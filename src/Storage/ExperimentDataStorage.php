<?php

namespace Drupal\rl\Storage;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;

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
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The snapshot storage service.
   *
   * @var \Drupal\rl\Storage\SnapshotStorageInterface|null
   */
  protected $snapshotStorage;

  /**
   * Constructs a new ExperimentDataStorage.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\rl\Storage\SnapshotStorageInterface|null $snapshot_storage
   *   The snapshot storage service.
   */
  public function __construct(Connection $database, TimeInterface $time, ?SnapshotStorageInterface $snapshot_storage = NULL) {
    $this->database = $database;
    $this->time = $time;
    $this->snapshotStorage = $snapshot_storage;
  }

  /**
   * {@inheritdoc}
   */
  public function recordTurn($experiment_id, $arm_id) {
    $timestamp = $this->time->getRequestTime();

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

    // Record snapshot if enabled.
    $this->maybeRecordSnapshots($experiment_id, [$arm_id]);
  }

  /**
   * {@inheritdoc}
   */
  public function recordTurns($experiment_id, array $arm_ids) {
    $timestamp = $this->time->getRequestTime();
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

    // Record snapshots if enabled.
    $this->maybeRecordSnapshots($experiment_id, $arm_ids);
  }

  /**
   * {@inheritdoc}
   */
  public function recordReward($experiment_id, $arm_id) {
    $timestamp = $this->time->getRequestTime();

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
        'created' => $timestamp,
        'updated' => $timestamp,
      ])
      ->expression('updated', ':timestamp', [':timestamp' => $timestamp])
      ->execute();

    // Record snapshot for reward if enabled.
    $this->maybeRecordSnapshots($experiment_id, [$arm_id]);
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
      $cutoff_timestamp = $this->time->getRequestTime() - $time_window_seconds;
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

  /**
   * Record snapshots for arms if event logging is enabled.
   *
   * @param string $experiment_id
   *   The experiment ID.
   * @param array $arm_ids
   *   Array of arm IDs to snapshot.
   */
  protected function maybeRecordSnapshots(string $experiment_id, array $arm_ids): void {
    if (!$this->snapshotStorage || !$this->snapshotStorage->isEnabled()) {
      return;
    }

    $total_turns = $this->getTotalTurns($experiment_id);

    foreach ($arm_ids as $arm_id) {
      $arm_data = $this->getArmData($experiment_id, $arm_id);
      if ($arm_data) {
        $this->snapshotStorage->recordSnapshot(
          $experiment_id,
          $arm_id,
          (int) $arm_data->turns,
          (int) $arm_data->rewards,
          $total_turns
        );
      }
    }
  }

}
