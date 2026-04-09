<?php

namespace Drupal\Tests\rl\Unit\Experiment;

use Drupal\rl\Experiment\VariantArmsTrait;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for VariantArmsTrait.
 *
 * @group rl
 */
class VariantArmsTraitTest extends UnitTestCase {

  /**
   * Build a fixture using the trait.
   *
   * @param string[] $variants
   *   The stored variant texts.
   */
  protected function fixture(array $variants): object {
    return new class($variants) {
      use VariantArmsTrait;

      /**
       * The fixture's stored variants.
       *
       * @var string[]
       */
      private array $variants;

      /**
       * Construct the fixture.
       *
       * @param string[] $variants
       *   The variants to expose via getVariants().
       */
      public function __construct(array $variants) {
        $this->variants = $variants;
      }

      /**
       * {@inheritdoc}
       */
      public function getVariants(): array {
        return $this->variants;
      }

    };
  }

  /**
   * Tests getArmIds() with no stored variants.
   */
  public function testGetArmIdsEmpty(): void {
    $f = $this->fixture([]);
    $this->assertSame(['v0'], $f->getArmIds());
  }

  /**
   * Tests getArmIds() with three stored variants.
   */
  public function testGetArmIdsThreeVariants(): void {
    $f = $this->fixture(['Alt 1', 'Alt 2', 'Alt 3']);
    $this->assertSame(['v0', 'v1', 'v2', 'v3'], $f->getArmIds());
  }

  /**
   * Tests getArmText() across known and malformed arm IDs.
   *
   * @dataProvider armTextProvider
   */
  public function testGetArmText(string $arm_id, ?string $expected): void {
    $f = $this->fixture(['Alpha', 'Beta', 'Gamma']);
    $this->assertSame($expected, $f->getArmText($arm_id));
  }

  /**
   * Provides arm IDs and the expected text for getArmText() tests.
   *
   * @return iterable<string, array{0: string, 1: ?string}>
   *   Test cases for arm text.
   */
  public static function armTextProvider(): iterable {
    yield 'v0 returns null (original)' => ['v0', NULL];
    yield 'v1 returns first variant' => ['v1', 'Alpha'];
    yield 'v2 returns second variant' => ['v2', 'Beta'];
    yield 'v3 returns third variant' => ['v3', 'Gamma'];
    yield 'v4 returns null (out of range)' => ['v4', NULL];
    yield 'v10 returns null (out of range)' => ['v10', NULL];
    yield 'malformed vfoo returns null' => ['vfoo', NULL];
    yield 'malformed v-1 returns null' => ['v-1', NULL];
    yield 'empty string returns null' => ['', NULL];
    yield 'random string returns null' => ['random', NULL];
  }

  /**
   * Tests that buildVariantExperimentId() is deterministic.
   */
  public function testBuildVariantExperimentIdIsDeterministic(): void {
    $a = VariantArmsTrait::buildVariantExperimentId('rl_page_title', '/blog');
    $b = VariantArmsTrait::buildVariantExperimentId('rl_page_title', '/blog');
    $this->assertSame($a, $b);
  }

  /**
   * Tests that different inputs produce different hashes.
   */
  public function testBuildVariantExperimentIdHashesDifferentInputs(): void {
    $a = VariantArmsTrait::buildVariantExperimentId('rl_page_title', '/blog');
    $b = VariantArmsTrait::buildVariantExperimentId('rl_page_title', '/news');
    $this->assertNotSame($a, $b);
  }

  /**
   * Tests that the prefix argument is honored in the resulting ID.
   */
  public function testBuildVariantExperimentIdRespectsPrefix(): void {
    $a = VariantArmsTrait::buildVariantExperimentId('rl_page_title', '/blog');
    $b = VariantArmsTrait::buildVariantExperimentId('rl_menu_link', '/blog');
    $this->assertNotSame($a, $b);
    $this->assertStringStartsWith('rl_page_title-', $a);
    $this->assertStringStartsWith('rl_menu_link-', $b);
  }

  /**
   * Tests the shape of the generated experiment ID.
   */
  public function testBuildVariantExperimentIdShape(): void {
    $id = VariantArmsTrait::buildVariantExperimentId('rl_page_title', '/blog');
    // Format: prefix-12-char-sha1.
    $this->assertMatchesRegularExpression('/^rl_page_title-[a-f0-9]{12}$/', $id);
  }

  /**
   * Tests that paths colliding under naive sanitization get distinct hashes.
   *
   * Sanitization that replaces non-alphanumeric with _ would conflate
   * /foo/bar and /foo_bar; the hash-based approach avoids this.
   */
  public function testBuildVariantExperimentIdAvoidsCollisions(): void {
    $a = VariantArmsTrait::buildVariantExperimentId('rl_page_title', '/foo/bar');
    $b = VariantArmsTrait::buildVariantExperimentId('rl_page_title', '/foo_bar');
    $this->assertNotSame($a, $b);
  }

}
