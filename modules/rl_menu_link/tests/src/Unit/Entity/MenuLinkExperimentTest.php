<?php

namespace Drupal\Tests\rl_menu_link\Unit\Entity;

use Drupal\rl_menu_link\Entity\MenuLinkExperiment;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\rl_menu_link\Entity\MenuLinkExperiment
 *
 * @group rl_menu_link
 */
class MenuLinkExperimentTest extends UnitTestCase {

  /**
   * @covers ::buildRlExperimentId
   */
  public function testBuildRlExperimentIdIsDeterministic(): void {
    $a = MenuLinkExperiment::buildRlExperimentId('menu_link_content:abc-uuid');
    $b = MenuLinkExperiment::buildRlExperimentId('menu_link_content:abc-uuid');
    $this->assertSame($a, $b);
  }

  /**
   * @covers ::buildRlExperimentId
   */
  public function testBuildRlExperimentIdShape(): void {
    $id = MenuLinkExperiment::buildRlExperimentId('system.admin_content');
    $this->assertMatchesRegularExpression('/^rl_menu_link-[a-f0-9]{12}$/', $id);
  }

  /**
   * @covers ::buildRlExperimentId
   */
  public function testBuildRlExperimentIdDifferentForDifferentInputs(): void {
    $a = MenuLinkExperiment::buildRlExperimentId('menu_link_content:111');
    $b = MenuLinkExperiment::buildRlExperimentId('menu_link_content:222');
    $this->assertNotSame($a, $b);
  }

  /**
   * Tests that plugin IDs with surrounding whitespace collapse to one hash.
   */
  public function testBuildRlExperimentIdTrimsWhitespace(): void {
    $a = MenuLinkExperiment::buildRlExperimentId('system.admin_content');
    $b = MenuLinkExperiment::buildRlExperimentId('  system.admin_content  ');
    $this->assertSame($a, $b);
  }

}
