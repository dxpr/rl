<?php

namespace Drupal\Tests\rl_menu_link\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\rl_menu_link\Entity\MenuLinkExperiment;

/**
 * Tests MenuLinkDecorator output.
 *
 * @group rl_menu_link
 */
class MenuLinkDecoratorTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'rl', 'rl_menu_link'];

  /**
   * The decorator service.
   *
   * @var \Drupal\rl_menu_link\Decorator\MenuLinkDecorator
   */
  protected $decorator;

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
    $this->decorator = $this->container->get('rl_menu_link.decorator');
  }

  /**
   * Decorator returns NULL for unknown experiment ID prefixes.
   */
  public function testDecorateExperimentReturnsNullForOtherPrefix(): void {
    $this->assertNull($this->decorator->decorateExperiment('rl_page_title-abc123'));
    $this->assertNull($this->decorator->decorateExperiment('something-else'));
  }

  /**
   * Tests that v0 yields the original placeholder.
   */
  public function testDecorateArmV0(): void {
    $experiment = $this->createExperiment('system.admin_content', ['Alpha']);
    $result = $this->decorator->decorateArm($experiment->getRlExperimentId(), 'v0');
    $this->assertIsArray($result);
  }

  /**
   * Tests that v1 yields the first stored variant.
   */
  public function testDecorateArmV1(): void {
    $experiment = $this->createExperiment('system.admin_content', ['Alpha', 'Beta']);
    $result = $this->decorator->decorateArm($experiment->getRlExperimentId(), 'v1');
    $rendered = (string) $this->container->get('renderer')->renderInIsolation($result);
    $this->assertStringContainsString('Alpha', $rendered);
  }

  /**
   * Decorator returns NULL for unknown hash.
   */
  public function testDecorateExperimentReturnsNullForUnknownHash(): void {
    $this->assertNull($this->decorator->decorateExperiment('rl_menu_link-deadbeefcafe'));
  }

  /**
   * Helper: create a test experiment.
   */
  protected function createExperiment(string $plugin_id, array $variants): MenuLinkExperiment {
    $experiment = MenuLinkExperiment::create([
      'id' => 'ml_test_' . substr(md5($plugin_id . serialize($variants)), 0, 8),
      'label' => 'Test ' . $plugin_id,
      'menu_link_plugin_id' => $plugin_id,
      'variants' => $variants,
      'enabled' => TRUE,
    ]);
    $experiment->save();
    return $experiment;
  }

}
