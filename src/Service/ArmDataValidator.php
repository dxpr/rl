<?php

namespace Drupal\rl\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for validating arm data integrity.
 *
 * Ensures arm data is valid before Thompson Sampling calculations
 * to prevent division by zero and other mathematical errors.
 */
class ArmDataValidator {

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new ArmDataValidator.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(LoggerInterface $logger) {
    $this->logger = $logger;
  }

  /**
   * Validates and sanitizes arm data.
   *
   * @param object $arm
   *   The arm data object with turns and rewards properties.
   * @param string $experiment_id
   *   The experiment ID for logging context.
   * @param string $arm_id
   *   The arm ID for logging context.
   *
   * @return object
   *   The sanitized arm data object.
   */
  public function validateAndSanitize($arm, string $experiment_id, string $arm_id) {
    $original_turns = $arm->turns;
    $original_rewards = $arm->rewards;
    $was_modified = FALSE;

    // Ensure turns is a non-negative integer.
    if (!is_numeric($arm->turns) || $arm->turns < 0) {
      $this->logger->error('Invalid turns value for experiment @exp_id, arm @arm_id: @value. Defaulting to 0.', [
        '@exp_id' => $experiment_id,
        '@arm_id' => $arm_id,
        '@value' => var_export($arm->turns, TRUE),
      ]);
      $arm->turns = 0;
      $was_modified = TRUE;
    }
    else {
      $arm->turns = (int) $arm->turns;
    }

    // Ensure rewards is a non-negative integer.
    if (!is_numeric($arm->rewards) || $arm->rewards < 0) {
      $this->logger->error('Invalid rewards value for experiment @exp_id, arm @arm_id: @value. Defaulting to 0.', [
        '@exp_id' => $experiment_id,
        '@arm_id' => $arm_id,
        '@value' => var_export($arm->rewards, TRUE),
      ]);
      $arm->rewards = 0;
      $was_modified = TRUE;
    }
    else {
      $arm->rewards = (int) $arm->rewards;
    }

    // Critical validation: rewards cannot exceed turns.
    if ($arm->rewards > $arm->turns) {
      $this->logger->critical('Data integrity violation in experiment @exp_id, arm @arm_id: rewards (@rewards) exceeds turns (@turns). This indicates database corruption or a bug in reward tracking. Setting rewards = turns to prevent division by zero.', [
        '@exp_id' => $experiment_id,
        '@arm_id' => $arm_id,
        '@rewards' => $arm->rewards,
        '@turns' => $arm->turns,
      ]);
      $arm->rewards = $arm->turns;
      $was_modified = TRUE;
    }

    // Log warning if data was sanitized.
    if ($was_modified) {
      $this->logger->warning('Arm data sanitized for experiment @exp_id, arm @arm_id. Original: turns=@orig_turns, rewards=@orig_rewards. Sanitized: turns=@new_turns, rewards=@new_rewards.', [
        '@exp_id' => $experiment_id,
        '@arm_id' => $arm_id,
        '@orig_turns' => $original_turns,
        '@orig_rewards' => $original_rewards,
        '@new_turns' => $arm->turns,
        '@new_rewards' => $arm->rewards,
      ]);
    }

    return $arm;
  }

  /**
   * Validates an array of arm data objects.
   *
   * @param array $arms_data
   *   Array of arm data objects keyed by arm ID.
   * @param string $experiment_id
   *   The experiment ID for logging context.
   *
   * @return array
   *   The sanitized arms data array.
   */
  public function validateArmsData(array $arms_data, string $experiment_id): array {
    foreach ($arms_data as $arm_id => $arm) {
      $arms_data[$arm_id] = $this->validateAndSanitize($arm, $experiment_id, (string) $arm_id);
    }
    return $arms_data;
  }

}
