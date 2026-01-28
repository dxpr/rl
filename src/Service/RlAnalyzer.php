<?php

declare(strict_types=1);

namespace Drupal\rl\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service for analyzing RL experiment data.
 *
 * Provides methods to retrieve experiment performance, trends, and insights
 * with human-readable labels and pre-computed analytics.
 */
class RlAnalyzer implements RlAnalyzerInterface {

  /**
   * Constructs a new RlAnalyzer.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function listExperiments(): array {
    $query = $this->database->select('rl_experiment_registry', 'e');
    $query->leftJoin('rl_arm_data', 'a', 'a.experiment_id = e.experiment_id');
    $query->fields('e', ['experiment_id', 'experiment_name', 'module', 'registered_at']);
    $query->addExpression('COUNT(DISTINCT a.arm_id)', 'arms');
    $query->addExpression('COALESCE(SUM(a.turns), 0)', 'impressions');
    $query->addExpression('COALESCE(SUM(a.rewards), 0)', 'conversions');
    $query->addExpression('MAX(a.updated)', 'last_activity');
    $query->groupBy('e.experiment_id');
    $query->groupBy('e.experiment_name');
    $query->groupBy('e.module');
    $query->groupBy('e.registered_at');
    $query->orderBy('impressions', 'DESC');

    $results = $query->execute()->fetchAll();
    $experiments = [];

    foreach ($results as $row) {
      $impressions = (int) $row->impressions;
      $conversions = (int) $row->conversions;
      $rate = $impressions > 0 ? round($conversions * 100 / $impressions, 2) : 0;

      // Determine experiment status.
      $status = $this->determineExperimentStatus($row->experiment_id, $impressions);

      $experiments[] = [
        'id' => $row->experiment_id,
        'name' => $row->experiment_name ?: $row->experiment_id,
        'source' => $row->module,
        'status' => $status['status'],
        'arms' => (int) $row->arms,
        'impressions' => $impressions,
        'conversions' => $conversions,
        'conversion_rate' => $rate,
        'started' => date('Y-m-d', (int) $row->registered_at),
        'last_activity' => $row->last_activity ? date('Y-m-d H:i', (int) $row->last_activity) : NULL,
      ];
    }

    return ['experiments' => $experiments];
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(string $experimentId): array {
    $experiment = $this->getExperimentOrFail($experimentId);
    $arms = $this->getArmsData($experimentId);

    $totalImpressions = array_sum(array_column($arms, 'turns'));
    $totalConversions = array_sum(array_column($arms, 'rewards'));
    $armsWithData = count(array_filter($arms, fn($a) => $a['turns'] > 0));
    $armsWithConversions = count(array_filter($arms, fn($a) => $a['rewards'] > 0));

    // Calculate average rate for equal distribution comparison.
    $rates = array_filter(array_map(function ($a) {
      return $a['turns'] > 0 ? $a['rewards'] / $a['turns'] : NULL;
    }, $arms));
    $avgRate = count($rates) > 0 ? array_sum($rates) / count($rates) : 0;
    $actualRate = $totalImpressions > 0 ? $totalConversions / $totalImpressions : 0;

    // Estimate conversions under equal distribution.
    $equalDistConversions = round($totalImpressions * $avgRate);
    $additionalConversions = $totalConversions - $equalDistConversions;

    // Get status determination.
    $statusInfo = $this->determineExperimentStatus($experimentId, $totalImpressions);

    // Calculate traffic distribution metrics.
    $sortedArms = $arms;
    usort($sortedArms, fn($a, $b) => $b['turns'] <=> $a['turns']);
    $top10Count = max(1, (int) ceil(count($arms) * 0.1));
    $top10Arms = array_slice($sortedArms, 0, $top10Count);
    $top10Traffic = array_sum(array_column($top10Arms, 'turns'));
    $top10Pct = $totalImpressions > 0 ? round($top10Traffic * 100 / $totalImpressions, 1) : 0;

    return [
      'experiment' => [
        'id' => $experimentId,
        'name' => $experiment->experiment_name ?: $experimentId,
        'source' => $experiment->module,
        'started' => date('Y-m-d', (int) $experiment->registered_at),
        'days_running' => (int) ((time() - $experiment->registered_at) / 86400),
      ],
      'status' => [
        'phase' => $statusInfo['phase'],
        'is_conclusive' => $statusInfo['status'] === 'conclusive',
        'top_performer_confidence' => $statusInfo['confidence'],
        'recommendation' => $statusInfo['recommendation'],
      ],
      'summary' => [
        'total_arms' => count($arms),
        'arms_with_data' => $armsWithData,
        'arms_with_conversions' => $armsWithConversions,
        'total_impressions' => $totalImpressions,
        'total_conversions' => $totalConversions,
        'overall_rate' => round($actualRate * 100, 2),
      ],
      'distribution' => [
        'top_10_pct_arms_traffic_share' => $top10Pct,
        'exploitation_ratio' => $top10Pct > 50 ? 'high' : ($top10Pct > 30 ? 'medium' : 'low'),
      ],
      'value_generated' => [
        'actual_conversions' => $totalConversions,
        'estimated_equal_distribution_conversions' => (int) $equalDistConversions,
        'additional_conversions_from_optimization' => max(0, (int) $additionalConversions),
        'improvement_pct' => $equalDistConversions > 0
          ? round(($totalConversions - $equalDistConversions) * 100 / $equalDistConversions, 1)
          : 0,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getPerformance(string $experimentId, int $limit = 20, string $sortBy = 'rate'): array {
    $this->getExperimentOrFail($experimentId);
    $arms = $this->getArmsData($experimentId);

    // Calculate overall average rate.
    $totalImpressions = array_sum(array_column($arms, 'turns'));
    $totalConversions = array_sum(array_column($arms, 'rewards'));
    $avgRate = $totalImpressions > 0 ? $totalConversions / $totalImpressions : 0;

    // Enrich arm data with labels and computed fields.
    $enrichedArms = [];
    foreach ($arms as $arm) {
      $impressions = (int) $arm['turns'];
      $conversions = (int) $arm['rewards'];
      $rate = $impressions > 0 ? $conversions / $impressions : 0;
      $score = ($conversions + 1) / ($impressions + 2);

      // Calculate vs average.
      $vsAvg = $avgRate > 0 ? round(($rate - $avgRate) * 100 / $avgRate, 1) : 0;
      $vsAvgFormatted = $vsAvg >= 0 ? "+{$vsAvg}%" : "{$vsAvg}%";

      // Traffic share.
      $trafficShare = $totalImpressions > 0
        ? round($impressions * 100 / $totalImpressions, 1)
        : 0;

      // Estimate confidence using beta distribution approximation.
      $confidence = $this->estimateConfidence($conversions, $impressions);

      $enrichedArms[] = [
        'arm_id' => $arm['arm_id'],
        'label' => $this->resolveArmLabel($arm['arm_id'], $experimentId),
        'impressions' => $impressions,
        'conversions' => $conversions,
        'conversion_rate' => round($rate * 100, 2),
        'conversion_score' => round($score * 100, 2),
        'traffic_share_pct' => $trafficShare,
        'vs_average' => $vsAvgFormatted,
        'confidence' => $confidence,
      ];
    }

    // Sort by requested field.
    $sortField = match ($sortBy) {
      'impressions' => 'impressions',
      'conversions' => 'conversions',
      default => 'conversion_rate',
    };
    usort($enrichedArms, fn($a, $b) => $b[$sortField] <=> $a[$sortField]);

    // Limit results.
    $limitedArms = array_slice($enrichedArms, 0, $limit);

    // Generate insights.
    $topPerformers = array_filter($enrichedArms, fn($a) =>
      $a['impressions'] >= 50 && $a['conversion_rate'] > $avgRate * 100 * 1.5
    );
    $zeroConversions = count(array_filter($enrichedArms, fn($a) => $a['conversions'] === 0));

    return [
      'experiment_id' => $experimentId,
      'total_arms' => count($arms),
      'showing' => count($limitedArms),
      'average_rate' => round($avgRate * 100, 2),
      'arms' => $limitedArms,
      'insights' => [
        'top_performers_count' => count($topPerformers),
        'zero_conversion_arms' => $zeroConversions,
        'data_quality' => $zeroConversions > count($arms) * 0.8 ? 'sparse' : 'adequate',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getTrends(string $experimentId, string $period = 'weekly', int $periods = 8): array {
    $this->getExperimentOrFail($experimentId);

    // Determine date grouping format.
    $dateFormat = match ($period) {
      'daily' => '%Y-%m-%d',
      'monthly' => '%Y-%m',
      default => '%Y-%u',
    };

    // Query snapshots for trend data.
    $query = $this->database->select('rl_arm_snapshots', 's');
    $query->condition('s.experiment_id', $experimentId);
    $query->addExpression("DATE_FORMAT(FROM_UNIXTIME(s.created), '{$dateFormat}')", 'period_key');
    $query->addExpression('SUM(s.turns)', 'impressions');
    $query->addExpression('SUM(s.rewards)', 'conversions');
    $query->addExpression('MAX(s.created)', 'period_end');
    $query->groupBy('period_key');
    $query->orderBy('period_key', 'DESC');
    $query->range(0, $periods);

    $results = $query->execute()->fetchAll();

    if (empty($results)) {
      // Fall back to current arm data if no snapshots.
      return $this->getTrendsFallback($experimentId, $period, $periods);
    }

    // Reverse to chronological order.
    $results = array_reverse($results);

    $data = [];
    $prevRate = NULL;
    foreach ($results as $row) {
      $impressions = (int) $row->impressions;
      $conversions = (int) $row->conversions;
      $rate = $impressions > 0 ? round($conversions * 100 / $impressions, 2) : 0;

      $change = $prevRate !== NULL && $prevRate > 0
        ? round(($rate - $prevRate) * 100 / $prevRate, 1)
        : NULL;

      $data[] = [
        'period' => $row->period_key,
        'impressions' => $impressions,
        'conversions' => $conversions,
        'rate' => $rate,
        'change_pct' => $change,
      ];

      $prevRate = $rate;
    }

    // Calculate trend direction.
    $rates = array_column($data, 'rate');
    $firstHalf = array_slice($rates, 0, (int) ceil(count($rates) / 2));
    $secondHalf = array_slice($rates, (int) ceil(count($rates) / 2));
    $firstAvg = count($firstHalf) > 0 ? array_sum($firstHalf) / count($firstHalf) : 0;
    $secondAvg = count($secondHalf) > 0 ? array_sum($secondHalf) / count($secondHalf) : 0;

    $trendDirection = 'stable';
    if ($secondAvg > $firstAvg * 1.1) {
      $trendDirection = 'improving';
    }
    elseif ($secondAvg < $firstAvg * 0.9) {
      $trendDirection = 'declining';
    }

    return [
      'experiment_id' => $experimentId,
      'period' => $period,
      'periods_returned' => count($data),
      'data' => $data,
      'analysis' => [
        'trend_direction' => $trendDirection,
        'first_period_rate' => $data[0]['rate'] ?? 0,
        'last_period_rate' => end($data)['rate'] ?? 0,
        'overall_change_pct' => count($data) >= 2
          ? round((end($data)['rate'] - $data[0]['rate']) * 100 / max(0.01, $data[0]['rate']), 1)
          : 0,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function export(string $experimentId, bool $includeSnapshots = FALSE): array {
    $experiment = $this->getExperimentOrFail($experimentId);
    $arms = $this->getArmsData($experimentId);

    $export = [
      'experiment' => [
        'id' => $experimentId,
        'name' => $experiment->experiment_name ?: $experimentId,
        'source' => $experiment->module,
        'registered_at' => (int) $experiment->registered_at,
        'registered_date' => date('Y-m-d H:i:s', (int) $experiment->registered_at),
      ],
      'arms' => [],
      'exported_at' => date('Y-m-d H:i:s'),
    ];

    foreach ($arms as $arm) {
      $armData = [
        'arm_id' => $arm['arm_id'],
        'label' => $this->resolveArmLabel($arm['arm_id'], $experimentId),
        'turns' => (int) $arm['turns'],
        'rewards' => (int) $arm['rewards'],
        'conversion_rate' => $arm['turns'] > 0
          ? round($arm['rewards'] * 100 / $arm['turns'], 4)
          : 0,
        'conversion_score' => round(($arm['rewards'] + 1) * 100 / ($arm['turns'] + 2), 4),
        'created' => (int) $arm['created'],
        'updated' => (int) $arm['updated'],
      ];

      if ($includeSnapshots) {
        $armData['snapshots'] = $this->getArmSnapshots($experimentId, $arm['arm_id']);
      }

      $export['arms'][] = $armData;
    }

    // Sort arms by conversion rate descending.
    usort($export['arms'], fn($a, $b) => $b['conversion_rate'] <=> $a['conversion_rate']);

    return $export;
  }

  /**
   * Gets experiment record or throws exception.
   *
   * @param string $experimentId
   *   The experiment ID.
   *
   * @return object
   *   The experiment record.
   *
   * @throws \InvalidArgumentException
   *   If experiment not found.
   */
  protected function getExperimentOrFail(string $experimentId): object {
    $experiment = $this->database->select('rl_experiment_registry', 'e')
      ->fields('e')
      ->condition('experiment_id', $experimentId)
      ->execute()
      ->fetchObject();

    if (!$experiment) {
      throw new \InvalidArgumentException("Experiment '{$experimentId}' not found.");
    }

    return $experiment;
  }

  /**
   * Gets all arms data for an experiment.
   *
   * @param string $experimentId
   *   The experiment ID.
   *
   * @return array
   *   Array of arm data.
   */
  protected function getArmsData(string $experimentId): array {
    return $this->database->select('rl_arm_data', 'a')
      ->fields('a')
      ->condition('experiment_id', $experimentId)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Gets snapshots for a specific arm.
   *
   * @param string $experimentId
   *   The experiment ID.
   * @param string $armId
   *   The arm ID.
   *
   * @return array
   *   Array of snapshot data.
   */
  protected function getArmSnapshots(string $experimentId, string $armId): array {
    $results = $this->database->select('rl_arm_snapshots', 's')
      ->fields('s', ['turns', 'rewards', 'total_experiment_turns', 'created', 'is_milestone'])
      ->condition('experiment_id', $experimentId)
      ->condition('arm_id', $armId)
      ->orderBy('created', 'ASC')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    return array_map(function ($row) {
      return [
        'turns' => (int) $row['turns'],
        'rewards' => (int) $row['rewards'],
        'total_experiment_turns' => (int) $row['total_experiment_turns'],
        'created' => (int) $row['created'],
        'is_milestone' => (bool) $row['is_milestone'],
      ];
    }, $results);
  }

  /**
   * Resolves an arm ID to a human-readable label.
   *
   * @param string $armId
   *   The arm ID.
   * @param string $experimentId
   *   The experiment ID (for context).
   *
   * @return string
   *   Human-readable label or the original arm ID.
   */
  protected function resolveArmLabel(string $armId, string $experimentId): string {
    // If arm_id is numeric, try to resolve as node title.
    if (is_numeric($armId)) {
      try {
        $node = $this->entityTypeManager->getStorage('node')->load((int) $armId);
        if ($node) {
          return $node->label();
        }
      }
      catch (\Exception $e) {
        // Fall through to return arm_id.
      }
    }

    return $armId;
  }

  /**
   * Determines experiment status and phase.
   *
   * @param string $experimentId
   *   The experiment ID.
   * @param int $totalImpressions
   *   Total impressions.
   *
   * @return array
   *   Status info with keys: status, phase, confidence, recommendation.
   */
  protected function determineExperimentStatus(string $experimentId, int $totalImpressions): array {
    if ($totalImpressions < 100) {
      return [
        'status' => 'insufficient_data',
        'phase' => 'exploration',
        'confidence' => 0,
        'recommendation' => 'Continue collecting data',
      ];
    }

    // Get top 2 performers.
    $query = $this->database->select('rl_arm_data', 'a');
    $query->fields('a', ['arm_id', 'turns', 'rewards']);
    $query->condition('experiment_id', $experimentId);
    $query->condition('turns', 50, '>=');
    $query->orderBy('rewards', 'DESC');
    $query->range(0, 2);
    $topArms = $query->execute()->fetchAll();

    if (count($topArms) < 2) {
      return [
        'status' => 'active',
        'phase' => 'exploration',
        'confidence' => 0,
        'recommendation' => 'Continue collecting data',
      ];
    }

    // Calculate confidence that top arm is better than second.
    $top = $topArms[0];
    $second = $topArms[1];

    $topRate = $top->turns > 0 ? $top->rewards / $top->turns : 0;
    $secondRate = $second->turns > 0 ? $second->rewards / $second->turns : 0;

    // Simple confidence estimation based on sample sizes and rate difference.
    $pooledRate = ($top->rewards + $second->rewards) / ($top->turns + $second->turns);
    $se = sqrt($pooledRate * (1 - $pooledRate) * (1 / $top->turns + 1 / $second->turns));
    $zScore = $se > 0 ? abs($topRate - $secondRate) / $se : 0;

    // Convert z-score to approximate confidence.
    $confidence = min(0.99, 0.5 + 0.5 * (1 - exp(-$zScore * $zScore / 2)));

    $phase = 'exploration';
    if ($confidence > 0.8) {
      $phase = 'exploitation';
    }
    elseif ($confidence > 0.5) {
      $phase = 'learning';
    }

    $status = $confidence > 0.95 ? 'conclusive' : 'active';

    $recommendation = match (TRUE) {
      $confidence > 0.95 => 'Ready to conclude - implement winner',
      $confidence > 0.8 => 'Strong signal - continue monitoring',
      $confidence > 0.5 => 'Learning in progress',
      default => 'Continue collecting data',
    };

    return [
      'status' => $status,
      'phase' => $phase,
      'confidence' => round($confidence, 2),
      'recommendation' => $recommendation,
    ];
  }

  /**
   * Estimates statistical confidence for an arm.
   *
   * @param int $conversions
   *   Number of conversions.
   * @param int $impressions
   *   Number of impressions.
   *
   * @return float
   *   Confidence estimate (0-1).
   */
  protected function estimateConfidence(int $conversions, int $impressions): float {
    if ($impressions < 30) {
      return 0;
    }

    // Use Wilson score interval width as proxy for confidence.
    $p = $conversions / $impressions;
    $z = 1.96;
    $denominator = 1 + $z * $z / $impressions;
    $center = ($p + $z * $z / (2 * $impressions)) / $denominator;
    $spread = $z * sqrt(($p * (1 - $p) + $z * $z / (4 * $impressions)) / $impressions) / $denominator;

    // Narrower interval = higher confidence.
    $intervalWidth = 2 * $spread;
    $confidence = max(0, min(1, 1 - $intervalWidth * 2));

    return round($confidence, 2);
  }

  /**
   * Fallback trends when no snapshots available.
   *
   * @param string $experimentId
   *   The experiment ID.
   * @param string $period
   *   The period type.
   * @param int $periods
   *   Number of periods.
   *
   * @return array
   *   Trends data with current data only.
   */
  protected function getTrendsFallback(string $experimentId, string $period, int $periods): array {
    $arms = $this->getArmsData($experimentId);
    $totalImpressions = array_sum(array_column($arms, 'turns'));
    $totalConversions = array_sum(array_column($arms, 'rewards'));
    $rate = $totalImpressions > 0 ? round($totalConversions * 100 / $totalImpressions, 2) : 0;

    return [
      'experiment_id' => $experimentId,
      'period' => $period,
      'periods_returned' => 1,
      'data' => [
        [
          'period' => 'current',
          'impressions' => $totalImpressions,
          'conversions' => $totalConversions,
          'rate' => $rate,
          'change_pct' => NULL,
        ],
      ],
      'analysis' => [
        'trend_direction' => 'insufficient_data',
        'first_period_rate' => $rate,
        'last_period_rate' => $rate,
        'overall_change_pct' => 0,
        'note' => 'Historical snapshots not available. Enable event logging for trend analysis.',
      ],
    ];
  }

}
