<?php

namespace Drupal\rl\Service;

/**
 * Interface for experiment management operations.
 */
interface ExperimentManagerInterface {

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
   *
   * @return array
   *   Array of arm data objects.
   */
  public function getAllArmsData($experiment_id);

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
   * Gets Thompson Sampling scores for all arms in an experiment.
   *
   * @param string $experiment_id
   *   The experiment ID.
   * @param int|null $time_window_seconds
   *   Optional time window in seconds. Only considers arms active within this
   *   timeframe.
   * @param array $requested_arms
   *   Optional array of arm IDs that need scores. New arms will be initialized
   *   with zero stats (0 turns, 0 rewards) to ensure maximum exploration.
   *
   * @return array
   *   Array of Thompson Sampling scores keyed by arm_id. Returns empty array
   *   only if no arms exist AND no requested_arms were provided.
   */
  public function getThompsonScores($experiment_id, $time_window_seconds = NULL, array $requested_arms = []);

}
