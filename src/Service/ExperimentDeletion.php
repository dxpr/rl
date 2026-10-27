<?php

namespace Drupal\rl\Service;

use Drupal\Core\Database\Connection;

/**
 * Deletes experiments and their collected data atomically.
 */
class ExperimentDeletion {

  /**
   * Constructs the deletion service.
   */
  public function __construct(protected Connection $database) {}

  /**
   * Deletes the selected experiments, including all statistics and snapshots.
   *
   * @param string[] $experiment_ids
   *   The experiment IDs.
   *
   * @return int
   *   The number of registered experiments deleted.
   */
  public function deleteMultiple(array $experiment_ids): int {
    if (!$experiment_ids) {
      return 0;
    }
    $transaction = $this->database->startTransaction();
    try {
      foreach (['rl_arm_data', 'rl_experiment_totals', 'rl_arm_snapshots'] as $table) {
        $this->database->delete($table)
          ->condition('experiment_id', $experiment_ids, 'IN')
          ->execute();
      }
      return (int) $this->database->delete('rl_experiment_registry')
        ->condition('experiment_id', $experiment_ids, 'IN')
        ->execute();
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

}
