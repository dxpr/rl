<?php

namespace Drupal\rl\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
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
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(Connection $database, ExperimentDataStorageInterface $experiment_storage, DateFormatterInterface $date_formatter, ExperimentDecoratorManager $decorator_manager, RendererInterface $renderer, ArmDataValidator $arm_data_validator, SnapshotStorageInterface $snapshot_storage, RequestStack $request_stack) {
    $this->database = $database;
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
      $container->get('database'),
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
      $this->t('Variants'),
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

    // Add charts if we have snapshot data.
    $snapshots = $this->snapshotStorage->getSnapshotHistory($experiment_id);
    if (!empty($snapshots)) {
      $build['charts'] = $this->buildCharts($experiment_id, $snapshots, $arms);
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
    $request = $this->requestStack->getCurrentRequest();
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
      'xValues' => $x_values,
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
    $chart_line_threshold = $this->config('rl.settings')->get('chart_line_threshold') ?? 10;
    $total_arms_all = count($arm_totals);
    $plotly_data = [
      'ridgelineData' => $ridgeline_data,
      'surface3d' => $surface_3d_data,
      'totalArmsDisplayed' => count($top_arms_3d),
      'totalArmsAll' => $total_arms_all,
      'chartLineThreshold' => $chart_line_threshold,
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
      '#chart_title_2d' => $this->t('Conversion Rate Over Time'),
      '#chart_title_3d_surface' => $this->t('Conversion Rate Over Time'),
    ];

    return $build;
  }

}
