<?php

namespace Drupal\Tests\rl_menu_link\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\rl_menu_link\Entity\MenuLinkExperiment;

/**
 * Tests MenuLinkVariantSelector::selectForPluginId.
 *
 * @group rl_menu_link
 */
class MenuLinkVariantSelectorTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['rl', 'rl_menu_link'];

  /**
   * The variant selector.
   *
   * @var \Drupal\rl_menu_link\Service\MenuLinkVariantSelector
   */
  protected $selector;

  /**
   * The experiment registry.
   *
   * @var \Drupal\rl\Registry\ExperimentRegistryInterface
   */
  protected $registry;

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
    $this->installConfig(['rl_menu_link']);
    $this->selector = $this->container->get('rl_menu_link.variant_selector');
    $this->registry = $this->container->get('rl.experiment_registry');
  }

  /**
   * No experiment configured -> selector returns NULL.
   */
  public function testSelectForPluginIdReturnsNullWhenNoExperiment(): void {
    $this->assertNull($this->selector->selectForPluginId('system.admin_content'));
  }

  /**
   * A disabled experiment is treated as if it does not exist.
   */
  public function testSelectForPluginIdReturnsNullWhenDisabled(): void {
    $this->createExperiment('system.admin_content', ['Alpha'], FALSE);
    $this->assertNull($this->selector->selectForPluginId('system.admin_content'));
  }

  /**
   * An enabled experiment yields a result.
   */
  public function testSelectForPluginIdReturnsResultWhenEnabled(): void {
    $experiment = $this->createExperiment('system.admin_content', ['Alpha', 'Beta']);
    $this->registry->register($experiment->getRlExperimentId(), 'rl_menu_link', 'Test');

    $result = $this->selector->selectForPluginId('system.admin_content');
    $this->assertNotNull($result);
    $this->assertSame($experiment->getRlExperimentId(), $result['experiment_id']);
    $this->assertContains($result['arm_id'], ['v0', 'v1', 'v2']);
  }

  /**
   * Plugin ID with whitespace is normalized.
   */
  public function testSelectForPluginIdTrimsWhitespace(): void {
    $experiment = $this->createExperiment('system.admin_content', ['Alpha']);
    $this->registry->register($experiment->getRlExperimentId(), 'rl_menu_link', 'Test');

    $this->assertNotNull($this->selector->selectForPluginId('  system.admin_content  '));
  }

  /**
   * Per-request caching: same plugin ID returns same result object.
   */
  public function testSelectForPluginIdIsCached(): void {
    $experiment = $this->createExperiment('system.admin_content', ['Alpha']);
    $this->registry->register($experiment->getRlExperimentId(), 'rl_menu_link', 'Test');

    $first = $this->selector->selectForPluginId('system.admin_content');
    $second = $this->selector->selectForPluginId('system.admin_content');
    $this->assertSame($first, $second);
  }

  /**
   * Helper: create a test experiment.
   */
  protected function createExperiment(string $plugin_id, array $variants, bool $enabled = TRUE): MenuLinkExperiment {
    $experiment = MenuLinkExperiment::create([
      'id' => 'ml_test_' . substr(md5($plugin_id), 0, 8),
      'label' => 'Test ' . $plugin_id,
      'menu_link_plugin_id' => $plugin_id,
      'variants' => $variants,
      'enabled' => $enabled,
    ]);
    $experiment->save();
    return $experiment;
  }

}
