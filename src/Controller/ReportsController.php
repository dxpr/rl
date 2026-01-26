<?php

namespace Drupal\rl\Controller;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\rl\Decorator\ExperimentDecoratorManager;
use Drupal\rl\Storage\ExperimentDataStorageInterface;
use Drupal\rl\Storage\SnapshotStorageInterface;
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
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The experiment decorator manager.
   *
   * @var \Drupal\rl\Decorator\ExperimentDecoratorManager
   */
  protected $decoratorManager;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The arm data validator.
   *
   * @var \Drupal\rl\Service\ArmDataValidator
   */
  protected $armDataValidator;

  /**
   * The snapshot storage.
   *
   * @var \Drupal\rl\Storage\SnapshotStorageInterface
   */
  protected $snapshotStorage;

  /**
   * Constructs a ReportsController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\rl\Storage\ExperimentDataStorageInterface $experiment_storage
   *   The experiment data storage.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\rl\Decorator\ExperimentDecoratorManager $decorator_manager
   *   The experiment decorator manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\rl\Service\ArmDataValidator $arm_data_validator
   *   The arm data validator.
   * @param \Drupal\rl\Storage\SnapshotStorageInterface $snapshot_storage
   *   The snapshot storage.
   */
  public function __construct(Connection $database, ExperimentDataStorageInterface $experiment_storage, DateFormatterInterface $date_formatter, ExperimentDecoratorManager $decorator_manager, RendererInterface $renderer, $arm_data_validator = NULL, ?SnapshotStorageInterface $snapshot_storage = NULL) {
    $this->database = $database;
    $this->experimentStorage = $experiment_storage;
    $this->dateFormatter = $date_formatter;
    $this->decoratorManager = $decorator_manager;
    $this->renderer = $renderer;
    // Use service container if validator not injected (backward compatibility).
    $this->armDataValidator = $arm_data_validator ?: \Drupal::service('rl.arm_data_validator');
    $this->snapshotStorage = $snapshot_storage ?: \Drupal::service('rl.snapshot_storage');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
          $container->get('database'),
          $container->get('rl.experiment_data_storage'),
          $container->get('date.formatter'),
          $container->get('rl.experiment_decorator_manager'),
          $container->get('renderer'),
          $container->get('rl.arm_data_validator'),
          $container->get('rl.snapshot_storage')
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
      $this->t('Operations'),
      $this->t('Experiment ID'),
      $this->t('Ownership'),
      $this->t('Total Turns'),
      $this->t('Total Arms'),
      $this->t('Last Activity'),
    ];

    $rows = [];

    // Get all registered experiments with their totals (if any)
    $query = $this->database->select('rl_experiment_registry', 'er')
      ->fields('er', ['experiment_id', 'module', 'experiment_name', 'registered_at']);
    $query->leftJoin('rl_experiment_totals', 'et', 'er.experiment_id = et.experiment_id');
    $query->addField('et', 'total_turns', 'total_turns');
    $query->addField('et', 'created', 'totals_created');
    $query->addField('et', 'updated', 'totals_updated');
    $query->orderBy('er.registered_at', 'DESC');
    $experiments = $query->execute()->fetchAll();

    foreach ($experiments as $experiment) {
      // Count arms for this experiment.
      $arms_count = $this->database->select('rl_arm_data', 'ad')
        ->condition('experiment_id', $experiment->experiment_id)
        ->countQuery()
        ->execute()
        ->fetchField();

      $operations = [];

      $detail_url = Url::fromRoute('rl.reports.experiment_detail', [
        'experiment_id' => $experiment->experiment_id,
      ]);
      $operations[] = Link::fromTextAndUrl($this->t('View'), $detail_url);

      if ($this->currentUser()->hasPermission('administer rl experiments')) {
        $delete_url = Url::fromRoute('rl.experiment.delete', [
          'experiment_id' => $experiment->experiment_id,
        ]);
        $operations[] = Link::fromTextAndUrl($this->t('Delete'), $delete_url);
      }

      $operations_markup = implode(' | ', array_map(function ($link) {
        return $link->toString();
      }, $operations));

      // Format last activity timestamp - use totals_updated if available,
      // otherwise registered_at.
      $last_activity_timestamp = $experiment->totals_updated ?: $experiment->registered_at;
      $last_activity = $last_activity_timestamp > 0
            ? $this->dateFormatter->format($last_activity_timestamp, 'short')
            : $this->t('Never');

      // Use experiment name from registry or fallback to experiment ID.
      $experiment_name = $experiment->experiment_name ?: $experiment->experiment_id;

      $rows[] = [
        ['data' => ['#markup' => $operations_markup]],
        $experiment_name,
        $experiment->module,
        $experiment->total_turns ?: 0,
        $arms_count,
        $last_activity,
      ];
    }

    $build = [];

    $actions = [];

    if ($this->currentUser()->hasPermission('administer rl experiments')) {
      $add_url = Url::fromRoute('rl.experiment.add');
      $actions[] = [
        '#type' => 'link',
        '#title' => $this->t('Add experiment'),
        '#url' => $add_url,
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }

    if (!empty($actions)) {
      $build['actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['rl-actions']],
        'links' => $actions,
        '#suffix' => '<br><br>',
      ];
    }

    $build['table'] = [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No experiments found.'),
      '#caption' => $this->t('All Reinforcement Learning experiments and their statistics.'),
    ];

    $build['#prefix'] = '<p>' . $this->t('This page shows all active reinforcement learning experiments. Each experiment represents a multi-armed bandit test where different "arms" (options) are being evaluated based on user interactions (turns and rewards).') . '</p>'
      . '<p>' . $this->t('<strong>Tip:</strong> Deleting an experiment resets its data. Experiments auto-recreate on next render.') . '</p>';

    return $build;
  }

  /**
   * Detail page for a specific experiment showing all arms.
   *
   * @param string $experiment_id
   *   The experiment ID.
   *
   * @return array
   *   A render array.
   */
  public function experimentDetail($experiment_id) {
    // Get experiment totals.
    $experiment_totals = $this->database->select('rl_experiment_totals', 'et')
      ->fields('et')
      ->condition('experiment_id', $experiment_id)
      ->execute()
      ->fetchObject();

    if (!$experiment_totals) {
      throw new NotFoundHttpException();
    }

    // Get all arms for this experiment.
    $arms_query = $this->database->select('rl_arm_data', 'ad')
      ->fields('ad')
      ->condition('experiment_id', $experiment_id)
      ->orderBy('updated', 'DESC');
    $arms = $arms_query->execute()->fetchAll();

    $build = [];

    // Add explanatory text.
    $build['intro'] = [
      '#markup' => '<p>' . $this->t('This page shows detailed information about a specific reinforcement learning experiment and all its arms (options being tested).') . '</p>',
    ];

    // Add charts if we have snapshot data.
    $snapshots = $this->snapshotStorage->getSnapshotHistory($experiment_id);
    if (!empty($snapshots)) {
      $build['charts'] = $this->buildCharts($experiment_id, $snapshots, $arms);
    }
    else {
      $build['no_charts'] = [
        '#markup' => '<p><em>' . $this->t('No historical data available for charts. Enable event logging to track experiment evolution over time.') . '</em></p>',
      ];
    }

    // Build sortable header - use field specifier for tablesorter.
    $header = [
      ['data' => $this->t('Arm ID'), 'field' => 'arm_id'],
      ['data' => $this->t('Turns'), 'field' => 'turns', 'sort' => 'desc'],
      ['data' => $this->t('Rewards'), 'field' => 'rewards'],
      ['data' => $this->t('Success Rate'), 'field' => 'success_rate'],
      ['data' => $this->t('TS Score'), 'field' => 'ts_score'],
    ];

    // Build row data with sortable values.
    $arm_data = [];
    foreach ($arms as $arm) {
      // Validate and sanitize arm data.
      $arm = $this->armDataValidator->validateAndSanitize($arm, $experiment_id, $arm->arm_id);

      $success_rate = $arm->turns > 0 ? ($arm->rewards / $arm->turns) * 100 : 0;

      // Calculate Thompson Sampling score.
      $alpha_param = $arm->rewards + 1;
      $beta_param = ($arm->turns - $arm->rewards) + 1;
      // Beta mean as approximation.
      $ts_score = $alpha_param / ($alpha_param + $beta_param);

      // Get decorated arm name or fallback to arm ID.
      $arm_display = $this->decoratorManager->decorateArm($experiment_id, $arm->arm_id);
      $arm_name = $arm_display ? $this->renderer->renderInIsolation($arm_display) : $arm->arm_id;

      $arm_data[] = [
        'arm_id' => $arm->arm_id,
        'arm_name' => $arm_name,
        'turns' => (int) $arm->turns,
        'rewards' => (int) $arm->rewards,
        'success_rate' => $success_rate,
        'ts_score' => $ts_score,
      ];
    }

    // Sort by the selected column.
    $order = \Drupal::request()->query->get('order', 'Turns');
    $sort = \Drupal::request()->query->get('sort', 'desc');

    $sort_field = 'turns';
    if (stripos($order, 'Arm') !== FALSE) {
      $sort_field = 'arm_id';
    }
    elseif (stripos($order, 'Reward') !== FALSE) {
      $sort_field = 'rewards';
    }
    elseif (stripos($order, 'Success') !== FALSE) {
      $sort_field = 'success_rate';
    }
    elseif (stripos($order, 'TS') !== FALSE) {
      $sort_field = 'ts_score';
    }

    usort($arm_data, function ($a, $b) use ($sort_field, $sort) {
      $cmp = $a[$sort_field] <=> $b[$sort_field];
      return $sort === 'desc' ? -$cmp : $cmp;
    });

    // Build rows for display.
    $rows = [];
    foreach ($arm_data as $data) {
      $rows[] = [
        ['data' => ['#markup' => $data['arm_name']]],
        $data['turns'],
        $data['rewards'],
        number_format($data['success_rate'], 2) . '%',
        number_format($data['ts_score'], 4),
      ];
    }

    $build['table'] = [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No arms found for this experiment.'),
      '#caption' => $this->t('All arms in this experiment with their performance data.'),
      '#attributes' => ['class' => ['rl-sortable-table']],
    ];

    $build = [
      '#title' => $this->t('RL Experiment: @id', ['@id' => $experiment_id]),
      'table' => $table,
    ];

    return $build;
  }

  /**
   * Build charts render array.
   *
   * @param string $experiment_id
   *   The experiment ID.
   * @param array $snapshots
   *   Array of snapshot objects.
   * @param array $arms
   *   Array of arm objects with current state.
   *
   * @return array
   *   Render array with charts.
   */
  protected function buildCharts(string $experiment_id, array $snapshots, array $arms): array {
    // Organize snapshots by arm and calculate chart data.
    $arms_data = [];
    $all_turns = [];
    $all_timestamps = [];

    foreach ($snapshots as $snapshot) {
      $arm_id = $snapshot->arm_id;
      $total_turns = (int) $snapshot->total_experiment_turns;
      $created = (int) $snapshot->created;

      if (!isset($arms_data[$arm_id])) {
        $arms_data[$arm_id] = [];
      }

      $turns = (int) $snapshot->turns;
      $rewards = (int) $snapshot->rewards;

      // Calculate posterior mean and CI.
      $alpha = $rewards + 1;
      $beta = max(1, $turns - $rewards + 1);
      $mean = $alpha / ($alpha + $beta);

      // Approximate 95% CI using normal approximation for Beta.
      $variance = ($alpha * $beta) / (pow($alpha + $beta, 2) * ($alpha + $beta + 1));
      $std = sqrt($variance);
      $ci_low = max(0, $mean - 1.96 * $std);
      $ci_high = min(1, $mean + 1.96 * $std);

      $arms_data[$arm_id][$total_turns] = [
        'mean' => $mean,
        'ci_low' => $ci_low,
        'ci_high' => $ci_high,
        'turns' => $turns,
        'rewards' => $rewards,
        'created' => $created,
      ];

      $all_turns[$total_turns] = $created;
      $all_timestamps[] = $created;
    }

    ksort($all_turns);
    $x_values = array_keys($all_turns);

    // Determine time granularity based on date span.
    $time_config = $this->determineTimeGranularity($all_timestamps);

    // Limit to top 10 arms by final turns for line charts.
    $arm_totals = [];
    foreach ($arms as $arm) {
      $arm_totals[$arm->arm_id] = (int) $arm->turns;
    }
    arsort($arm_totals);
    $top_arms = array_slice(array_keys($arm_totals), 0, 10);

    // Build arm label map using decorators for human-readable names.
    $arm_labels = [];
    foreach (array_keys($arm_totals) as $arm_id) {
      $arm_display = $this->decoratorManager->decorateArm($experiment_id, $arm_id);
      if ($arm_display) {
        // Render and strip HTML tags for chart labels.
        $label = strip_tags($this->renderer->renderInIsolation($arm_display));
        // Truncate long labels for charts.
        $arm_labels[$arm_id] = strlen($label) > 25 ? substr($label, 0, 22) . '...' : $label;
      }
      else {
        // Fallback to truncated arm ID.
        $arm_labels[$arm_id] = strlen($arm_id) > 20 ? substr($arm_id, 0, 17) . '...' : $arm_id;
      }
    }

    // Generate colors for arms.
    $colors = [
      'rgba(255, 99, 132, 1)',
      'rgba(54, 162, 235, 1)',
      'rgba(255, 206, 86, 1)',
      'rgba(75, 192, 192, 1)',
      'rgba(153, 102, 255, 1)',
      'rgba(255, 159, 64, 1)',
      'rgba(199, 199, 199, 1)',
      'rgba(83, 102, 255, 1)',
      'rgba(255, 99, 255, 1)',
      'rgba(99, 255, 132, 1)',
    ];

    // Prepare line chart data.
    $line_datasets = [];
    $i = 0;
    foreach ($top_arms as $arm_id) {
      if (!isset($arms_data[$arm_id])) {
        continue;
      }
      $color = $colors[$i % count($colors)];
      $bg_color = str_replace('1)', '0.2)', $color);

      $data_points = [];
      $ci_low_points = [];
      $ci_high_points = [];

      foreach ($x_values as $x) {
        if (isset($arms_data[$arm_id][$x])) {
          $data_points[] = ['x' => $x, 'y' => round($arms_data[$arm_id][$x]['mean'] * 100, 2)];
          $ci_low_points[] = ['x' => $x, 'y' => round($arms_data[$arm_id][$x]['ci_low'] * 100, 2)];
          $ci_high_points[] = ['x' => $x, 'y' => round($arms_data[$arm_id][$x]['ci_high'] * 100, 2)];
        }
      }

      $line_datasets[] = [
        'label' => $arm_labels[$arm_id],
        'data' => array_values($data_points),
        'borderColor' => $color,
        'backgroundColor' => $bg_color,
        'fill' => FALSE,
        'tension' => 0.1,
      ];

      $i++;
    }

    // Prepare heatmap data (for all arms).
    $heatmap_data = [];
    $arm_ids_sorted = array_keys($arm_totals);
    foreach ($arm_ids_sorted as $idx => $arm_id) {
      if (!isset($arms_data[$arm_id])) {
        continue;
      }
      foreach ($arms_data[$arm_id] as $x => $point) {
        $heatmap_data[] = [
          'x' => $x,
          'y' => $idx,
          'v' => round($point['mean'] * 100, 1),
        ];
      }
    }

    // Prepare ranking data (track rank over time for top arms).
    $ranking_data = [];
    foreach ($x_values as $x) {
      $scores_at_x = [];
      foreach ($arms_data as $arm_id => $points) {
        // Find closest point at or before x.
        $closest = NULL;
        foreach ($points as $px => $point) {
          if ($px <= $x) {
            $closest = $point;
          }
        }
        if ($closest) {
          $scores_at_x[$arm_id] = $closest['mean'];
        }
      }
      arsort($scores_at_x);
      $rank = 1;
      foreach ($scores_at_x as $arm_id => $score) {
        if (!isset($ranking_data[$arm_id])) {
          $ranking_data[$arm_id] = [];
        }
        $ranking_data[$arm_id][$x] = $rank;
        $rank++;
      }
    }

    $ranking_datasets = [];
    $i = 0;
    foreach ($top_arms as $arm_id) {
      if (!isset($ranking_data[$arm_id])) {
        continue;
      }
      $color = $colors[$i % count($colors)];
      $data_points = [];
      foreach ($x_values as $x) {
        if (isset($ranking_data[$arm_id][$x])) {
          $data_points[] = ['x' => $x, 'y' => $ranking_data[$arm_id][$x]];
        }
      }
      $ranking_datasets[] = [
        'label' => $arm_labels[$arm_id],
        'data' => array_values($data_points),
        'borderColor' => $color,
        'fill' => FALSE,
        'tension' => 0.3,
      ];
      $i++;
    }

    // Prepare P(best) data using Monte Carlo simulation.
    $pbest_data = [];
    $num_samples = 1000;
    foreach ($x_values as $x) {
      $wins = [];
      foreach ($top_arms as $arm_id) {
        $wins[$arm_id] = 0;
      }

      // Get current state for each arm at this point.
      $states = [];
      foreach ($top_arms as $arm_id) {
        if (!isset($arms_data[$arm_id])) {
          continue;
        }
        $closest = NULL;
        foreach ($arms_data[$arm_id] as $px => $point) {
          if ($px <= $x) {
            $closest = $point;
          }
        }
        if ($closest) {
          $states[$arm_id] = [
            'alpha' => $closest['rewards'] + 1,
            'beta' => max(1, $closest['turns'] - $closest['rewards'] + 1),
          ];
        }
      }

      if (count($states) < 2) {
        continue;
      }

      // Monte Carlo sampling.
      for ($s = 0; $s < $num_samples; $s++) {
        $best_arm = NULL;
        $best_sample = -1;
        foreach ($states as $arm_id => $state) {
          // Sample from Beta distribution using inverse transform.
          $sample = $this->sampleBeta($state['alpha'], $state['beta']);
          if ($sample > $best_sample) {
            $best_sample = $sample;
            $best_arm = $arm_id;
          }
        }
        if ($best_arm) {
          $wins[$best_arm]++;
        }
      }

      $pbest_data[$x] = [];
      foreach ($top_arms as $arm_id) {
        $pbest_data[$x][$arm_id] = isset($wins[$arm_id]) ? $wins[$arm_id] / $num_samples : 0;
      }
    }

    // Prepare stacked area datasets for P(best).
    $pbest_datasets = [];
    $i = 0;
    foreach ($top_arms as $arm_id) {
      $color = $colors[$i % count($colors)];
      $bg_color = str_replace('1)', '0.6)', $color);
      $data_points = [];
      foreach ($x_values as $x) {
        if (isset($pbest_data[$x][$arm_id])) {
          $data_points[] = ['x' => $x, 'y' => round($pbest_data[$x][$arm_id] * 100, 1)];
        }
      }
      $pbest_datasets[] = [
        'label' => $arm_labels[$arm_id],
        'data' => array_values($data_points),
        'borderColor' => $color,
        'backgroundColor' => $bg_color,
        'fill' => TRUE,
      ];
      $i++;
    }

    // Prepare convergence data (average CI width over time).
    $convergence_data = [];
    foreach ($x_values as $x) {
      $ci_widths = [];
      foreach ($arms_data as $arm_id => $points) {
        if (isset($points[$x])) {
          $ci_widths[] = $points[$x]['ci_high'] - $points[$x]['ci_low'];
        }
      }
      if (!empty($ci_widths)) {
        $convergence_data[] = ['x' => $x, 'y' => round(array_sum($ci_widths) / count($ci_widths) * 100, 2)];
      }
    }

    // Build time-based data for the timeline chart.
    $timeline_datasets = [];
    $i = 0;
    foreach ($top_arms as $arm_id) {
      if (!isset($arms_data[$arm_id])) {
        continue;
      }
      $color = $colors[$i % count($colors)];

      $time_points = [];
      foreach ($arms_data[$arm_id] as $total_turns => $point) {
        // Use timestamp as x value (in milliseconds for Chart.js).
        $time_points[] = [
          'x' => $point['created'] * 1000,
          'y' => round($point['mean'] * 100, 2),
        ];
      }

      // Sort by time.
      usort($time_points, function ($a, $b) {
        return $a['x'] - $b['x'];
      });

      $timeline_datasets[] = [
        'label' => $arm_labels[$arm_id],
        'data' => array_values($time_points),
        'borderColor' => $color,
        'fill' => FALSE,
        'tension' => 0.1,
      ];

      $i++;
    }

    // Build the render array.
    // Use array_values() to ensure proper JSON array serialization.
    $chart_data = [
      'lineDatasets' => array_values($line_datasets),
      'rankingDatasets' => array_values($ranking_datasets),
      'pbestDatasets' => array_values($pbest_datasets),
      'convergenceData' => array_values($convergence_data),
      'heatmapData' => array_values($heatmap_data),
      'timelineDatasets' => array_values($timeline_datasets),
      'timeConfig' => $time_config,
      'armLabels' => array_values(array_map(function ($id) {
        return strlen($id) > 15 ? substr($id, 0, 12) . '...' : $id;
      }, $arm_ids_sorted)),
      'xValues' => array_values($x_values),
      'totalArms' => count($arms),
    ];

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['rl-charts-container']],
    ];

    $build['library'] = [
      '#attached' => [
        'library' => ['rl/charts'],
      ],
    ];

    $build['charts_markup'] = [
      '#type' => 'inline_template',
      '#template' => '
        <style>
          .rl-charts-container { margin-bottom: 2em; }
          .rl-chart-row { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 20px; }
          .rl-chart-box { flex: 1 1 45%; min-width: 400px; background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 15px; }
          .rl-chart-box h4 { margin-top: 0; margin-bottom: 10px; font-size: 14px; color: #333; }
          .rl-chart-box canvas { max-height: 300px; }
          .rl-chart-box.full-width { flex: 1 1 100%; }
          .rl-chart-description { font-size: 12px; color: #666; margin-bottom: 10px; }
        </style>
        <h3>{{ title }}</h3>
        <p class="rl-chart-description">{{ description }}</p>

        <div class="rl-chart-row">
          <div class="rl-chart-box">
            <h4>1. Conversion Rate Over Time (with 95% CI)</h4>
            <p class="rl-chart-description">Shows estimated conversion rate for each arm as evidence accumulates.</p>
            <canvas id="rl-line-chart"></canvas>
          </div>
          <div class="rl-chart-box">
            <h4>2. Probability of Being Best</h4>
            <p class="rl-chart-description">Shows which arm is most likely the winner at each point in time.</p>
            <canvas id="rl-pbest-chart"></canvas>
          </div>
        </div>

        <div class="rl-chart-row">
          <div class="rl-chart-box">
            <h4>3. Ranking Over Time</h4>
            <p class="rl-chart-description">Shows how arm rankings changed as the experiment progressed.</p>
            <canvas id="rl-ranking-chart"></canvas>
          </div>
          <div class="rl-chart-box">
            <h4>4. Convergence (Uncertainty Reduction)</h4>
            <p class="rl-chart-description">Shows how quickly we are becoming confident in results (lower = more confident).</p>
            <canvas id="rl-convergence-chart"></canvas>
          </div>
        </div>

        <div class="rl-chart-row">
          <div class="rl-chart-box full-width">
            <h4>5. Timeline: Conversion Rate by {{ time_label }}</h4>
            <p class="rl-chart-description">Shows conversion rate evolution over calendar time. Useful for identifying seasonal patterns or external events.</p>
            <canvas id="rl-timeline-chart"></canvas>
          </div>
        </div>

        <div class="rl-chart-row">
          <div class="rl-chart-box full-width">
            <h4>6. Heatmap: All Arms Over Time</h4>
            <p class="rl-chart-description">Color intensity shows conversion rate. Rows are arms (sorted by total activity), columns are experiment progress.</p>
            <canvas id="rl-heatmap-chart"></canvas>
          </div>
        </div>
      ',
      '#context' => [
        'title' => $this->t('Experiment Evolution Charts'),
        'description' => $this->t('These charts show how the experiment evolved over time. Showing top 10 arms by activity.'),
        'time_label' => $time_config['label'],
      ],
    ];

    $build['#attached']['drupalSettings']['rlCharts'] = $chart_data;

    return $build;
  }

  /**
   * Determine appropriate time granularity based on data span.
   *
   * @param array $timestamps
   *   Array of Unix timestamps.
   *
   * @return array
   *   Array with 'granularity', 'format', and 'label' keys.
   */
  protected function determineTimeGranularity(array $timestamps): array {
    if (empty($timestamps)) {
      return [
        'granularity' => 'day',
        'format' => 'M j',
        'label' => 'Date',
        'jsFormat' => 'MMM d',
      ];
    }

    $min_time = min($timestamps);
    $max_time = max($timestamps);
    $span_days = ($max_time - $min_time) / 86400;

    if ($span_days <= 14) {
      // Up to 2 weeks: show days.
      return [
        'granularity' => 'day',
        'format' => 'M j',
        'label' => 'Date',
        'jsFormat' => 'MMM d',
      ];
    }
    elseif ($span_days <= 90) {
      // Up to 3 months: show weeks.
      return [
        'granularity' => 'week',
        'format' => '\WW, Y',
        'label' => 'Week',
        'jsFormat' => "'W'W, yyyy",
      ];
    }
    elseif ($span_days <= 365) {
      // Up to 1 year: show months.
      return [
        'granularity' => 'month',
        'format' => 'M Y',
        'label' => 'Month',
        'jsFormat' => 'MMM yyyy',
      ];
    }
    else {
      // Over 1 year: show quarters.
      return [
        'granularity' => 'quarter',
        'format' => '\QQ Y',
        'label' => 'Quarter',
        'jsFormat' => "'Q'Q yyyy",
      ];
    }
  }

  /**
   * Sample from Beta distribution using inverse transform.
   *
   * @param float $alpha
   *   Alpha parameter.
   * @param float $beta
   *   Beta parameter.
   *
   * @return float
   *   Sample from Beta(alpha, beta).
   */
  protected function sampleBeta(float $alpha, float $beta): float {
    // Use gamma sampling: Beta(a,b) = Gamma(a,1) / (Gamma(a,1) + Gamma(b,1))
    $x = $this->sampleGamma($alpha);
    $y = $this->sampleGamma($beta);
    return $x / ($x + $y);
  }

  /**
   * Sample from Gamma distribution using Marsaglia and Tsang's method.
   *
   * @param float $shape
   *   Shape parameter (k).
   *
   * @return float
   *   Sample from Gamma(shape, 1).
   */
  protected function sampleGamma(float $shape): float {
    if ($shape < 1) {
      return $this->sampleGamma($shape + 1) * pow(mt_rand() / mt_getrandmax(), 1 / $shape);
    }

    $d = $shape - 1 / 3;
    $c = 1 / sqrt(9 * $d);

    while (TRUE) {
      $x = $this->sampleNormal();
      $v = pow(1 + $c * $x, 3);

      if ($v > 0) {
        $u = mt_rand() / mt_getrandmax();
        if ($u < 1 - 0.0331 * pow($x, 4) ||
            log($u) < 0.5 * pow($x, 2) + $d * (1 - $v + log($v))) {
          return $d * $v;
        }
      }
    }
  }

  /**
   * Sample from standard normal distribution using Box-Muller.
   *
   * @return float
   *   Sample from N(0,1).
   */
  protected function sampleNormal(): float {
    $u1 = mt_rand() / mt_getrandmax();
    $u2 = mt_rand() / mt_getrandmax();
    return sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
  }

}
