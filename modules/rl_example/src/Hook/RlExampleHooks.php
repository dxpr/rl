<?php

namespace Drupal\rl_example\Hook;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for rl_example.
 */
class RlExampleHooks {
  use StringTranslationTrait;

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match) {
    if ($route_name == 'help.page.rl_example') {
      return '<p>' . $this->t('Place the "RL Example Newsletter Signup" block to see A/B testing in action. The module tests which button text gets more signups.') . '</p>';
    }
  }

}
