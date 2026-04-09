<?php

namespace Drupal\Tests\rl_page_title\Unit\Entity;

use Drupal\rl_page_title\Entity\PageTitleExperiment;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\rl_page_title\Entity\PageTitleExperiment
 *
 * @group rl_page_title
 */
class PageTitleExperimentTest extends UnitTestCase {

  /**
   * @covers ::normalizePath
   * @dataProvider normalizePathProvider
   */
  public function testNormalizePath(string $input, string $expected): void {
    $this->assertSame($expected, PageTitleExperiment::normalizePath($input));
  }

  /**
   * Provides input strings and expected normalized output.
   *
   * @return iterable<string, array{0: string, 1: string}>
   *   Test cases for path normalization.
   */
  public static function normalizePathProvider(): iterable {
    yield 'empty string -> root' => ['', '/'];
    yield 'single slash stays root' => ['/', '/'];
    yield 'leading slash preserved' => ['/blog', '/blog'];
    yield 'no leading slash gets one' => ['blog', '/blog'];
    yield 'trailing slash stripped' => ['/blog/', '/blog'];
    yield 'both leading and trailing slashes' => ['blog/', '/blog'];
    yield 'nested path with trailing slash' => ['/blog/post/', '/blog/post'];
    yield 'nested path without trailing slash' => ['/blog/post', '/blog/post'];
    yield 'leading and trailing whitespace' => ['  /blog  ', '/blog'];
    yield 'whitespace inside path preserved' => ['/blog post', '/blog post'];
    yield 'numeric path' => ['/node/42', '/node/42'];
    yield 'numeric with trailing slash' => ['/node/42/', '/node/42'];
    yield 'admin path' => ['/admin/structure/types', '/admin/structure/types'];
    yield 'unicode path' => ['/blog/héllo', '/blog/héllo'];
  }

  /**
   * Tests that different surface representations produce the same RL ID.
   *
   * The form save handler relies on this to keep config and analytics in
   * sync regardless of how the user typed the path.
   */
  public function testBuildRlExperimentIdNormalizesBeforeHashing(): void {
    $canonical = PageTitleExperiment::buildRlExperimentId('/blog');
    $this->assertSame($canonical, PageTitleExperiment::buildRlExperimentId('/blog/'));
    $this->assertSame($canonical, PageTitleExperiment::buildRlExperimentId('blog'));
    $this->assertSame($canonical, PageTitleExperiment::buildRlExperimentId('blog/'));
    $this->assertSame($canonical, PageTitleExperiment::buildRlExperimentId('  /blog  '));
  }

  /**
   * @covers ::buildRlExperimentId
   */
  public function testBuildRlExperimentIdShape(): void {
    $id = PageTitleExperiment::buildRlExperimentId('/blog');
    $this->assertMatchesRegularExpression('/^rl_page_title-[a-f0-9]{12}$/', $id);
  }

  /**
   * @covers ::buildRlExperimentId
   */
  public function testBuildRlExperimentIdDifferentForDifferentPaths(): void {
    $a = PageTitleExperiment::buildRlExperimentId('/blog');
    $b = PageTitleExperiment::buildRlExperimentId('/news');
    $this->assertNotSame($a, $b);
  }

}
