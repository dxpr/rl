<?php

declare(strict_types=1);

namespace Drupal\rl\Drush\Commands;

use Drush\Commands\DrushCommands;
use Symfony\Component\Yaml\Yaml;

/**
 * Base class for all RL Drush commands.
 *
 * Provides YAML output formatting and response helpers
 * consistent with drush_webmaster / dxt / dxb conventions.
 */
abstract class RlCommandsBase extends DrushCommands {

  /**
   * Switches to admin user for the duration of the Drush process.
   *
   * Does not call switchBack() — relies on process termination after
   * command execution.
   */
  protected function switchToAdmin(): void {
    // @phpstan-ignore-next-line
    if (!\Drupal::hasContainer()) {
      return;
    }

    try {
      /** @var \Drupal\Core\Session\AccountSwitcherInterface $account_switcher */
      // @phpstan-ignore-next-line
      $account_switcher = \Drupal::service('account_switcher');
      /** @var \Drupal\user\UserStorageInterface $user_storage */
      // @phpstan-ignore-next-line
      $user_storage = \Drupal::entityTypeManager()->getStorage('user');
      $admin = $user_storage->load(1);

      if ($admin) {
        $account_switcher->switchTo($admin);
      }
    }
    catch (\Exception $e) {
      // Silently fail if services aren't available yet.
    }
  }

  /**
   * Outputs data as YAML.
   */
  protected function yaml(array $data, int $inline = 4): string {
    return Yaml::dump($data, $inline, 2);
  }

  /**
   * Returns a success response as YAML.
   */
  protected function success(string $message, array $data = []): string {
    return $this->yaml(array_merge([
      'success' => TRUE,
      'message' => $message,
    ], $data));
  }

  /**
   * Returns an error response as YAML.
   */
  protected function error(string $message, array $errors = []): string {
    $data = [
      'success' => FALSE,
      'message' => $message,
    ];
    if (!empty($errors)) {
      $data['errors'] = $errors;
    }
    return $this->yaml($data);
  }

  /**
   * Returns success response with items list.
   */
  protected function successList(array $items, array $extra = []): string {
    return $this->yaml(array_merge([
      'success' => TRUE,
      'count' => count($items),
      'items' => $items,
    ], $extra));
  }

  /**
   * Gets the rl module path.
   */
  protected function getModulePath(): ?string {
    try {
      // @phpstan-ignore-next-line
      $path = \Drupal::service('extension.list.module')->getPath('rl');
      return $path ? DRUPAL_ROOT . '/' . $path : NULL;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Gets the Composer project root.
   */
  protected function getProjectRoot(): ?string {
    $dir = defined('DRUPAL_ROOT') ? DRUPAL_ROOT : getcwd();
    for ($i = 0; $i < 5; $i++) {
      if (file_exists($dir . '/composer.json')) {
        return $dir;
      }
      $parent = dirname($dir);
      if ($parent === $dir) {
        break;
      }
      $dir = $parent;
    }
    return NULL;
  }

}
