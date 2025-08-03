<?php

namespace Drupal\rl\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\rl\Storage\ExperimentDataStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for RL experiment reports.
 */
class ReportsController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The experiment data storage.
   *
   * @var \Drupal\rl\Storage\ExperimentDataStorageInterface
   */
  protected $experimentStorage;

  /**
   * Constructs a ReportsController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\rl\Storage\ExperimentDataStorageInterface $experiment_storage
   *   The experiment data storage.
   */
  public function __construct(Connection $database, ExperimentDataStorageInterface $experiment_storage) {
    $this->database = $database;
    $this->experimentStorage = $experiment_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('rl.experiment_data_storage')
    );
  }

  /**
   * Overview page showing all experiments.
   *
   * @return array
   *   A render array.
   */
  public function experimentsOverview() {
    $header = [
      $this->t('Experiment UUID'),
      $this->t('Total Turns'),
      $this->t('Total Arms'),
      $this->t('Last Activity'),
      $this->t('Operations'),
    ];

    $rows = [];

    // Get all experiments with their totals
    $query = $this->database->select('rl_experiment_totals', 'et')
      ->fields('et', ['experiment_uuid', 'total_turns', 'created', 'updated'])
      ->orderBy('updated', 'DESC');
    $experiments = $query->execute()->fetchAll();

    foreach ($experiments as $experiment) {
      // Count arms for this experiment
      $arms_count = $this->database->select('rl_arm_data', 'ad')
        ->condition('experiment_uuid', $experiment->experiment_uuid)
        ->countQuery()
        ->execute()
        ->fetchField();

      $detail_url = Url::fromRoute('rl.reports.experiment_detail', [
        'experiment_uuid' => $experiment->experiment_uuid,
      ]);
      $detail_link = Link::fromTextAndUrl($this->t('View details'), $detail_url);

      // Format last activity timestamp
      $last_activity = $experiment->updated > 0 
        ? $this->dateFormatter()->format($experiment->updated, 'short')
        : $this->t('Never');

      $rows[] = [
        $experiment->experiment_uuid,
        $experiment->total_turns,
        $arms_count,
        $last_activity,
        $detail_link,
      ];
    }

    $build = [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No experiments found.'),
      '#caption' => $this->t('All Reinforcement Learning experiments and their statistics.'),
    ];

    // Add some explanatory text
    $build['#prefix'] = '<p>' . $this->t('This page shows all active reinforcement learning experiments. Each experiment represents a multi-armed bandit test where different "arms" (options) are being evaluated based on user interactions (turns and rewards).') . '</p>';

    return $build;
  }

  /**
   * Detail page for a specific experiment showing all arms.
   *
   * @param string $experiment_uuid
   *   The experiment UUID.
   *
   * @return array
   *   A render array.
   */
  public function experimentDetail($experiment_uuid) {
    // Get experiment totals
    $experiment_totals = $this->database->select('rl_experiment_totals', 'et')
      ->fields('et')
      ->condition('experiment_uuid', $experiment_uuid)
      ->execute()
      ->fetchObject();

    if (!$experiment_totals) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    // Get all arms for this experiment
    $arms_query = $this->database->select('rl_arm_data', 'ad')
      ->fields('ad')
      ->condition('experiment_uuid', $experiment_uuid)
      ->orderBy('updated', 'DESC');
    $arms = $arms_query->execute()->fetchAll();

    $header = [
      $this->t('Arm ID'),
      $this->t('Turns'),
      $this->t('Rewards'),
      $this->t('Success Rate'),
      $this->t('UCB1 Score'),
      $this->t('First Seen'),
      $this->t('Last Updated'),
    ];

    $rows = [];
    $total_turns = max(1, $experiment_totals->total_turns);

    foreach ($arms as $arm) {
      $success_rate = $arm->turns > 0 ? ($arm->rewards / $arm->turns) * 100 : 0;
      
      // Calculate UCB1 score (using default alpha=2.0)
      $alpha = 2.0;
      $arm_turns = max(1, $arm->turns);
      $exploitation = $arm->rewards / $arm_turns;
      $exploration = sqrt(($alpha * log($total_turns)) / $arm_turns);
      $ucb1_score = $exploitation + $exploration;

      // Format timestamps
      $first_seen = $arm->created > 0 
        ? $this->dateFormatter()->format($arm->created, 'short')
        : $this->t('Unknown');
      $last_updated = $arm->updated > 0 
        ? $this->dateFormatter()->format($arm->updated, 'short')
        : $this->t('Never');

      $rows[] = [
        $arm->arm_id,
        $arm->turns,
        $arm->rewards,
        number_format($success_rate, 2) . '%',
        number_format($ucb1_score, 4),
        $first_seen,
        $last_updated,
      ];
    }

    // Summary information with timestamps
    $summary_items = [
      $this->t('Experiment UUID: @uuid', ['@uuid' => $experiment_uuid]),
      $this->t('Total Turns: @turns', ['@turns' => $experiment_totals->total_turns]),
      $this->t('Total Arms: @arms', ['@arms' => count($arms)]),
    ];

    if ($experiment_totals->created > 0) {
      $summary_items[] = $this->t('First Created: @created', [
        '@created' => $this->dateFormatter()->format($experiment_totals->created, 'long')
      ]);
    }

    if ($experiment_totals->updated > 0) {
      $summary_items[] = $this->t('Last Activity: @updated', [
        '@updated' => $this->dateFormatter()->format($experiment_totals->updated, 'long')
      ]);
    }

    $summary = [
      '#theme' => 'item_list',
      '#title' => $this->t('Experiment Summary'),
      '#items' => $summary_items,
    ];

    $table = [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No arms found for this experiment.'),
      '#caption' => $this->t('All arms in this experiment with their performance data.'),
    ];

    $build = [
      'summary' => $summary,
      'table' => $table,
    ];

    // Add explanatory text
    $build['#prefix'] = '<p>' . $this->t('This page shows detailed information about a specific reinforcement learning experiment and all its arms (options being tested).') . '</p>';
    $build['#suffix'] = '<p>' . $this->t('<strong>Terms:</strong><br>• <em>Turns</em>: Number of times this arm was presented/tried<br>• <em>Rewards</em>: Number of times this arm received positive feedback<br>• <em>Success Rate</em>: Percentage of turns that resulted in rewards<br>• <em>UCB1 Score</em>: Algorithm score balancing exploitation vs exploration (higher = more likely to be selected)<br>• <em>First Seen</em>: When this content was first added to the experiment<br>• <em>Last Updated</em>: When this arm last received activity (turns or rewards)') . '</p>';

    return $build;
  }

}