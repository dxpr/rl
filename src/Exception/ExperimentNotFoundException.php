<?php

namespace Drupal\rl\Exception;

/**
 * Exception thrown when an experiment is not found.
 */
class ExperimentNotFoundException extends \Exception {

  /**
   * Constructs a new ExperimentNotFoundException.
   *
   * @param string $experiment_uuid
   *   The experiment UUID that was not found.
   * @param string $message
   *   The exception message.
   * @param int $code
   *   The exception code.
   * @param \Throwable $previous
   *   The previous throwable used for the exception chaining.
   */
  public function __construct($experiment_uuid = '', $message = '', $code = 0, \Throwable $previous = NULL) {
    if (empty($message)) {
      $message = sprintf('Experiment "%s" not found.', $experiment_uuid);
    }
    parent::__construct($message, $code, $previous);
  }

}