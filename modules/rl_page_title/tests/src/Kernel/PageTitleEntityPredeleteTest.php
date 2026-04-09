<?php

namespace Drupal\Tests\rl_page_title\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\rl_page_title\Entity\PageTitleExperiment;

/**
 * Tests hook_entity_predelete cleanup for rl_page_title.
 *
 * @group rl_page_title
 */
class PageTitleEntityPredeleteTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'rl',
    'rl_page_title',
  ];

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
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['rl_page_title', 'filter', 'node']);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * Deleting a node purges the experiment + RL analytics for its path.
   */
  public function testPredeleteCleansUpExperimentAndAnalytics(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Test']);
    $node->save();

    $internal_path = '/node/' . $node->id();
    $experiment = PageTitleExperiment::create([
      'id' => 'pt_test_predelete',
      'label' => 'Test predelete',
      'path' => $internal_path,
      'variants' => ['Alt 1'],
      'enabled' => TRUE,
    ]);
    $experiment->save();

    $rl_id = $experiment->getRlExperimentId();
    $registry = $this->container->get('rl.experiment_registry');
    $registry->register($rl_id, 'rl_page_title', 'Test predelete');
    $manager = $this->container->get('rl.experiment_manager');
    $manager->recordTurn($rl_id, 'v0');

    // Sanity-check the seeds landed.
    $this->assertSame(1, (int) $this->container->get('database')->select('rl_experiment_registry', 'r')
      ->condition('experiment_id', $rl_id)
      ->countQuery()
      ->execute()
      ->fetchField());

    // Delete the node and verify cleanup.
    $node->delete();

    $storage = $this->container->get('entity_type.manager')->getStorage('rl_page_title_experiment');
    $remaining = $storage->loadByProperties(['path' => $internal_path]);
    $this->assertEmpty($remaining, 'Config entity was deleted');

    $count = (int) $this->container->get('database')->select('rl_experiment_registry', 'r')
      ->condition('experiment_id', $rl_id)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(0, $count, 'Registry row was purged');

    $arm_count = (int) $this->container->get('database')->select('rl_arm_data', 'a')
      ->condition('experiment_id', $rl_id)
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(0, $arm_count, 'Arm data was purged');
  }

}
