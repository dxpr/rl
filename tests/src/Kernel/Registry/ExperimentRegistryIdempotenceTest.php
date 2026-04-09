<?php

namespace Drupal\Tests\rl\Kernel\Registry;

use Drupal\KernelTests\KernelTestBase;

/**
 * Verifies ExperimentRegistry::register is idempotent.
 *
 * Both new modules call register() on every experiment save, including no-op
 * edits. The PR claims this is safe because the registry uses MERGE; this
 * test pins that behavior.
 *
 * @group rl
 */
class ExperimentRegistryIdempotenceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['rl'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('rl', ['rl_experiment_registry']);
  }

  /**
   * Tests double registration leaves exactly one row and updates the name.
   */
  public function testRegisterIsIdempotent(): void {
    /** @var \Drupal\rl\Registry\ExperimentRegistryInterface $registry */
    $registry = $this->container->get('rl.experiment_registry');
    /** @var \Drupal\Core\Database\Connection $db */
    $db = $this->container->get('database');

    $eid = 'rl_test-idempotent';

    $registry->register($eid, 'rl', 'First name');
    $count_after_first = (int) $db->select('rl_experiment_registry', 'r')
      ->condition('experiment_id', $eid)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(1, $count_after_first);

    // Re-register with the same data - should not error, should not duplicate.
    $registry->register($eid, 'rl', 'First name');
    $count_after_second = (int) $db->select('rl_experiment_registry', 'r')
      ->condition('experiment_id', $eid)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(1, $count_after_second);

    // Re-register with a new name - should still be one row, name updated.
    $registry->register($eid, 'rl', 'Updated name');
    $rows = $db->select('rl_experiment_registry', 'r')
      ->fields('r')
      ->condition('experiment_id', $eid)
      ->execute()
      ->fetchAll();
    $this->assertCount(1, $rows);
    $this->assertSame('Updated name', $rows[0]->experiment_name);
  }

}
