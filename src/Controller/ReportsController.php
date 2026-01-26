<?php

namespace Drupal\rl\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\rl\Decorator\ExperimentDecoratorManager;
use Drupal\rl\Service\ArmDataValidator;
use Drupal\rl\Storage\ExperimentDataStorageInterface;
use Drupal\rl\Storage\SnapshotStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for RL experiment reports.
 */
class ReportsController extends ControllerBase {

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
  protected ArmDataValidator $armDataValidator;

  /**
   * The snapshot storage.
   *
   * @var \Drupal\rl\Storage\SnapshotStorageInterface
   */
  protected SnapshotStorageInterface $snapshotStorage;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * Constructs a ReportsController object.
   *
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
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(ExperimentDataStorageInterface $experiment_storage, DateFormatterInterface $date_formatter, ExperimentDecoratorManager $decorator_manager, RendererInterface $renderer, ArmDataValidator $arm_data_validator, SnapshotStorageInterface $snapshot_storage, RequestStack $request_stack) {
    $this->experimentStorage = $experiment_storage;
    $this->dateFormatter = $date_formatter;
    $this->decoratorManager = $decorator_manager;
    $this->renderer = $renderer;
    $this->armDataValidator = $arm_data_validator;
    $this->snapshotStorage = $snapshot_storage;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   *
   * PHPStan note: The 'new.static' warning is suppressed because Drupal's
   * dependency injection pattern requires static factories in non-final
   * controller classes. This is standard Drupal architecture.
   */
  public static function create(ContainerInterface $container): static {
    // @phpstan-ignore new.static
    return new static(
      $container->get('rl.experiment_data_storage'),
      $container->get('date.formatter'),
      $container->get('rl.experiment_decorator_manager'),
      $container->get('renderer'),
      $container->get('rl.arm_data_validator'),
      $container->get('rl.snapshot_storage'),
      $container->get('request_stack')
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
      $this->t('Experiment'),
      $this->t('Source'),
      $this->t('Impressions'),
      $this->t('Conversions'),
      $this->t('Variants'),
      $this->t('Last Activity'),
    ];

    $rows = [];

    // Get all experiments with their statistics from storage.
    $experiments = $this->experimentStorage->getExperimentsWithStats();

    foreach ($experiments as $experiment) {
      $arms_count = $experiment->arm_count;
      $total_rewards = $experiment->total_rewards;

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
        $total_rewards,
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
    ];

    $build['#prefix'] = '<p>' . $this->t('<strong>Tip:</strong> Deleting an experiment resets its data. Experiments auto-recreate on next render.') . '</p>';

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
    // Get experiment totals from storage.
    $experiment_totals = $this->experimentStorage->getExperimentTotals($experiment_id);

    if (!$experiment_totals) {
      throw new NotFoundHttpException();
    }

    // Get all arms for this experiment from storage.
    $arms = $this->experimentStorage->getArmsByExperiment($experiment_id);

    $build = [];

    // Get date range from request or use defaults.
    $request = $this->requestStack->getCurrentRequest();
    $preset = $request ? $request->query->get('preset', '') : '';
    $start_date = $request ? $request->query->get('start') : NULL;
    $end_date = $request ? $request->query->get('end') : NULL;
    $time_axis = $request ? $request->query->get('axis', 'trials') : 'trials';

    // Validate time axis value.
    $valid_axes = ['trials', 'daily', 'weekly', 'monthly', 'quarterly'];
    if (!in_array($time_axis, $valid_axes)) {
      $time_axis = 'trials';
    }

    // Calculate date range from preset if provided.
    $date_range = $this->calculateDateRange($preset, $start_date, $end_date);

    // Get available date range for this experiment.
    $available_range = $this->snapshotStorage->getSnapshotDateRange($experiment_id);

    // Build date filter form for charts.
    $date_filter = NULL;
    if (!empty($available_range)) {
      $date_filter = $this->buildDateFilterForm($experiment_id, $date_range, $available_range, $preset, $time_axis);
    }

    // Add charts if we have snapshot data.
    $snapshots = $this->snapshotStorage->getSnapshotHistory(
      $experiment_id,
      $date_range['start'] ?? NULL,
      $date_range['end'] ?? NULL
    );
    if (!empty($snapshots)) {
      $build['charts'] = $this->buildCharts($experiment_id, $snapshots, $arms, $time_axis, $date_filter);
    }
    else {
      $build['no_charts'] = [
        '#markup' => '<p><em>' . $this->t('No data yet. Charts appear after the experiment receives traffic.') . '</em></p>',
      ];
    }

    // Build sortable header - use field specifier for tablesorter.
    $header = [
      ['data' => $this->t('Variant'), 'field' => 'arm_id'],
      ['data' => $this->t('Impressions'), 'field' => 'turns'],
      ['data' => $this->t('Conversions'), 'field' => 'rewards'],
      ['data' => $this->t('Rate'), 'field' => 'success_rate', 'sort' => 'desc'],
    ];

    // Build row data with sortable values.
    $arm_data = [];
    foreach ($arms as $arm) {
      // Validate and sanitize arm data.
      $arm = $this->armDataValidator->validateAndSanitize($arm, $experiment_id, $arm->arm_id);

      $success_rate = $arm->turns > 0 ? ($arm->rewards / $arm->turns) * 100 : 0;

      // Get decorated arm name or fallback to escaped arm ID.
      $arm_display = $this->decoratorManager->decorateArm($experiment_id, $arm->arm_id);
      $arm_name = $arm_display ? $this->renderer->renderInIsolation($arm_display) : Html::escape($arm->arm_id);

      $arm_data[] = [
        'arm_id' => $arm->arm_id,
        'arm_name' => $arm_name,
        'turns' => (int) $arm->turns,
        'rewards' => (int) $arm->rewards,
        'success_rate' => $success_rate,
      ];
    }

    // Sort by the selected column.
    $order = $request ? $request->query->get('order', 'Rate') : 'Rate';
    $sort = $request ? $request->query->get('sort', 'desc') : 'desc';

    $sort_field = 'success_rate';
    if (stripos($order, 'Variant') !== FALSE) {
      $sort_field = 'arm_id';
    }
    elseif (stripos($order, 'Impression') !== FALSE) {
      $sort_field = 'turns';
    }
    elseif (stripos($order, 'Conversion') !== FALSE) {
      $sort_field = 'rewards';
    }

    // @phpstan-ignore argument.unresolvableType, argument.unresolvableType
    usort($arm_data, static function (array $a, array $b) use ($sort_field, $sort): int {
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
      ];
    }

    $build['table'] = [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No variants found.'),
      '#attributes' => ['class' => ['rl-sortable-table']],
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
   * @param string $time_axis
   *   Time axis type: 'trials', 'daily', 'weekly', 'monthly',
   *   or 'quarterly'.
   * @param array|null $date_filter
   *   Optional date filter render array.
   *
   * @return array
   *   Render array with charts.
   */
  protected function buildCharts(string $experiment_id, array $snapshots, array $arms, string $time_axis = 'trials', ?array $date_filter = NULL): array {
    // Organize snapshots by arm and calculate chart data.
    $arms_data = [];
    $all_x_values = [];
    $x_axis_label = $this->t('Total Impressions');
    $x_labels = [];

    foreach ($snapshots as $snapshot) {
      $arm_id = $snapshot->arm_id;
      $created = (int) $snapshot->created;

      // Calculate x-axis value based on time axis type.
      if ($time_axis === 'trials') {
        $x_value = (int) $snapshot->total_experiment_turns;
      }
      else {
        $x_value = $this->getTimeBucket($created, $time_axis);
      }

      if (!isset($arms_data[$arm_id])) {
        $arms_data[$arm_id] = [];
      }

      $turns = (int) $snapshot->turns;
      $rewards = (int) $snapshot->rewards;

      // Calculate posterior mean.
      $alpha = $rewards + 1;
      $beta = max(1, $turns - $rewards + 1);
      $mean = $alpha / ($alpha + $beta);

      // For time-based axes, keep the latest snapshot per bucket.
      if (!isset($arms_data[$arm_id][$x_value]) || $created > $arms_data[$arm_id][$x_value]['created']) {
        $arms_data[$arm_id][$x_value] = [
          'mean' => $mean,
          'turns' => $turns,
          'rewards' => $rewards,
          'created' => $created,
        ];
      }

      $all_x_values[$x_value] = $created;
    }

    ksort($all_x_values);
    $x_values = array_keys($all_x_values);

    // Generate x-axis labels for time-based axes.
    if ($time_axis !== 'trials') {
      $x_axis_label = $this->getTimeAxisLabel($time_axis);
      foreach ($x_values as $x_value) {
        $x_labels[$x_value] = $this->formatTimeBucket($x_value, $time_axis);
      }
    }

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

    // Prepare chart data for both 2D line and 3D surface (single loop).
    $line_chart_data = ['arms' => []];
    $surface_3d_data = ['xValues' => $x_values, 'armLabels' => [], 'zMatrix' => []];
    $i = 0;
    foreach ($top_arms_3d as $arm_id) {
      if (!isset($arms_data[$arm_id])) {
        continue;
      }
      $label = $arm_labels[$arm_id];
      $color = $colors[$i % count($colors)];
      $data_points = [];
      $z_row = [];

      foreach ($x_values as $x) {
        if (isset($arms_data[$arm_id][$x])) {
          $rate = round($arms_data[$arm_id][$x]['mean'] * 100, 2);
          $data_points[] = ['x' => $x, 'y' => $rate];
          $z_row[] = $rate;
        }
        else {
          // Find closest previous value for 3D surface interpolation.
          $closest = NULL;
          foreach ($arms_data[$arm_id] as $px => $point) {
            if ($px <= $x) {
              $closest = $point;
            }
          }
          $z_row[] = $closest ? round($closest['mean'] * 100, 2) : 0;
        }
      }

      $line_chart_data['arms'][] = ['label' => $label, 'data' => $data_points, 'color' => $color];
      $surface_3d_data['armLabels'][] = $label;
      $surface_3d_data['zMatrix'][] = $z_row;
      $i++;
    }

    // Plotly data (up to 100 arms for 3D visualizations).
    $chart_line_threshold = $this->config('rl.settings')->get('chart_line_threshold') ?? 10;
    $total_arms_all = count($arm_totals);
    $plotly_data = [
      'lineChartData' => $line_chart_data,
      'surface3d' => $surface_3d_data,
      'totalArmsDisplayed' => count($top_arms_3d),
      'totalArmsAll' => $total_arms_all,
      'chartLineThreshold' => $chart_line_threshold,
      'timeAxis' => $time_axis,
      'xAxisLabel' => (string) $x_axis_label,
      'xLabels' => !empty($x_labels) ? $x_labels : NULL,
    ];

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['rl-charts-container', 'rl-plotly-container']],
      '#attached' => [
        'library' => ['rl/plotly'],
        'drupalSettings' => [
          'rlPlotly' => $plotly_data,
        ],
      ],
    ];

    $build['charts'] = [
      '#theme' => 'rl_charts',
      '#title' => $this->t('Performance Over Time'),
      '#tip_hover' => $this->t('Hover for details. Higher = better.'),
      '#tip_taller' => $this->t('Hover for details. Taller/brighter = better conversion rate.'),
      '#interaction_hint' => $this->t('Drag to rotate @bullet Scroll to zoom', ['@bullet' => '•']),
      '#date_filter' => $date_filter,
    ];

    return $build;
  }

  /**
   * Calculate date range from preset or explicit dates.
   *
   * @param string $preset
   *   Preset name (e.g., 'last_4_weeks', 'this_month').
   * @param string|null $start_date
   *   Explicit start date (Y-m-d format).
   * @param string|null $end_date
   *   Explicit end date (Y-m-d format).
   *
   * @return array
   *   Array with 'start' and 'end' timestamps, or empty for all data.
   */
  protected function calculateDateRange(string $preset, ?string $start_date, ?string $end_date): array {
    $today_end = strtotime('today 23:59:59');

    // Handle relative presets (last_X_days, last_X_weeks).
    if (preg_match('/^last_(\d+)_(day|days|week|weeks)$/', $preset, $matches)) {
      $amount = $matches[1];
      $unit = str_contains($matches[2], 'day') ? 'days' : 'weeks';
      return [
        'start' => strtotime("-{$amount} {$unit} midnight"),
        'end' => $today_end,
      ];
    }

    // Handle named presets.
    $named_presets = [
      'this_month' => 'first day of this month midnight',
      'this_year' => 'first day of January this year midnight',
    ];
    if (isset($named_presets[$preset])) {
      return ['start' => strtotime($named_presets[$preset]), 'end' => $today_end];
    }

    // Handle this_quarter specially (requires calculation).
    if ($preset === 'this_quarter') {
      $month = (int) date('n');
      $quarter_start_month = (int) (floor(($month - 1) / 3) * 3 + 1);
      return [
        'start' => strtotime(date('Y') . '-' . str_pad((string) $quarter_start_month, 2, '0', STR_PAD_LEFT) . '-01 midnight'),
        'end' => $today_end,
      ];
    }

    // Handle explicit dates.
    if ($start_date || $end_date) {
      $range = [];
      if ($start_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
        $range['start'] = strtotime($start_date . ' 00:00:00');
      }
      if ($end_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        $range['end'] = strtotime($end_date . ' 23:59:59');
      }
      return $range;
    }

    // No filter - return empty array for all data.
    return [];
  }

  /**
   * Get the time bucket for a timestamp based on granularity.
   *
   * @param int $timestamp
   *   The Unix timestamp.
   * @param string $granularity
   *   The granularity: 'hourly', 'daily', 'weekly', 'monthly', 'quarterly'.
   *
   * @return int
   *   A bucket identifier (timestamp of bucket start).
   */
  protected function getTimeBucket(int $timestamp, string $granularity): int {
    switch ($granularity) {
      case 'hourly':
        return (int) strtotime(date('Y-m-d H:00:00', $timestamp));

      case 'daily':
        return (int) strtotime(date('Y-m-d', $timestamp));

      case 'weekly':
        // Start of week (Monday).
        return (int) strtotime('monday this week', $timestamp);

      case 'monthly':
        return (int) strtotime(date('Y-m-01', $timestamp));

      case 'quarterly':
        $month = (int) date('n', $timestamp);
        $quarter_month = (int) (floor(($month - 1) / 3) * 3 + 1);
        return (int) strtotime(date('Y', $timestamp) . '-' . str_pad((string) $quarter_month, 2, '0', STR_PAD_LEFT) . '-01');

      default:
        return $timestamp;
    }
  }

  /**
   * Format a time bucket for display.
   *
   * @param int $bucket
   *   The bucket timestamp.
   * @param string $granularity
   *   The granularity.
   *
   * @return string
   *   Formatted label.
   */
  protected function formatTimeBucket(int $bucket, string $granularity): string {
    switch ($granularity) {
      case 'hourly':
        return date('M j, H:00', $bucket);

      case 'daily':
        return date('M j', $bucket);

      case 'weekly':
        return 'W' . date('W', $bucket) . ' ' . date('M j', $bucket);

      case 'monthly':
        return date('M Y', $bucket);

      case 'quarterly':
        $month = (int) date('n', $bucket);
        $quarter = (int) ceil($month / 3);
        return 'Q' . $quarter . ' ' . date('Y', $bucket);

      default:
        return (string) $bucket;
    }
  }

  /**
   * Get the x-axis label for a time axis type.
   *
   * @param string $time_axis
   *   The time axis type.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The axis label.
   */
  protected function getTimeAxisLabel(string $time_axis) {
    switch ($time_axis) {
      case 'hourly':
        return $this->t('Hour');

      case 'daily':
        return $this->t('Date');

      case 'weekly':
        return $this->t('Week');

      case 'monthly':
        return $this->t('Month');

      case 'quarterly':
        return $this->t('Quarter');

      default:
        return $this->t('Total Impressions');
    }
  }

  /**
   * Build the date filter form.
   *
   * @param string $experiment_id
   *   The experiment ID.
   * @param array $current_range
   *   Currently selected date range.
   * @param array $available_range
   *   Available date range from snapshots.
   * @param string $current_preset
   *   Currently selected preset.
   * @param string $current_axis
   *   Currently selected time axis.
   *
   * @return array
   *   Render array for date filter.
   */
  protected function buildDateFilterForm(string $experiment_id, array $current_range, array $available_range, string $current_preset, string $current_axis = 'trials'): array {
    $base_url = Url::fromRoute('rl.reports.experiment_detail', [
      'experiment_id' => $experiment_id,
    ]);

    $presets = [
      '' => $this->t('All time'),
      'last_1_day' => $this->t('Last 1 day'),
      'last_5_days' => $this->t('Last 5 days'),
      'last_1_week' => $this->t('Last 1 week'),
      'last_2_weeks' => $this->t('Last 2 weeks'),
      'last_4_weeks' => $this->t('Last 4 weeks'),
      'last_8_weeks' => $this->t('Last 8 weeks'),
      'last_12_weeks' => $this->t('Last 12 weeks'),
      'last_24_weeks' => $this->t('Last 24 weeks'),
      'this_month' => $this->t('This month'),
      'this_quarter' => $this->t('This quarter'),
      'this_year' => $this->t('This year'),
    ];

    $axes = [
      'trials' => $this->t('Impressions'),
      'daily' => $this->t('Daily'),
      'weekly' => $this->t('Weekly'),
      'monthly' => $this->t('Monthly'),
      'quarterly' => $this->t('Quarterly'),
    ];

    // Helper to build dropdown options with URLs.
    $build_options = function (array $items, string $param, string $other_param, string $other_value, string $other_default) use ($base_url): array {
      $options = $urls = [];
      foreach ($items as $key => $label) {
        $url = clone $base_url;
        $query = [];
        if ($key && $key !== $other_default) {
          $query[$param] = $key;
        }
        if ($other_value && $other_value !== $other_default) {
          $query[$other_param] = $other_value;
        }
        if (!empty($query)) {
          $url->setOption('query', $query);
        }
        $options[$key] = $label;
        $urls[$key] = $url->toString();
      }
      return ['options' => $options, 'urls' => $urls];
    };

    $preset_data = $build_options($presets, 'preset', 'axis', $current_axis, 'trials');
    $axis_data = $build_options($axes, 'axis', 'preset', $current_preset, '');

    // Format available date range for display.
    $range_text = $this->t('Data available from @start to @end', [
      '@start' => $this->dateFormatter->format($available_range['min'], 'short'),
      '@end' => $this->dateFormatter->format($available_range['max'], 'short'),
    ]);

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['rl-date-filter']],
      'presets' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['rl-presets']],
        'label' => [
          '#markup' => '<strong>' . $this->t('Time range:') . '</strong> ',
        ],
        'select' => [
          '#type' => 'select',
          '#options' => $preset_data['options'],
          '#value' => $current_preset,
          '#attributes' => [
            'class' => ['rl-preset-select', 'rl-filter-select'],
            'data-urls' => json_encode($preset_data['urls']),
          ],
        ],
      ],
      'axes' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['rl-axes']],
        'label' => [
          '#markup' => '<strong>' . $this->t('X-axis:') . '</strong> ',
        ],
        'select' => [
          '#type' => 'select',
          '#options' => $axis_data['options'],
          '#value' => $current_axis,
          '#attributes' => [
            'class' => ['rl-axis-select', 'rl-filter-select'],
            'data-urls' => json_encode($axis_data['urls']),
          ],
        ],
      ],
      'range_info' => [
        '#markup' => '<div class="rl-range-info">' . $range_text . '</div>',
      ],
      '#attached' => [
        'library' => ['rl/date-filter'],
      ],
    ];
  }

}
