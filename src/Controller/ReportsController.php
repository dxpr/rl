<?php

namespace Drupal\rl\Controller;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\rl\Decorator\ExperimentDecoratorManager;
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
   */
  public function __construct(Connection $database, ExperimentDataStorageInterface $experiment_storage, DateFormatterInterface $date_formatter, ExperimentDecoratorManager $decorator_manager) {
    $this->database = $database;
    $this->experimentStorage = $experiment_storage;
    $this->dateFormatter = $date_formatter;
    $this->decoratorManager = $decorator_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
          $container->get('database'),
          $container->get('rl.experiment_data_storage'),
          $container->get('date.formatter'),
          $container->get('rl.experiment_decorator_manager')
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
      $this->t('Experiment ID'),
      $this->t('Module'),
      $this->t('Total Turns'),
      $this->t('Total Arms'),
      $this->t('Last Activity'),
      $this->t('Operations'),
    ];

    $rows = [];

    // Get all registered experiments with their totals (if any)
    $query = $this->database->select('rl_experiment_registry', 'er')
      ->fields('er', ['uuid', 'module', 'registered_at']);
    $query->leftJoin('rl_experiment_totals', 'et', 'er.uuid = et.experiment_uuid');
    $query->addField('et', 'total_turns', 'total_turns');
    $query->addField('et', 'created', 'totals_created');
    $query->addField('et', 'updated', 'totals_updated');
    $query->orderBy('er.registered_at', 'DESC');
    $experiments = $query->execute()->fetchAll();

    foreach ($experiments as $experiment) {
      // Count arms for this experiment.
      $arms_count = $this->database->select('rl_arm_data', 'ad')
        ->condition('experiment_uuid', $experiment->uuid)
        ->countQuery()
        ->execute()
        ->fetchField();

      $operations = [];

      $detail_url = Url::fromRoute('rl.reports.experiment_detail', [
        'experiment_uuid' => $experiment->uuid,
      ]);
      $operations[] = Link::fromTextAndUrl($this->t('View'), $detail_url);

      if ($this->currentUser()->hasPermission('administer rl experiments')) {
        $edit_url = Url::fromRoute('rl.experiment.edit', [
          'experiment_uuid' => $experiment->uuid,
        ]);
        $operations[] = Link::fromTextAndUrl($this->t('Edit'), $edit_url);

        $delete_url = Url::fromRoute('rl.experiment.delete', [
          'experiment_uuid' => $experiment->uuid,
        ]);
        $operations[] = Link::fromTextAndUrl($this->t('Delete'), $delete_url);
      }

      $operations_markup = implode(' | ', array_map(function ($link) {
        return $link->toString();
      }, $operations));

      // Format last activity timestamp - use totals_updated if available, otherwise registered_at.
      $last_activity_timestamp = $experiment->totals_updated ?: $experiment->registered_at;
      $last_activity = $last_activity_timestamp > 0
            ? $this->dateFormatter->format($last_activity_timestamp, 'short')
            : $this->t('Never');

      // Get decorated experiment name or fallback to UUID.
      $experiment_display = $this->decoratorManager->decorateExperiment($experiment->uuid);
      $experiment_name = $experiment_display ? \Drupal::service('renderer')->renderPlain($experiment_display) : $experiment->uuid;

      $rows[] = [
        $experiment_name,
        $experiment->module,
        $experiment->total_turns ?: 0,
        $arms_count,
        $last_activity,
        ['data' => ['#markup' => $operations_markup]],
      ];
    }

    $build = [];

    if ($this->currentUser()->hasPermission('administer rl experiments')) {
      $add_url = Url::fromRoute('rl.experiment.add');
      $build['add_link'] = [
        '#type' => 'link',
        '#title' => $this->t('Add experiment'),
        '#url' => $add_url,
        '#attributes' => ['class' => ['button', 'button--primary']],
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
    // Get experiment totals.
    $experiment_totals = $this->database->select('rl_experiment_totals', 'et')
      ->fields('et')
      ->condition('experiment_uuid', $experiment_uuid)
      ->execute()
      ->fetchObject();

    if (!$experiment_totals) {
      throw new NotFoundHttpException();
    }

    // Get all arms for this experiment.
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
      $this->t('TS Score'),
    ];

    $rows = [];
    $total_turns = max(1, $experiment_totals->total_turns);

    foreach ($arms as $arm) {
      $success_rate = $arm->turns > 0 ? ($arm->rewards / $arm->turns) * 100 : 0;

      // Calculate Thompson Sampling score.
      $alpha_param = $arm->rewards + 1;
      $beta_param = ($arm->turns - $arm->rewards) + 1;
      // Beta mean as approximation.
      $ts_score = $alpha_param / ($alpha_param + $beta_param);

      // Get decorated arm name or fallback to arm ID.
      $arm_display = $this->decoratorManager->decorateArm($experiment_uuid, $arm->arm_id);
      $arm_name = $arm_display ? \Drupal::service('renderer')->renderPlain($arm_display) : $arm->arm_id;

      $rows[] = [
        $arm_name,
        $arm->turns,
        $arm->rewards,
        number_format($success_rate, 2) . '%',
        number_format($ts_score, 4),
      ];
    }

    $table = [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No arms found for this experiment.'),
      '#caption' => $this->t('All arms in this experiment with their performance data.'),
    ];

    $build = [
      'table' => $table,
    ];

    // Add explanatory text.
    $build['#prefix'] = '<p>' . $this->t('This page shows detailed information about a specific reinforcement learning experiment and all its arms (options being tested).') . '</p>';
    $build['#suffix'] = '<p>' . $this->t('<strong>Terms:</strong><br>• <em>Turns</em>: Number of times this arm was presented/tried<br>• <em>Rewards</em>: Number of times this arm received positive feedback<br>• <em>Success Rate</em>: Percentage of turns that resulted in rewards<br>• <em>TS Score</em>: Thompson Sampling expected success rate (higher = more likely to be selected)<br>• <em>First Seen</em>: When this content was first added to the experiment<br>• <em>Last Updated</em>: When this arm last received activity (turns or rewards)') . '</p>';

    return $build;
  }

}
