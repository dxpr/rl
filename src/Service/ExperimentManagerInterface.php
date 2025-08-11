<?php

namespace Drupal\rl\Service;

/**
 * Interface for experiment management operations.
 */
interface ExperimentManagerInterface {

  /**
   * Records a turn for a specific arm in an experiment.
   *
   * @param string $experiment_uuid
   *   The experiment UUID.
   * @param string $arm_id
   *   The arm identifier.
   */
  public function recordTurn($experiment_uuid, $arm_id);

  /**
   * Records turns for multiple arms in an experiment.
   *
   * @param string $experiment_uuid
   *   The experiment UUID.
   * @param array $arm_ids
   *   Array of arm identifiers.
   */
  public function recordTurns($experiment_uuid, array $arm_ids);

  /**
   * Records a reward for a specific arm in an experiment.
   *
   * @param string $experiment_uuid
   *   The experiment UUID.
   * @param string $arm_id
   *   The arm identifier.
   */
  public function recordReward($experiment_uuid, $arm_id);

  /**
   * Gets data for a specific arm in an experiment.
   *
   * @param string $experiment_uuid
   *   The experiment UUID.
   * @param string $arm_id
   *   The arm identifier.
   *
   * @return object|null
   *   Object with turns and rewards, or NULL if not found.
   */
  public function getArmData($experiment_uuid, $arm_id);

  /**
   * Gets data for all arms in an experiment.
   *
   * @param string $experiment_uuid
   *   The experiment UUID.
   *
   * @return array
   *   Array of arm data objects.
   */
  public function getAllArmsData($experiment_uuid);

  /**
   * Gets the total number of turns for an experiment.
   *
   * @param string $experiment_uuid
   *   The experiment UUID.
   *
   * @return int
   *   The total number of turns.
   */
  public function getTotalTurns($experiment_uuid);

  /**
   * Gets Thompson Sampling scores for all arms in an experiment.
   *
   * @param string $experiment_uuid
   *   The experiment UUID.
   * @param int|null $time_window_seconds
   *   Optional time window in seconds. Only considers arms active within this timeframe.
   * @param array $requested_arms
   *   Optional array of arm IDs that need scores. New arms will be initialized
   *   with zero stats (0 turns, 0 rewards) to ensure maximum exploration.
   *
   * @return array
   *   Array of Thompson Sampling scores keyed by arm_id. Returns empty array
   *   only if no arms exist AND no requested_arms were provided.
   */
  public function getThompsonScores($experiment_uuid, $time_window_seconds = NULL, array $requested_arms = []);

}
