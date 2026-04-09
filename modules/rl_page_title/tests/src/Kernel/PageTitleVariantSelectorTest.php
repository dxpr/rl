<?php

namespace Drupal\Tests\rl_page_title\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\rl_page_title\Entity\PageTitleExperiment;

/**
 * Tests TitleVariantSelector::selectForPath.
 *
 * @group rl_page_title
 */
class PageTitleVariantSelectorTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['rl', 'rl_page_title'];

  /**
   * The variant selector.
   *
   * @var \Drupal\rl_page_title\Service\TitleVariantSelector
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
    $this->installConfig(['rl_page_title']);
    $this->selector = $this->container->get('rl_page_title.variant_selector');
    $this->registry = $this->container->get('rl.experiment_registry');
  }

  /**
   * No experiment configured for this path -> selector returns NULL.
   */
  public function testSelectForPathReturnsNullWhenNoExperiment(): void {
    $this->assertNull($this->selector->selectForPath('/blog'));
  }

  /**
   * A disabled experiment is treated as if it does not exist.
   */
  public function testSelectForPathReturnsNullWhenDisabled(): void {
    $this->createExperiment('/blog', ['Alpha', 'Beta'], FALSE);
    $this->assertNull($this->selector->selectForPath('/blog'));
  }

  /**
   * An enabled experiment yields a result with experiment_id and arm_id.
   */
  public function testSelectForPathReturnsResultWhenEnabled(): void {
    $experiment = $this->createExperiment('/blog', ['Alpha', 'Beta']);
    $this->registry->register($experiment->getRlExperimentId(), 'rl_page_title', 'Test');

    $result = $this->selector->selectForPath('/blog');
    $this->assertNotNull($result);
    $this->assertSame($experiment->getRlExperimentId(), $result['experiment_id']);
    $this->assertContains($result['arm_id'], ['v0', 'v1', 'v2']);
    if ($result['arm_id'] === 'v0') {
      $this->assertNull($result['text']);
    }
    else {
      $this->assertContains($result['text'], ['Alpha', 'Beta']);
    }
  }

  /**
   * Path normalization: trailing slash and missing leading slash both match.
   */
  public function testSelectForPathNormalizes(): void {
    $experiment = $this->createExperiment('/blog', ['Alt']);
    $this->registry->register($experiment->getRlExperimentId(), 'rl_page_title', 'Test');

    $this->assertNotNull($this->selector->selectForPath('/blog'));
    $this->assertNotNull($this->selector->selectForPath('/blog/'));
    $this->assertNotNull($this->selector->selectForPath('blog'));
    $this->assertNotNull($this->selector->selectForPath('  /blog  '));
  }

  /**
   * Per-request caching: same path queried twice returns same result object.
   */
  public function testSelectForPathIsCached(): void {
    $experiment = $this->createExperiment('/blog', ['Alpha']);
    $this->registry->register($experiment->getRlExperimentId(), 'rl_page_title', 'Test');

    $first = $this->selector->selectForPath('/blog');
    $second = $this->selector->selectForPath('/blog');
    $this->assertSame($first, $second);
  }

  /**
   * Helper: create a test experiment.
   */
  protected function createExperiment(string $path, array $variants, bool $enabled = TRUE): PageTitleExperiment {
    $experiment = PageTitleExperiment::create([
      'id' => 'pt_test_' . substr(md5($path), 0, 8),
      'label' => 'Test ' . $path,
      'path' => $path,
      'variants' => $variants,
      'enabled' => $enabled,
    ]);
    $experiment->save();
    return $experiment;
  }

}
