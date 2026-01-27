<?php

namespace Drupal\rl\Storage;

/**
 * Interface for experiment data storage operations.
 */
interface ExperimentDataStorageInterface {

  /**
   * Records a turn for a specific arm in an experiment.
   *
   * @param string $experiment_id
   *   The experiment ID.
   * @param string $arm_id
   *   The arm identifier.
   */
  public function recordTurn($experiment_id, $arm_id);

  /**
   * Records turns for multiple arms in an experiment.
   *
   * @param string $experiment_id
   *   The experiment ID.
   * @param array $arm_ids
   *   Array of arm identifiers.
   */
  public function recordTurns($experiment_id, array $arm_ids);

  /**
   * Records a reward for a specific arm in an experiment.
   *
   * @param string $experiment_id
   *   The experiment ID.
   * @param string $arm_id
   *   The arm identifier.
   */
  public function recordReward($experiment_id, $arm_id);

  /**
   * Gets data for a specific arm in an experiment.
   *
   * @param string $experiment_id
   *   The experiment ID.
   * @param string $arm_id
   *   The arm identifier.
   *
   * @return object|null
   *   Object with turns and rewards, or NULL if not found.
   */
  public function getArmData($experiment_id, $arm_id);

  /**
   * Gets data for all arms in an experiment.
   *
   * @param string $experiment_id
   *   The experiment ID.
   * @param int|null $time_window_seconds
   *   Optional time window in seconds. Only returns arms active within this
   *   timeframe.
   *
   * @return array
   *   Array of arm data objects keyed by arm_id.
   */
  public function getAllArmsData($experiment_id, $time_window_seconds = NULL);

  /**
   * Gets the total number of turns for an experiment.
   *
   * @param string $experiment_id
   *   The experiment ID.
   *
   * @return int
   *   The total number of turns.
   */
  public function getTotalTurns($experiment_id);

  /**
   * Gets all experiments with their statistics for the overview page.
   *
   * @return array
   *   Array of experiment objects with stats.
   */
  public function getExperimentsWithStats(): array;

  /**
   * Gets the experiment totals record.
   *
   * @param string $experiment_id
   *   The experiment ID.
   *
   * @return object|null
   *   The experiment totals object or NULL if not found.
   */
  public function getExperimentTotals(string $experiment_id): ?object;

  /**
   * Gets all arms for an experiment with their data.
   *
   * @param string $experiment_id
   *   The experiment ID.
   *
   * @return array
   *   Array of arm data objects.
   */
  public function getArmsByExperiment(string $experiment_id): array;

}
