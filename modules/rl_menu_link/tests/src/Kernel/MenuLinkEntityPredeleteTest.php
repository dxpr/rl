<?php

namespace Drupal\Tests\rl_menu_link\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\rl_menu_link\Entity\MenuLinkExperiment;

/**
 * Tests hook_entity_predelete cleanup for rl_menu_link.
 *
 * @group rl_menu_link
 */
class MenuLinkEntityPredeleteTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'link',
    'menu_link_content',
    'rl',
    'rl_menu_link',
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
    $this->installEntitySchema('user');
    $this->installEntitySchema('menu_link_content');
    // Installing 'system' config already provides the default menu config
    // entities (main, admin, footer, account, tools), so we don't need to
    // create the 'main' menu manually.
    $this->installConfig(['rl_menu_link', 'system']);
  }

  /**
   * Deleting a menu link content purges the experiment + RL analytics.
   */
  public function testPredeleteCleansUpExperimentAndAnalytics(): void {
    $link = MenuLinkContent::create([
      'title' => 'About',
      'menu_name' => 'main',
      'link' => ['uri' => 'internal:/about'],
    ]);
    $link->save();

    $plugin_id = $link->getPluginId();
    $experiment = MenuLinkExperiment::create([
      'id' => 'ml_test_predelete',
      'label' => 'Test predelete',
      'menu_link_plugin_id' => $plugin_id,
      'variants' => ['Alt'],
      'enabled' => TRUE,
    ]);
    $experiment->save();

    $rl_id = $experiment->getRlExperimentId();
    $registry = $this->container->get('rl.experiment_registry');
    $registry->register($rl_id, 'rl_menu_link', 'Test predelete');
    $manager = $this->container->get('rl.experiment_manager');
    $manager->recordTurn($rl_id, 'v0');

    $this->assertSame(1, (int) $this->container->get('database')->select('rl_experiment_registry', 'r')
      ->condition('experiment_id', $rl_id)
      ->countQuery()
      ->execute()
      ->fetchField());

    $link->delete();

    $storage = $this->container->get('entity_type.manager')->getStorage('rl_menu_link_experiment');
    $this->assertEmpty($storage->loadByProperties(['menu_link_plugin_id' => $plugin_id]));

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
