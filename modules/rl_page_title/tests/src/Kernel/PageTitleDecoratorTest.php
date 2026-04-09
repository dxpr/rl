<?php

namespace Drupal\Tests\rl_page_title\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\rl_page_title\Entity\PageTitleExperiment;

/**
 * Tests PageTitleDecorator output.
 *
 * @group rl_page_title
 */
class PageTitleDecoratorTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['rl', 'rl_page_title'];

  /**
   * The decorator service.
   *
   * @var \Drupal\rl_page_title\Decorator\PageTitleDecorator
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
    $this->installConfig(['rl_page_title']);
    $this->decorator = $this->container->get('rl_page_title.decorator');
  }

  /**
   * Decorator returns NULL for unknown experiment ID prefixes.
   */
  public function testDecorateExperimentReturnsNullForOtherPrefix(): void {
    $this->assertNull($this->decorator->decorateExperiment('rl_menu_link-abc123'));
    $this->assertNull($this->decorator->decorateExperiment('something-else'));
  }

  /**
   * Decorator returns NULL when no experiment matches the hashed ID.
   */
  public function testDecorateExperimentReturnsNullForUnknownHash(): void {
    $this->assertNull($this->decorator->decorateExperiment('rl_page_title-deadbeefcafe'));
  }

  /**
   * Decorator returns a render array for a known experiment.
   */
  public function testDecorateExperimentReturnsRenderArray(): void {
    $experiment = $this->createExperiment('/blog', ['Alt']);
    $result = $this->decorator->decorateExperiment($experiment->getRlExperimentId());
    $this->assertIsArray($result);
    $this->assertArrayHasKey('#type', $result);
    $this->assertSame('inline_template', $result['#type']);
  }

  /**
   * Tests that v0 yields the "(original title)" placeholder.
   */
  public function testDecorateArmV0(): void {
    $experiment = $this->createExperiment('/blog', ['Alpha']);
    $result = $this->decorator->decorateArm($experiment->getRlExperimentId(), 'v0');
    $this->assertIsArray($result);
    $rendered = (string) $this->container->get('renderer')->renderInIsolation($result);
    $this->assertStringContainsString('original title', $rendered);
  }

  /**
   * Tests that v1 yields the first stored variant text.
   */
  public function testDecorateArmV1(): void {
    $experiment = $this->createExperiment('/blog', ['Alpha', 'Beta']);
    $result = $this->decorator->decorateArm($experiment->getRlExperimentId(), 'v1');
    $rendered = (string) $this->container->get('renderer')->renderInIsolation($result);
    $this->assertStringContainsString('Alpha', $rendered);
  }

  /**
   * Tests that v2 yields the second stored variant text.
   */
  public function testDecorateArmV2(): void {
    $experiment = $this->createExperiment('/blog', ['Alpha', 'Beta']);
    $result = $this->decorator->decorateArm($experiment->getRlExperimentId(), 'v2');
    $rendered = (string) $this->container->get('renderer')->renderInIsolation($result);
    $this->assertStringContainsString('Beta', $rendered);
  }

  /**
   * Out-of-range arms fall through to the original placeholder.
   */
  public function testDecorateArmOutOfRange(): void {
    $experiment = $this->createExperiment('/blog', ['Alpha']);
    $result = $this->decorator->decorateArm($experiment->getRlExperimentId(), 'v99');
    $rendered = (string) $this->container->get('renderer')->renderInIsolation($result);
    $this->assertStringContainsString('original', $rendered);
  }

  /**
   * Decorator on an unknown experiment ID returns NULL.
   */
  public function testDecorateArmUnknownExperiment(): void {
    $this->assertNull($this->decorator->decorateArm('rl_page_title-deadbeefcafe', 'v0'));
  }

  /**
   * Helper: create a test experiment.
   */
  protected function createExperiment(string $path, array $variants): PageTitleExperiment {
    $experiment = PageTitleExperiment::create([
      'id' => 'pt_test_' . substr(md5($path . serialize($variants)), 0, 8),
      'label' => 'Test ' . $path,
      'path' => $path,
      'variants' => $variants,
      'enabled' => TRUE,
    ]);
    $experiment->save();
    return $experiment;
  }

}
