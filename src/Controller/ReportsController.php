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

    foreach ($snapshots as $snapshot) {
      $arm_id = $snapshot->arm_id;
      $total_turns = (int) $snapshot->total_experiment_turns;
      $created = (int) $snapshot->created;

      if (!isset($arms_data[$arm_id])) {
        $arms_data[$arm_id] = [];
      }

      $turns = (int) $snapshot->turns;
      $rewards = (int) $snapshot->rewards;

      // Calculate posterior mean.
      $alpha = $rewards + 1;
      $beta = max(1, $turns - $rewards + 1);
      $mean = $alpha / ($alpha + $beta);

      $arms_data[$arm_id][$total_turns] = [
        'mean' => $mean,
        'turns' => $turns,
        'rewards' => $rewards,
      ];

      $all_turns[$total_turns] = $created;
    }

    ksort($all_turns);
    $x_values = array_keys($all_turns);

    // Sort arms by total turns (activity).
    $arm_totals = [];
    foreach ($arms as $arm) {
      $arm_totals[$arm->arm_id] = (int) $arm->turns;
    }
    arsort($arm_totals);

    // Use up to 100 arms for 3D Plotly visualizations.
    $top_arms_3d = array_slice(array_keys($arm_totals), 0, 100);

    // Build arm label map using decorators for human-readable names.
    $arm_labels = [];
    foreach (array_keys($arm_totals) as $arm_id) {
      $arm_display = $this->decoratorManager->decorateArm($experiment_id, $arm_id);
      if ($arm_display) {
        // Render and strip HTML tags for chart labels.
        $label = strip_tags($this->renderer->renderInIsolation($arm_display));
        // Decode HTML entities to show proper quotes and special chars.
        $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Full labels for 3D charts (up to 60 chars for better readability).
        $arm_labels[$arm_id] = mb_strlen($label) > 60 ? mb_substr($label, 0, 57) . '...' : $label;
      }
      else {
        // Fallback to truncated arm ID.
        $arm_labels[$arm_id] = strlen($arm_id) > 40 ? substr($arm_id, 0, 37) . '...' : $arm_id;
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

    // Prepare ridgeline data for Plotly 3D (up to 100 arms).
    $ridgeline_data = ['arms' => []];
    $i = 0;
    foreach ($top_arms_3d as $arm_id) {
      if (!isset($arms_data[$arm_id])) {
        continue;
      }
      $color = $colors[$i % count($colors)];
      $data_points = [];
      foreach ($x_values as $x) {
        if (isset($arms_data[$arm_id][$x])) {
          $data_points[] = [
            'x' => $x,
            'y' => round($arms_data[$arm_id][$x]['mean'] * 100, 2),
          ];
        }
      }
      $ridgeline_data['arms'][] = [
        'label' => $arm_labels[$arm_id],
        'data' => $data_points,
        'color' => $color,
      ];
      $i++;
    }

    // Prepare 3D surface data for loss-landscape style visualization.
    // This creates a continuous surface from all arms' posteriors over time.
    $surface_3d_data = [
      'xValues' => array_values($x_values),
      'armLabels' => [],
      'zMatrix' => [],
    ];
    foreach ($top_arms_3d as $arm_id) {
      if (!isset($arms_data[$arm_id])) {
        continue;
      }
      $surface_3d_data['armLabels'][] = $arm_labels[$arm_id];
      $z_row = [];
      foreach ($x_values as $x) {
        if (isset($arms_data[$arm_id][$x])) {
          $z_row[] = round($arms_data[$arm_id][$x]['mean'] * 100, 2);
        }
        else {
          // Find closest previous value.
          $closest = NULL;
          foreach ($arms_data[$arm_id] as $px => $point) {
            if ($px <= $x) {
              $closest = $point;
            }
          }
          $z_row[] = $closest ? round($closest['mean'] * 100, 2) : 0;
        }
      }
      $surface_3d_data['zMatrix'][] = $z_row;
    }

    // Plotly data (up to 100 arms for 3D visualizations).
    $plotly_data = [
      'ridgelineData' => $ridgeline_data,
      'surface3d' => $surface_3d_data,
      'totalArms3d' => count($top_arms_3d),
    ];

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['rl-charts-container', 'rl-plotly-container']],
    ];

    $build['library'] = [
      '#attached' => [
        'library' => ['rl/plotly'],
      ],
    ];

    $build['charts_markup'] = [
      '#type' => 'inline_template',
      '#template' => '
        <style>
          .rl-charts-container { margin-bottom: 2em; }
          .rl-chart-row { display: flex; flex-wrap: wrap; gap: 24px; margin-bottom: 24px; }
          .rl-chart-box {
            flex: 1 1 100%;
            min-width: 280px;
            background: #fff;
            border: 2px solid #d0d0d0;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
          }
          .rl-chart-box h4 {
            margin: 0 0 8px 0;
            font-size: 18px;
            font-weight: 600;
            color: #1a1a1a;
          }
          .rl-chart-description {
            font-size: 14px;
            color: #444;
            margin-bottom: 16px;
            line-height: 1.5;
          }
          .rl-chart-area-3d {
            background: linear-gradient(135deg, #f0f4f8 0%, #e8eef3 100%);
            border: 2px solid #c0c8d0;
            border-radius: 8px;
            padding: 8px;
            margin-bottom: 16px;
            min-height: 50vh;
          }
          .rl-eli16 {
            background: #f5f7fa;
            border-left: 4px solid #4a90d9;
            padding: 12px 16px;
            margin-top: 16px;
            font-size: 13px;
            color: #555;
            line-height: 1.6;
            border-radius: 0 6px 6px 0;
          }
          .rl-eli16 strong { color: #333; }
          .rl-scroll-hint {
            font-size: 11px;
            color: #888;
            text-align: center;
            padding: 8px;
            background: #fff8e1;
            border-radius: 4px;
            margin-bottom: 8px;
          }
          /* Responsive styles */
          @media (max-width: 430px) {
            .rl-chart-box { padding: 12px; }
            .rl-chart-box h4 { font-size: 15px; }
            .rl-chart-description { font-size: 12px; margin-bottom: 10px; }
            .rl-chart-area-3d { min-height: 350px; padding: 4px; }
            .rl-eli16 { font-size: 11px; padding: 8px 12px; }
            .rl-scroll-hint { font-size: 10px; padding: 6px; }
          }
          @media (min-width: 431px) and (max-width: 768px) {
            .rl-chart-box { padding: 16px; }
            .rl-chart-box h4 { font-size: 16px; }
            .rl-chart-area-3d { min-height: 400px; }
            .rl-eli16 { font-size: 12px; }
          }
          @media (min-width: 1921px) {
            .rl-chart-box { padding: 28px; }
            .rl-chart-box h4 { font-size: 22px; }
            .rl-chart-description { font-size: 16px; }
            .rl-chart-area-3d { min-height: 70vh; padding: 16px; }
            .rl-eli16 { font-size: 15px; padding: 16px 20px; }
            .rl-scroll-hint { font-size: 13px; }
          }
        </style>
        <h3>{{ title }}</h3>
        <p class="rl-chart-description">{{ description }}</p>

        <div class="rl-chart-row">
          <div class="rl-chart-box">
            <h4>3D Posterior Landscape</h4>
            <p class="rl-chart-description">A terrain map showing conversion rates for all variants over experiment progress.</p>
            <div class="rl-scroll-hint">Scroll inside the chart to zoom. Drag to rotate. Scroll OUTSIDE the chart border to scroll the page.</div>
            <div class="rl-chart-area-3d" id="rl-plotly-3d-surface"></div>
            <div class="rl-eli16">
              <strong>What this shows:</strong> A 3D terrain map of all your variants over time. Mountains are high-performing variants, valleys are poor performers. The X-axis is experiment progress (total turns), the Y-axis represents different variants, and the height/color shows conversion rate percentage. You can rotate this view by dragging and zoom by scrolling inside the chart area. Hover over any point to see the variant name, turn number, and exact conversion rate.
            </div>
          </div>
        </div>

        <div class="rl-chart-row">
          <div class="rl-chart-box">
            <h4>3D Stacked Ridgelines</h4>
            <p class="rl-chart-description">Each colored ribbon represents one variant\'s conversion rate evolution over time.</p>
            <div class="rl-scroll-hint">Scroll inside the chart to zoom. Drag to rotate. Scroll OUTSIDE the chart border to scroll the page.</div>
            <div class="rl-chart-area-3d" id="rl-plotly-ridgelines"></div>
            <div class="rl-eli16">
              <strong>What this shows:</strong> Each variant is displayed as a separate colored ribbon stacked in 3D space. This view makes it easier to track individual performance trends when you have many variants. Higher ribbons indicate better conversion rates. Hover over any ribbon to see the full variant name and exact conversion rate at that point in time.
            </div>
          </div>
        </div>
      ',
      '#context' => [
        'title' => $this->t('Experiment Evolution Charts'),
        'description' => $this->t('Interactive 3D visualizations showing how the experiment evolved over time.'),
      ],
    ];

    $build['#attached']['drupalSettings']['rlPlotly'] = $plotly_data;

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
        'jsFormat' => "''Week'' I",
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
        'jsFormat' => "''Q''Q yyyy",
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
