<?php

namespace Drupal\rl\Storage;

/**
 * Interface for snapshot storage operations.
 */
interface SnapshotStorageInterface {

  /**
   * Check if event logging is enabled.
   *
   * @return bool
   *   TRUE if enabled.
   */
  public function isEnabled(): bool;

  /**
   * Record a snapshot of arm state.
   *
   * @param string $experiment_id
   *   The experiment ID.
   * @param string $arm_id
   *   The arm ID.
   * @param int $turns
   *   Cumulative turns for this arm.
   * @param int $rewards
   *   Cumulative rewards for this arm.
   * @param int $total_experiment_turns
   *   Total turns across all arms in experiment.
   */
  public function recordSnapshot(string $experiment_id, string $arm_id, int $turns, int $rewards, int $total_experiment_turns): void;

  /**
   * Get snapshot history for an experiment.
   *
   * @param string $experiment_id
   *   The experiment ID.
   * @param int|null $start_date
   *   Optional start timestamp to filter snapshots.
   * @param int|null $end_date
   *   Optional end timestamp to filter snapshots.
   *
   * @return array
   *   Array of snapshot objects ordered by total_experiment_turns.
   */
  public function getSnapshotHistory(string $experiment_id, ?int $start_date = NULL, ?int $end_date = NULL): array;

  /**
   * Get the date range of available snapshots for an experiment.
   *
   * @param string $experiment_id
   *   The experiment ID.
   *
   * @return array
   *   Array with 'min' and 'max' timestamps, or empty if no snapshots.
   */
  public function getSnapshotDateRange(string $experiment_id): array;

  /**
   * Clean up old snapshots according to retention policy.
   *
   * @return int
   *   Number of rows deleted.
   */
  public function cleanup(): int;

}
