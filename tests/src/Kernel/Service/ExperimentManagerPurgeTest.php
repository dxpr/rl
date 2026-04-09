<?php

namespace Drupal\Tests\rl\Kernel\Service;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests ExperimentManager::purgeExperiment.
 *
 * @group rl
 */
class ExperimentManagerPurgeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['rl'];

  /**
   * The experiment manager service.
   *
   * @var \Drupal\rl\Service\ExperimentManagerInterface
   */
  protected $manager;

  /**
   * The experiment registry service.
   *
   * @var \Drupal\rl\Registry\ExperimentRegistryInterface
   */
  protected $registry;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('rl', [
      'rl_experiment_totals',
      'rl_arm_data',
      'rl_experiment_registry',
      'rl_arm_snapshots',
    ]);
    $this->manager = $this->container->get('rl.experiment_manager');
    $this->registry = $this->container->get('rl.experiment_registry');
    $this->database = $this->container->get('database');
  }

  /**
   * Tests purgeExperiment removes data from all four tables.
   */
  public function testPurgeExperimentRemovesAllTables(): void {
    $eid = 'rl_test-purgeall';

    // Seed each table.
    $this->registry->register($eid, 'rl', 'Test purge all');
    $this->manager->recordTurns($eid, ['v0', 'v1']);
    $this->manager->recordReward($eid, 'v1');

    // Sanity-check the seeds landed.
    $this->assertGreaterThan(0, $this->countRows('rl_arm_data', $eid));
    $this->assertGreaterThan(0, $this->countRows('rl_experiment_totals', $eid));
    $this->assertGreaterThan(0, $this->countRows('rl_experiment_registry', $eid));

    $this->manager->purgeExperiment($eid);

    $this->assertSame(0, $this->countRows('rl_arm_data', $eid));
    $this->assertSame(0, $this->countRows('rl_experiment_totals', $eid));
    $this->assertSame(0, $this->countRows('rl_experiment_registry', $eid));
    $this->assertSame(0, $this->countRows('rl_arm_snapshots', $eid));
  }

  /**
   * Tests purgeExperiment of a non-existent experiment is a no-op.
   */
  public function testPurgeExperimentNoop(): void {
    // Should not throw, should not affect any rows.
    $this->manager->purgeExperiment('rl_test-nonexistent');
    $this->assertSame(0, $this->countRows('rl_arm_data', 'rl_test-nonexistent'));
  }

  /**
   * Tests purgeExperiment leaves OTHER experiments alone.
   */
  public function testPurgeExperimentScoped(): void {
    $a = 'rl_test-keep';
    $b = 'rl_test-purge';

    $this->registry->register($a, 'rl', 'Keep');
    $this->registry->register($b, 'rl', 'Purge');
    $this->manager->recordTurn($a, 'v0');
    $this->manager->recordTurn($b, 'v0');

    $this->manager->purgeExperiment($b);

    $this->assertGreaterThan(0, $this->countRows('rl_arm_data', $a));
    $this->assertGreaterThan(0, $this->countRows('rl_experiment_registry', $a));
    $this->assertSame(0, $this->countRows('rl_arm_data', $b));
    $this->assertSame(0, $this->countRows('rl_experiment_registry', $b));
  }

  /**
   * Helper: count rows in $table for $experiment_id.
   */
  protected function countRows(string $table, string $experiment_id): int {
    return (int) $this->database->select($table, 't')
      ->condition('experiment_id', $experiment_id)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
