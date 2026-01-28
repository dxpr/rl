<?php

declare(strict_types=1);

namespace Drupal\rl\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\rl\Service\RlAnalyzerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for RL experiment analytics.
 *
 * These commands provide AI-friendly access to experiment data,
 * performance metrics, and actionable insights.
 */
final class RlCommands extends DrushCommands {

  /**
   * Constructs RlCommands.
   *
   * @param \Drupal\rl\Service\RlAnalyzerInterface $analyzer
   *   The RL analyzer service.
   */
  public function __construct(
    protected RlAnalyzerInterface $analyzer,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('rl.analyzer')
    );
  }

  /**
   * List all RL experiments with summary statistics.
   *
   * Returns experiment IDs, names, source modules, arm counts,
   * impression/conversion totals, and current status.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Experiments data.
   */
  #[CLI\Command(name: 'rl:list', aliases: ['rll'])]
  #[CLI\FieldLabels(labels: [
    'id' => 'ID',
    'name' => 'Name',
    'source' => 'Source',
    'status' => 'Status',
    'arms' => 'Arms',
    'impressions' => 'Impressions',
    'conversions' => 'Conversions',
    'conversion_rate' => 'Rate (%)',
  ])]
  #[CLI\DefaultFields(fields: ['id', 'name', 'status', 'arms', 'impressions', 'conversions', 'conversion_rate'])]
  #[CLI\Usage(name: 'drush rl:list', description: 'List all experiments')]
  #[CLI\Usage(name: 'drush rl:list --format=json', description: 'Get experiments as JSON for AI processing')]
  #[CLI\Usage(name: 'drush rl:list --format=table', description: 'Display experiments in table format')]
  public function listExperiments(): RowsOfFields {
    $data = $this->analyzer->listExperiments();
    return new RowsOfFields($data['experiments']);
  }

  /**
   * Get detailed status of a specific experiment.
   *
   * Returns experiment phase (exploration/learning/exploitation),
   * confidence levels, traffic distribution, and value generated
   * compared to equal traffic distribution.
   *
   * @param string $experimentId
   *   The experiment ID (e.g., 'ab_test_button_color').
   * @param array $options
   *   Command options including format.
   *
   * @return array
   *   Detailed experiment status.
   */
  #[CLI\Command(name: 'rl:status', aliases: ['rlst'])]
  #[CLI\Argument(name: 'experimentId', description: 'The experiment ID')]
  #[CLI\Option(name: 'format', description: 'Output format: json, yaml (default: yaml)')]
  #[CLI\Usage(name: 'drush rl:status ab_test_button_color', description: 'Get status of button color test')]
  #[CLI\Usage(name: 'drush rl:status ai_sorting-help_center_categories-block_1 --format=json', description: 'Get detailed status as JSON')]
  public function status(string $experimentId, array $options = ['format' => 'yaml']): array {
    try {
      return $this->analyzer->getStatus($experimentId);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger()->error($e->getMessage());
      throw $e;
    }
  }

  /**
   * Get arm-level performance data with human-readable labels.
   *
   * Returns conversion rates, traffic shares, and comparison to average
   * for each variant. Entity IDs are resolved to titles (e.g., node
   * titles for content experiments).
   *
   * @param string $experimentId
   *   The experiment ID.
   * @param array $options
   *   Command options.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Arm performance data.
   */
  #[CLI\Command(name: 'rl:performance', aliases: ['rlp', 'rl:perf'])]
  #[CLI\Argument(name: 'experimentId', description: 'The experiment ID')]
  #[CLI\Option(name: 'limit', description: 'Maximum arms to return (default: 20)')]
  #[CLI\Option(name: 'sort', description: 'Sort by: rate, impressions, conversions (default: rate)')]
  #[CLI\FieldLabels(labels: [
    'arm_id' => 'Arm ID',
    'label' => 'Label',
    'impressions' => 'Impressions',
    'conversions' => 'Conversions',
    'conversion_rate' => 'Rate (%)',
    'conversion_score' => 'Score (%)',
    'traffic_share_pct' => 'Traffic (%)',
    'vs_average' => 'vs Avg',
    'confidence' => 'Confidence',
  ])]
  #[CLI\DefaultFields(fields: [
    'label', 'impressions', 'conversions',
    'conversion_rate', 'traffic_share_pct', 'vs_average',
  ])]
  #[CLI\Usage(name: 'drush rl:performance mock_10_arm_test', description: 'Get arm performance')]
  #[CLI\Usage(name: 'drush rl:perf ai_sorting-help_center_categories-block_1 --limit=10 --format=json', description: 'Get top 10 performers as JSON')]
  #[CLI\Usage(name: 'drush rl:perf ab_test_headline_variants --sort=impressions', description: 'Sort by traffic volume')]
  public function performance(string $experimentId, array $options = ['limit' => 20, 'sort' => 'rate']): RowsOfFields {
    try {
      $data = $this->analyzer->getPerformance(
        $experimentId,
        (int) $options['limit'],
        $options['sort']
      );
      return new RowsOfFields($data['arms']);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger()->error($e->getMessage());
      throw $e;
    }
  }

  /**
   * Get historical trends for an experiment.
   *
   * Returns conversion rates over time periods with trend analysis.
   * Requires event logging to be enabled for historical data.
   *
   * @param string $experimentId
   *   The experiment ID.
   * @param array $options
   *   Command options.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Trend data.
   */
  #[CLI\Command(name: 'rl:trends', aliases: ['rlt'])]
  #[CLI\Argument(name: 'experimentId', description: 'The experiment ID')]
  #[CLI\Option(name: 'period', description: 'Aggregation period: daily, weekly, monthly (default: weekly)')]
  #[CLI\Option(name: 'periods', description: 'Number of periods to return (default: 8)')]
  #[CLI\FieldLabels(labels: [
    'period' => 'Period',
    'impressions' => 'Impressions',
    'conversions' => 'Conversions',
    'rate' => 'Rate (%)',
    'change_pct' => 'Change (%)',
  ])]
  #[CLI\Usage(name: 'drush rl:trends ab_test_headline_variants', description: 'Get weekly trends')]
  #[CLI\Usage(name: 'drush rl:trends mock_10_arm_test --period=daily --periods=14 --format=json', description: 'Get 14 days of daily data')]
  public function trends(string $experimentId, array $options = ['period' => 'weekly', 'periods' => 8]): RowsOfFields {
    try {
      $data = $this->analyzer->getTrends(
        $experimentId,
        $options['period'],
        (int) $options['periods']
      );
      return new RowsOfFields($data['data']);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger()->error($e->getMessage());
      throw $e;
    }
  }

  /**
   * Export complete experiment data for deep analysis.
   *
   * Returns all arm data with optional historical snapshots.
   * Useful for external analysis tools or AI processing.
   *
   * @param string $experimentId
   *   The experiment ID.
   * @param array $options
   *   Command options.
   *
   * @return array
   *   Complete experiment export.
   */
  #[CLI\Command(name: 'rl:export', aliases: ['rle'])]
  #[CLI\Argument(name: 'experimentId', description: 'The experiment ID')]
  #[CLI\Option(name: 'snapshots', description: 'Include historical snapshots')]
  #[CLI\Option(name: 'format', description: 'Output format: json, yaml (default: json)')]
  #[CLI\Usage(name: 'drush rl:export ab_test_button_color', description: 'Export experiment data as JSON')]
  #[CLI\Usage(name: 'drush rl:export mock_10_arm_test --snapshots > export.json', description: 'Export with snapshots to file')]
  public function export(string $experimentId, array $options = ['snapshots' => FALSE, 'format' => 'json']): array {
    try {
      return $this->analyzer->export(
        $experimentId,
        (bool) $options['snapshots']
      );
    }
    catch (\InvalidArgumentException $e) {
      $this->logger()->error($e->getMessage());
      throw $e;
    }
  }

  /**
   * Get full analysis with insights (wrapper combining status + performance).
   *
   * Returns comprehensive analysis including status, top performers,
   * and actionable recommendations. Optimized for AI consumption.
   *
   * @param string $experimentId
   *   The experiment ID.
   * @param array $options
   *   Command options including format.
   *
   * @return array
   *   Full analysis data.
   */
  #[CLI\Command(name: 'rl:analyze', aliases: ['rla'])]
  #[CLI\Argument(name: 'experimentId', description: 'The experiment ID')]
  #[CLI\Option(name: 'format', description: 'Output format: json, yaml (default: yaml)')]
  #[CLI\Usage(name: 'drush rl:analyze ab_test_button_color', description: 'Get full analysis with recommendations')]
  #[CLI\Usage(name: 'drush rl:analyze mock_10_arm_test --format=json', description: 'Get analysis as JSON')]
  public function analyze(string $experimentId, array $options = ['format' => 'yaml']): array {
    try {
      $status = $this->analyzer->getStatus($experimentId);
      $performance = $this->analyzer->getPerformance($experimentId, 10);

      return [
        'experiment' => $status['experiment'],
        'status' => $status['status'],
        'summary' => $status['summary'],
        'value_generated' => $status['value_generated'],
        'top_performers' => array_slice($performance['arms'], 0, 5),
        'insights' => $performance['insights'],
        'recommendation' => $this->generateRecommendation($status, $performance),
      ];
    }
    catch (\InvalidArgumentException $e) {
      $this->logger()->error($e->getMessage());
      throw $e;
    }
  }

  /**
   * Generates a human-readable recommendation based on experiment data.
   *
   * @param array $status
   *   Status data.
   * @param array $performance
   *   Performance data.
   *
   * @return string
   *   Recommendation text.
   */
  protected function generateRecommendation(array $status, array $performance): string {
    $confidence = $status['status']['top_performer_confidence'] ?? 0;
    $phase = $status['status']['phase'] ?? 'exploration';
    $additionalConversions = $status['value_generated']['additional_conversions_from_optimization'] ?? 0;

    if ($confidence > 0.95) {
      $winner = $performance['arms'][0]['label'] ?? 'top performer';
      return "Experiment is conclusive. Consider implementing '{$winner}' as the default. " .
        "Thompson Sampling has already generated {$additionalConversions} additional conversions.";
    }

    if ($confidence > 0.8) {
      return "Strong signal emerging. Continue monitoring for 1-2 more weeks to confirm. " .
        "Current optimization has generated {$additionalConversions} additional conversions.";
    }

    if ($phase === 'learning') {
      return "Learning in progress. The system is identifying promising variants. " .
        "Allow more time for data collection.";
    }

    return "Exploration phase. Continue collecting data to identify performance patterns.";
  }

}
