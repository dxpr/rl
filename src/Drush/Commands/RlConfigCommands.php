<?php

declare(strict_types=1);

namespace Drupal\rl\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drush\Attributes as CLI;

/**
 * Drush commands for RL module configuration management.
 */
final class RlConfigCommands extends RlCommandsBase {

  /**
   * Valid settings with their types, defaults, and constraints.
   */
  protected const SETTINGS_SCHEMA = [
    'debug_mode' => [
      'type' => 'boolean',
      'default' => FALSE,
      'description' => 'Enable Thompson Sampling score logging for debugging.',
    ],
    'enable_event_log' => [
      'type' => 'boolean',
      'default' => TRUE,
      'description' => 'Record snapshots over time for historical visualization.',
    ],
    'event_log_max_rows' => [
      'type' => 'integer',
      'default' => 100000,
      'min' => 1000,
      'max' => 10000000,
      'description' => 'Maximum rows in rl_arm_snapshots before cron cleanup.',
    ],
    'chart_line_threshold' => [
      'type' => 'integer',
      'default' => 9,
      'min' => 2,
      'max' => 50,
      'description' => 'Max variants for 2D line chart; above this uses 3D landscape.',
    ],
  ];

  /**
   * Constructs RlConfigCommands.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly Connection $database,
  ) {
    parent::__construct();
  }

  /**
   * Get one or all RL settings.
   */
  #[CLI\Command(name: 'rl:config:get', aliases: ['rl-cg'])]
  #[CLI\Help(description: '[YAML] Get RL configuration values with schema metadata.')]
  #[CLI\Argument(name: 'key', description: 'Setting key (omit to get all)')]
  #[CLI\Usage(name: 'drush rl:config:get', description: 'Get all settings')]
  #[CLI\Usage(name: 'drush rl:config:get debug_mode', description: 'Get a specific setting')]
  public function get(string $key = ''): string {
    $config = $this->configFactory->get('rl.settings');

    if ($key !== '') {
      if (!isset(self::SETTINGS_SCHEMA[$key])) {
        return $this->error(
          sprintf('Unknown setting "%s".', $key),
          ['Valid keys: ' . implode(', ', array_keys(self::SETTINGS_SCHEMA))]
        );
      }

      $schema = self::SETTINGS_SCHEMA[$key];
      $value = $config->get($key) ?? $schema['default'];

      return $this->yaml([
        'key' => $key,
        'value' => $value,
        'type' => $schema['type'],
        'default' => $schema['default'],
        'description' => $schema['description'],
      ]);
    }

    $settings = [];
    foreach (self::SETTINGS_SCHEMA as $settingKey => $schema) {
      $settings[$settingKey] = [
        'value' => $config->get($settingKey) ?? $schema['default'],
        'type' => $schema['type'],
        'default' => $schema['default'],
        'description' => $schema['description'],
      ];
    }

    return $this->yaml(['settings' => $settings]);
  }

  /**
   * Set an RL configuration value.
   */
  #[CLI\Command(name: 'rl:config:set', aliases: ['rl-cs'])]
  #[CLI\Help(description: '[YAML] Set an RL configuration value with validation.')]
  #[CLI\Argument(name: 'key', description: 'Setting key')]
  #[CLI\Argument(name: 'value', description: 'New value')]
  #[CLI\Option(name: 'dry-run', description: 'Preview without making changes')]
  #[CLI\Usage(name: 'drush rl:config:set debug_mode 1', description: 'Enable debug mode')]
  #[CLI\Usage(name: 'drush rl:config:set event_log_max_rows 500000', description: 'Set max log rows')]
  #[CLI\Usage(name: 'drush rl-cs chart_line_threshold 15 --dry-run', description: 'Preview change')]
  public function set(
    string $key,
    string $value,
    array $options = ['dry-run' => FALSE],
  ): string {
    if (!isset(self::SETTINGS_SCHEMA[$key])) {
      return $this->error(
        sprintf('Unknown setting "%s".', $key),
        ['Valid keys: ' . implode(', ', array_keys(self::SETTINGS_SCHEMA))]
      );
    }

    $schema = self::SETTINGS_SCHEMA[$key];
    $normalized = $this->normalizeValue($value, $schema);

    if ($normalized === NULL) {
      return $this->error(sprintf('Invalid value "%s" for %s (type: %s).', $value, $key, $schema['type']));
    }

    // Range validation.
    if ($schema['type'] === 'integer') {
      if (isset($schema['min']) && $normalized < $schema['min']) {
        return $this->error(sprintf('Value %d is below minimum %d for "%s".', $normalized, $schema['min'], $key));
      }
      if (isset($schema['max']) && $normalized > $schema['max']) {
        return $this->error(sprintf('Value %d exceeds maximum %d for "%s".', $normalized, $schema['max'], $key));
      }
    }

    $config = $this->configFactory->get('rl.settings');
    $oldValue = $config->get($key) ?? $schema['default'];

    if ($options['dry-run']) {
      return $this->yaml([
        'dry_run' => TRUE,
        'action' => 'config:set',
        'key' => $key,
        'old_value' => $oldValue,
        'new_value' => $normalized,
      ]);
    }

    $this->configFactory->getEditable('rl.settings')
      ->set($key, $normalized)
      ->save();

    return $this->success(
      sprintf('Setting "%s" updated.', $key),
      [
        'key' => $key,
        'old_value' => $oldValue,
        'new_value' => $normalized,
      ]
    );
  }

  /**
   * List all RL settings with current values and schema.
   */
  #[CLI\Command(name: 'rl:config:list', aliases: ['rl-cl'])]
  #[CLI\Help(description: '[YAML] List all RL settings with current values, types, and descriptions.')]
  #[CLI\Usage(name: 'drush rl:config:list', description: 'List all settings')]
  public function listSettings(): string {
    $config = $this->configFactory->get('rl.settings');
    $items = [];

    foreach (self::SETTINGS_SCHEMA as $key => $schema) {
      $current = $config->get($key) ?? $schema['default'];
      $item = [
        'key' => $key,
        'value' => $current,
        'default' => $schema['default'],
        'type' => $schema['type'],
        'description' => $schema['description'],
      ];
      if (isset($schema['min'])) {
        $item['min'] = $schema['min'];
      }
      if (isset($schema['max'])) {
        $item['max'] = $schema['max'];
      }
      $items[] = $item;
    }

    return $this->successList($items);
  }

  /**
   * Reset all RL settings to defaults.
   */
  #[CLI\Command(name: 'rl:config:reset', aliases: ['rl-cr'])]
  #[CLI\Help(description: '[YAML] Reset all RL settings to their default values.')]
  #[CLI\Option(name: 'dry-run', description: 'Preview without making changes')]
  #[CLI\Usage(name: 'drush rl:config:reset', description: 'Reset all settings')]
  #[CLI\Usage(name: 'drush rl-cr --dry-run', description: 'Preview reset')]
  public function reset(array $options = ['dry-run' => FALSE]): string {
    $config = $this->configFactory->get('rl.settings');
    $changes = [];

    foreach (self::SETTINGS_SCHEMA as $key => $schema) {
      $current = $config->get($key);
      if ($current !== $schema['default']) {
        $changes[$key] = [
          'from' => $current,
          'to' => $schema['default'],
        ];
      }
    }

    if (empty($changes)) {
      return $this->success('All settings are already at default values.');
    }

    if ($options['dry-run']) {
      return $this->yaml([
        'dry_run' => TRUE,
        'action' => 'config:reset',
        'changes' => $changes,
      ]);
    }

    $editable = $this->configFactory->getEditable('rl.settings');
    foreach (self::SETTINGS_SCHEMA as $key => $schema) {
      $editable->set($key, $schema['default']);
    }
    $editable->save();

    return $this->success('All settings reset to defaults.', ['changes' => $changes]);
  }

  /**
   * Clear all event log snapshots.
   */
  #[CLI\Command(name: 'rl:event-log:clear', aliases: ['rl-elc'])]
  #[CLI\Help(description: '[YAML] Delete all historical snapshots from rl_arm_snapshots.')]
  #[CLI\Option(name: 'dry-run', description: 'Preview without making changes')]
  #[CLI\Usage(name: 'drush rl:event-log:clear', description: 'Clear all snapshots')]
  #[CLI\Usage(name: 'drush rl-elc --dry-run', description: 'Preview how many rows would be deleted')]
  public function clearEventLog(array $options = ['dry-run' => FALSE]): string {
    $this->switchToAdmin();

    $count = (int) $this->database->select('rl_arm_snapshots', 's')
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($count === 0) {
      return $this->success('Event log is already empty.');
    }

    if ($options['dry-run']) {
      return $this->yaml([
        'dry_run' => TRUE,
        'action' => 'event-log:clear',
        'rows_to_delete' => $count,
      ]);
    }

    $this->database->truncate('rl_arm_snapshots')->execute();

    return $this->success(
      sprintf('Event log cleared (%d snapshots deleted).', $count),
      ['rows_deleted' => $count]
    );
  }

  /**
   * Normalizes a string value to the correct type.
   */
  protected function normalizeValue(string $value, array $schema): mixed {
    return match ($schema['type']) {
      'boolean' => in_array(strtolower($value), ['1', 'true', 'yes', 'on']) ? TRUE :
        (in_array(strtolower($value), ['0', 'false', 'no', 'off']) ? FALSE : NULL),
      'integer' => is_numeric($value) ? (int) $value : NULL,
      default => $value,
    };
  }

}
