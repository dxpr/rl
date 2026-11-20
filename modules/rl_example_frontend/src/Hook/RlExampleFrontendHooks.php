<?php

namespace Drupal\rl_example_frontend\Hook;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for rl_example_frontend.
 */
class RlExampleFrontendHooks {
  use StringTranslationTrait;

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match) {
    if ($route_name == 'help.page.rl_example_frontend') {
      return '<p>' . $this->t('Place the "RL Example Frontend Newsletter Signup" block to see A/B testing in action. This module demonstrates frontend-only A/B testing - JavaScript fetches Thompson sampling scores and overrides button text without any cache management complexity.') . '</p>';
    }
  }

}
