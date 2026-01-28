<?php

declare(strict_types=1);

namespace Drupal\rl\Service;

/**
 * Interface for RL experiment analytics service.
 *
 * Provides methods to analyze reinforcement learning experiments,
 * retrieve performance data, and generate insights. This service
 * can be used by Drush commands, other modules, or REST endpoints.
 *
 * @package Drupal\rl\Service
 */
interface RlAnalyzerInterface {

  /**
   * Lists all experiments with summary statistics.
   *
   * @return array
   *   Array of experiments, each containing:
   *   - id: Experiment ID
   *   - name: Human-readable name
   *   - source: Source module (ai_sorting, rl_demo, etc.)
   *   - status: active, conclusive, or insufficient_data
   *   - arms: Number of arms/variants
   *   - impressions: Total impressions
   *   - conversions: Total conversions
   *   - conversion_rate: Overall conversion rate percentage
   *   - started: Start date (Y-m-d format)
   *   - last_activity: Last activity timestamp
   */
  public function listExperiments(): array;

  /**
   * Gets detailed status for a single experiment.
   *
   * @param string $experimentId
   *   The experiment ID.
   *
   * @return array
   *   Detailed experiment status including:
   *   - experiment: Basic experiment info
   *   - status: Phase, confidence, conclusiveness
   *   - summary: Aggregated statistics
   *   - distribution: Traffic allocation info
   *   - value_generated: Comparison vs equal distribution
   *
   * @throws \Drupal\rl\Exception\ExperimentNotFoundException
   *   If experiment not found.
   */
  public function getStatus(string $experimentId): array;

  /**
   * Gets arm-level performance data with human-readable labels.
   *
   * @param string $experimentId
   *   The experiment ID.
   * @param int $limit
   *   Maximum number of arms to return (default 20).
   * @param string $sortBy
   *   Sort field: 'rate', 'impressions', or 'conversions' (default 'rate').
   *
   * @return array
   *   Array containing:
   *   - experiment_id: The experiment ID
   *   - arms: Array of arm data with labels, stats, and insights
   *   - summary: Aggregate insights about top/bottom performers
   *
   * @throws \Drupal\rl\Exception\ExperimentNotFoundException
   *   If experiment not found.
   */
  public function getPerformance(string $experimentId, int $limit = 20, string $sortBy = 'rate'): array;

  /**
   * Gets historical trend data for an experiment.
   *
   * @param string $experimentId
   *   The experiment ID.
   * @param string $period
   *   Aggregation period: 'daily', 'weekly', or 'monthly' (default 'weekly').
   * @param int $periods
   *   Number of periods to return (default 8).
   *
   * @return array
   *   Array containing:
   *   - experiment_id: The experiment ID
   *   - period: The aggregation period used
   *   - data: Array of period data with impressions, conversions, rates
   *   - analysis: Trend analysis (direction, anomalies)
   *
   * @throws \Drupal\rl\Exception\ExperimentNotFoundException
   *   If experiment not found.
   */
  public function getTrends(string $experimentId, string $period = 'weekly', int $periods = 8): array;

  /**
   * Exports full experiment data for deep analysis.
   *
   * @param string $experimentId
   *   The experiment ID.
   * @param bool $includeSnapshots
   *   Whether to include historical snapshots (default FALSE).
   *
   * @return array
   *   Complete experiment data including all arms, metadata, and optionally
   *   historical snapshots.
   *
   * @throws \Drupal\rl\Exception\ExperimentNotFoundException
   *   If experiment not found.
   */
  public function export(string $experimentId, bool $includeSnapshots = FALSE): array;

}
