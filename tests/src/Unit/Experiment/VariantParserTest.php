<?php

namespace Drupal\Tests\rl\Unit\Experiment;

use Drupal\rl\Experiment\VariantParser;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\rl\Experiment\VariantParser
 *
 * @group rl
 */
class VariantParserTest extends UnitTestCase {

  /**
   * @covers ::parse
   */
  public function testParseNull(): void {
    $this->assertSame([], VariantParser::parse(NULL));
  }

  /**
   * @covers ::parse
   */
  public function testParseEmpty(): void {
    $this->assertSame([], VariantParser::parse(''));
  }

  /**
   * @covers ::parse
   */
  public function testParseSingleLine(): void {
    $this->assertSame(['Hello'], VariantParser::parse('Hello'));
  }

  /**
   * @covers ::parse
   */
  public function testParseMultipleLines(): void {
    $this->assertSame(['One', 'Two', 'Three'], VariantParser::parse("One\nTwo\nThree"));
  }

  /**
   * @covers ::parse
   */
  public function testParseTrimsLines(): void {
    $this->assertSame(['One', 'Two'], VariantParser::parse("  One  \n   Two   "));
  }

  /**
   * @covers ::parse
   */
  public function testParseSkipsEmptyLines(): void {
    $this->assertSame(['One', 'Two'], VariantParser::parse("One\n\n\nTwo\n"));
  }

  /**
   * @covers ::parse
   */
  public function testParseHandlesMixedLineEndings(): void {
    $this->assertSame(['One', 'Two', 'Three'], VariantParser::parse("One\r\nTwo\rThree"));
  }

  /**
   * @covers ::parse
   */
  public function testParseSkipsWhitespaceOnlyLines(): void {
    $this->assertSame(['One', 'Two'], VariantParser::parse("One\n   \n\t\nTwo"));
  }

}
