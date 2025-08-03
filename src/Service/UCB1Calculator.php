<?php

namespace Drupal\rl\Service;

/**
 * UCB1 algorithm calculator for multi-armed bandit problems.
 */
class UCB1Calculator {

  /**
   * Calculates UCB1 scores for all arms in an experiment.
   *
   * @param array $arms_data
   *   Array of arm data objects with arm_id, turns, and rewards.
   * @param int $total_turns
   *   Total number of turns across all arms.
   * @param float $alpha
   *   Exploration parameter (default 2.0).
   *
   * @return array
   *   Array of UCB1 scores keyed by arm_id.
   */
  public function calculateUCB1Scores(array $arms_data, $total_turns, $alpha = 2.0) {
    $scores = [];

    // Ensure we have a minimum total turns to avoid division by zero
    $total_turns = max(1, $total_turns);

    foreach ($arms_data as $arm_id => $arm_data) {
      $scores[$arm_id] = $this->calculateUCB1Score(
        $arm_data->turns,
        $arm_data->rewards,
        $total_turns,
        $alpha
      );
    }

    return $scores;
  }

  /**
   * Calculates UCB1 score for a single arm.
   *
   * @param int $arm_turns
   *   Number of times this arm has been trialed.
   * @param int $arm_rewards
   *   Number of rewards received for this arm.
   * @param int $total_turns
   *   Total number of turns across all arms.
   * @param float $alpha
   *   Exploration parameter.
   *
   * @return float
   *   The UCB1 score for this arm.
   */
  public function calculateUCB1Score($arm_turns, $arm_rewards, $total_turns, $alpha = 2.0) {
    // Ensure we don't divide by zero
    $arm_turns = max(1, $arm_turns);
    $total_turns = max(1, $total_turns);

    // Calculate the exploitation component (average reward)
    $exploitation = $arm_rewards / $arm_turns;

    // Calculate the exploration component
    $exploration = sqrt(($alpha * log($total_turns)) / $arm_turns);

    // Add small random noise to break ties
    $noise = mt_rand() / mt_getrandmax() * 0.000001;

    return $exploitation + $exploration + $noise;
  }

  /**
   * Selects the best arm based on UCB1 scores.
   *
   * @param array $scores
   *   UCB1 scores keyed by arm_id.
   *
   * @return string|null
   *   The arm_id with the highest score, or NULL if no scores.
   */
  public function selectBestArm(array $scores) {
    if (empty($scores)) {
      return NULL;
    }

    return array_keys($scores, max($scores))[0];
  }

}