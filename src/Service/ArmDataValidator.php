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
   * Validates arm data and throws exception if invalid.
   *
   * @param object $arm
   *   The arm data object with turns and rewards properties.
   * @param string $experiment_id
   *   The experiment ID for logging context.
   * @param string $arm_id
   *   The arm ID for logging context.
   *
   * @return object
   *   The validated arm data object with normalized types.
   *
   * @throws \RuntimeException
   *   If arm data is invalid.
   */
  public function validateAndSanitize($arm, string $experiment_id, string $arm_id) {
    // Ensure turns is a non-negative integer.
    if (!is_numeric($arm->turns) || $arm->turns < 0) {
      $this->logger->critical('The %field field has invalid value %value for experiment %experiment_id, arm %arm_id.', [
        '%field' => 'turns',
        '%value' => var_export($arm->turns, TRUE),
        '%experiment_id' => $experiment_id,
        '%arm_id' => $arm_id,
      ]);
      throw new \RuntimeException(sprintf(
        'Invalid turns value %s for experiment %s, arm %s. Expected non-negative integer.',
        var_export($arm->turns, TRUE),
        $experiment_id,
        $arm_id
      ));
    }
    $arm->turns = (int) $arm->turns;

    // Ensure rewards is a non-negative integer.
    if (!is_numeric($arm->rewards) || $arm->rewards < 0) {
      $this->logger->critical('The %field field has invalid value %value for experiment %experiment_id, arm %arm_id.', [
        '%field' => 'rewards',
        '%value' => var_export($arm->rewards, TRUE),
        '%experiment_id' => $experiment_id,
        '%arm_id' => $arm_id,
      ]);
      throw new \RuntimeException(sprintf(
        'Invalid rewards value %s for experiment %s, arm %s. Expected non-negative integer.',
        var_export($arm->rewards, TRUE),
        $experiment_id,
        $arm_id
      ));
    }
    $arm->rewards = (int) $arm->rewards;

    // Critical validation: rewards cannot exceed turns.
    // Note: We sanitize instead of throwing to prevent DoS attacks where
    // malicious actors send reward requests to crash the site.
    if ($arm->rewards > $arm->turns) {
      $this->logger->critical('Data integrity violation: rewards (%rewards) exceeds turns (%turns) for experiment %experiment_id, arm %arm_id. This indicates database corruption, a bug in reward tracking, or malicious reward requests. Sanitizing to prevent site crash.', [
        '%rewards' => $arm->rewards,
        '%turns' => $arm->turns,
        '%experiment_id' => $experiment_id,
        '%arm_id' => $arm_id,
      ]);
      // Sanitize: cap rewards at turns to maintain mathematical validity.
      $arm->rewards = $arm->turns;
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
