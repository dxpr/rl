<?php

namespace Drupal\rl\Storage;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Storage handler for experiment snapshots (event log).
 */
class SnapshotStorage implements SnapshotStorageInterface {

  /**
   * Maximum snapshots per arm (budget).
   */
  const MAX_SNAPSHOTS_PER_ARM = 250;

  /**
   * Maximum rows per experiment to prevent single experiment dominating.
   */
  const MAX_ROWS_PER_EXPERIMENT = 10000;

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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new SnapshotStorage.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(Connection $database, TimeInterface $time, ConfigFactoryInterface $config_factory) {
    $this->database = $database;
    $this->time = $time;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    return (bool) $this->configFactory->get('rl.settings')->get('enable_event_log');
  }

  /**
   * {@inheritdoc}
   */
  public function recordSnapshot(string $experiment_id, string $arm_id, int $turns, int $rewards, int $total_experiment_turns): void {
    if (!$this->isEnabled()) {
      return;
    }

    // Check if we should record a snapshot based on our budget strategy.
    $arm_count = $this->getArmCount($experiment_id);
    $snapshots_per_arm = $this->calculateSnapshotsPerArm($arm_count);

    if (!$this->shouldRecordSnapshot($experiment_id, $arm_id, $total_experiment_turns, $snapshots_per_arm)) {
      return;
    }

    $is_milestone = $this->isMilestone($total_experiment_turns, $snapshots_per_arm);

    $this->database->insert('rl_arm_snapshots')
      ->fields([
        'experiment_id' => $experiment_id,
        'arm_id' => $arm_id,
        'turns' => $turns,
        'rewards' => $rewards,
        'total_experiment_turns' => $total_experiment_turns,
        'created' => $this->time->getRequestTime(),
        'is_milestone' => $is_milestone ? 1 : 0,
      ])
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getSnapshotHistory(string $experiment_id): array {
    return $this->database->select('rl_arm_snapshots', 's')
      ->fields('s', ['arm_id', 'turns', 'rewards', 'total_experiment_turns', 'created'])
      ->condition('experiment_id', $experiment_id)
      ->orderBy('total_experiment_turns', 'ASC')
      ->execute()
      ->fetchAll();
  }

  /**
   * {@inheritdoc}
   */
  public function cleanup(): int {
    $deleted = 0;

    // Get all experiments.
    $experiments = $this->database->select('rl_arm_snapshots', 's')
      ->fields('s', ['experiment_id'])
      ->distinct()
      ->execute()
      ->fetchCol();

    foreach ($experiments as $experiment_id) {
      $arm_count = $this->getArmCount($experiment_id);
      $snapshots_per_arm = $this->calculateSnapshotsPerArm($arm_count);
      $recent_window = $this->calculateRecentWindow($snapshots_per_arm);

      // Get all arms for this experiment.
      $arms = $this->database->select('rl_arm_snapshots', 's')
        ->fields('s', ['arm_id'])
        ->condition('experiment_id', $experiment_id)
        ->distinct()
        ->execute()
        ->fetchCol();

      foreach ($arms as $arm_id) {
        // Get non-milestone snapshots beyond recent window.
        $subquery = $this->database->select('rl_arm_snapshots', 's')
          ->fields('s', ['id'])
          ->condition('experiment_id', $experiment_id)
          ->condition('arm_id', $arm_id)
          ->condition('is_milestone', 0)
          ->orderBy('total_experiment_turns', 'DESC')
          ->range($recent_window, 1000000);

        $ids_to_delete = $subquery->execute()->fetchCol();

        if (!empty($ids_to_delete)) {
          $deleted += $this->database->delete('rl_arm_snapshots')
            ->condition('id', $ids_to_delete, 'IN')
            ->execute();
        }
      }
    }

    // Global cleanup if over max rows.
    $max_rows = $this->configFactory->get('rl.settings')->get('event_log_max_rows') ?: 100000;
    $total_rows = $this->database->select('rl_arm_snapshots', 's')
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($total_rows > $max_rows) {
      $to_delete = $total_rows - $max_rows;
      // Delete oldest non-milestone rows.
      $ids = $this->database->select('rl_arm_snapshots', 's')
        ->fields('s', ['id'])
        ->condition('is_milestone', 0)
        ->orderBy('created', 'ASC')
        ->range(0, $to_delete)
        ->execute()
        ->fetchCol();

      if (!empty($ids)) {
        $deleted += $this->database->delete('rl_arm_snapshots')
          ->condition('id', $ids, 'IN')
          ->execute();
      }
    }

    return $deleted;
  }

  /**
   * Calculate snapshots per arm based on arm count.
   *
   * @param int $arm_count
   *   Number of arms in experiment.
   *
   * @return int
   *   Snapshots allowed per arm.
   */
  protected function calculateSnapshotsPerArm(int $arm_count): int {
    if ($arm_count <= 0) {
      return self::MAX_SNAPSHOTS_PER_ARM;
    }
    return min(
      self::MAX_SNAPSHOTS_PER_ARM,
      max(20, (int) floor(self::MAX_ROWS_PER_EXPERIMENT / $arm_count))
    );
  }

  /**
   * Calculate the first N trials to capture at full resolution.
   *
   * @param int $snapshots_per_arm
   *   Total snapshot budget per arm.
   *
   * @return int
   *   Number of trials to capture at start.
   */
  protected function calculateFirstWindow(int $snapshots_per_arm): int {
    // 40% of budget for first trials.
    return (int) floor($snapshots_per_arm * 0.4);
  }

  /**
   * Calculate the last N trials to capture at full resolution.
   *
   * @param int $snapshots_per_arm
   *   Total snapshot budget per arm.
   *
   * @return int
   *   Number of recent trials to keep.
   */
  protected function calculateRecentWindow(int $snapshots_per_arm): int {
    // 40% of budget for recent trials.
    return (int) floor($snapshots_per_arm * 0.4);
  }

  /**
   * Calculate middle section snapshot interval.
   *
   * @param int $snapshots_per_arm
   *   Total snapshot budget per arm.
   * @param int $total_turns
   *   Current total turns.
   *
   * @return int
   *   Interval between middle snapshots.
   */
  protected function calculateMiddleInterval(int $snapshots_per_arm, int $total_turns): int {
    $first_window = $this->calculateFirstWindow($snapshots_per_arm);
    $recent_window = $this->calculateRecentWindow($snapshots_per_arm);
    $middle_budget = $snapshots_per_arm - $first_window - $recent_window;

    if ($middle_budget <= 0 || $total_turns <= $first_window) {
      return 1;
    }

    $middle_range = max(1, $total_turns - $first_window - $recent_window);
    return max(1, (int) floor($middle_range / max(1, $middle_budget)));
  }

  /**
   * Determine if we should record a snapshot at this point.
   *
   * @param string $experiment_id
   *   The experiment ID.
   * @param string $arm_id
   *   The arm ID.
   * @param int $total_turns
   *   Current total experiment turns.
   * @param int $snapshots_per_arm
   *   Snapshot budget per arm.
   *
   * @return bool
   *   TRUE if we should record.
   */
  protected function shouldRecordSnapshot(string $experiment_id, string $arm_id, int $total_turns, int $snapshots_per_arm): bool {
    $first_window = $this->calculateFirstWindow($snapshots_per_arm);

    // Always record in first window.
    if ($total_turns <= $first_window) {
      return TRUE;
    }

    // Always record recent (cleanup handles the window).
    // For middle section, use interval.
    $interval = $this->calculateMiddleInterval($snapshots_per_arm, $total_turns);
    return ($total_turns % $interval) === 0;
  }

  /**
   * Determine if this is a permanent milestone snapshot.
   *
   * @param int $total_turns
   *   Current total turns.
   * @param int $snapshots_per_arm
   *   Snapshot budget per arm.
   *
   * @return bool
   *   TRUE if this is a milestone.
   */
  protected function isMilestone(int $total_turns, int $snapshots_per_arm): bool {
    $first_window = $this->calculateFirstWindow($snapshots_per_arm);

    // First window are all milestones.
    if ($total_turns <= $first_window) {
      return TRUE;
    }

    // Middle section milestones at interval points.
    $interval = $this->calculateMiddleInterval($snapshots_per_arm, $total_turns);
    return ($total_turns % $interval) === 0;
  }

  /**
   * Get the number of arms for an experiment.
   *
   * @param string $experiment_id
   *   The experiment ID.
   *
   * @return int
   *   Number of arms.
   */
  protected function getArmCount(string $experiment_id): int {
    $count = $this->database->select('rl_arm_data', 'a')
      ->condition('experiment_id', $experiment_id)
      ->countQuery()
      ->execute()
      ->fetchField();

    return (int) $count ?: 1;
  }

}
