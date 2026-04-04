<?php

declare(strict_types=1);

namespace Drupal\rl\Drush\Commands;

use Drupal\Core\Database\Connection;
use Drupal\rl\Registry\ExperimentRegistryInterface;
use Drush\Attributes as CLI;

/**
 * Drush commands for RL experiment lifecycle management.
 */
final class RlExperimentCommands extends RlCommandsBase {

  /**
   * Constructs RlExperimentCommands.
   */
  public function __construct(
    protected readonly ExperimentRegistryInterface $experimentRegistry,
    protected readonly Connection $database,
  ) {
    parent::__construct();
  }

  /**
   * Create a new experiment.
   */
  #[CLI\Command(name: 'rl:experiment:create', aliases: ['rl-ec'])]
  #[CLI\Help(description: '[YAML] Register a new RL experiment.')]
  #[CLI\Argument(name: 'experimentId', description: 'Unique experiment ID (e.g., ab_test_button_color)')]
  #[CLI\Option(name: 'module', description: 'Owning module (default: custom)')]
  #[CLI\Option(name: 'name', description: 'Human-readable experiment name')]
  #[CLI\Option(name: 'dry-run', description: 'Preview without making changes')]
  #[CLI\Usage(name: 'drush rl:experiment:create ab_test_cta --module=my_module --name="CTA Button Test"', description: 'Create a new experiment')]
  #[CLI\Usage(name: 'drush rl-ec hero_banner_test --dry-run', description: 'Preview creation')]
  public function create(
    string $experimentId,
    array $options = [
      'module' => 'custom',
      'name' => NULL,
      'dry-run' => FALSE,
    ],
  ): string {
    $this->switchToAdmin();

    if ($this->experimentRegistry->isRegistered($experimentId)) {
      return $this->error(
        sprintf('Experiment "%s" already exists.', $experimentId),
        ['Use rl:experiment:update to modify it, or rl:list to see all experiments.']
      );
    }

    $module = $options['module'] ?? 'custom';
    $name = $options['name'] ?? $experimentId;

    if ($options['dry-run']) {
      return $this->yaml([
        'dry_run' => TRUE,
        'action' => 'create',
        'experiment' => [
          'id' => $experimentId,
          'module' => $module,
          'name' => $name,
        ],
      ]);
    }

    $this->experimentRegistry->register($experimentId, $module, $name);

    return $this->success(
      sprintf('Experiment "%s" created.', $experimentId),
      [
        'experiment' => [
          'id' => $experimentId,
          'module' => $module,
          'name' => $name,
        ],
      ]
    );
  }

  /**
   * Update an existing experiment's metadata.
   */
  #[CLI\Command(name: 'rl:experiment:update', aliases: ['rl-eu'])]
  #[CLI\Help(description: '[YAML] Update experiment name or module.')]
  #[CLI\Argument(name: 'experimentId', description: 'The experiment ID to update')]
  #[CLI\Option(name: 'module', description: 'New owning module')]
  #[CLI\Option(name: 'name', description: 'New human-readable name')]
  #[CLI\Option(name: 'dry-run', description: 'Preview without making changes')]
  #[CLI\Usage(name: 'drush rl:experiment:update ab_test_cta --name="Updated CTA Test"', description: 'Rename an experiment')]
  #[CLI\Usage(name: 'drush rl-eu ab_test_cta --module=other_module', description: 'Change owning module')]
  public function update(
    string $experimentId,
    array $options = [
      'module' => NULL,
      'name' => NULL,
      'dry-run' => FALSE,
    ],
  ): string {
    $this->switchToAdmin();

    if (!$this->experimentRegistry->isRegistered($experimentId)) {
      return $this->error(
        sprintf('Experiment "%s" not found.', $experimentId),
        ['Use rl:list to see all experiments.']
      );
    }

    $fields = [];
    if ($options['module'] !== NULL) {
      $fields['module'] = $options['module'];
    }
    if ($options['name'] !== NULL) {
      $fields['experiment_name'] = $options['name'];
    }

    if (empty($fields)) {
      return $this->error('Nothing to update. Provide --module and/or --name.');
    }

    if ($options['dry-run']) {
      return $this->yaml([
        'dry_run' => TRUE,
        'action' => 'update',
        'experiment_id' => $experimentId,
        'changes' => $fields,
      ]);
    }

    $this->database->update('rl_experiment_registry')
      ->fields($fields)
      ->condition('experiment_id', $experimentId)
      ->execute();

    return $this->success(
      sprintf('Experiment "%s" updated.', $experimentId),
      ['changes' => $fields]
    );
  }

  /**
   * Delete an experiment and all its data.
   */
  #[CLI\Command(name: 'rl:experiment:delete', aliases: ['rl-ed'])]
  #[CLI\Help(description: '[YAML] Delete an experiment and all associated data (turns, rewards, snapshots).')]
  #[CLI\Argument(name: 'experimentId', description: 'The experiment ID to delete')]
  #[CLI\Option(name: 'dry-run', description: 'Preview what would be deleted')]
  #[CLI\Usage(name: 'drush rl:experiment:delete ab_test_cta', description: 'Delete an experiment')]
  #[CLI\Usage(name: 'drush rl-ed old_test --dry-run', description: 'Preview deletion')]
  public function delete(
    string $experimentId,
    array $options = [
      'dry-run' => FALSE,
    ],
  ): string {
    $this->switchToAdmin();

    if (!$this->experimentRegistry->isRegistered($experimentId)) {
      return $this->error(
        sprintf('Experiment "%s" not found.', $experimentId),
        ['Use rl:list to see all experiments.']
      );
    }

    // Count data that would be deleted.
    $armCount = (int) $this->database->select('rl_arm_data', 'a')
      ->condition('experiment_id', $experimentId)
      ->countQuery()
      ->execute()
      ->fetchField();

    $snapshotCount = (int) $this->database->select('rl_arm_snapshots', 's')
      ->condition('experiment_id', $experimentId)
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($options['dry-run']) {
      return $this->yaml([
        'dry_run' => TRUE,
        'action' => 'delete',
        'experiment_id' => $experimentId,
        'data_to_delete' => [
          'arms' => $armCount,
          'snapshots' => $snapshotCount,
          'tables' => ['rl_arm_data', 'rl_experiment_totals', 'rl_arm_snapshots', 'rl_experiment_registry'],
        ],
      ]);
    }

    $transaction = $this->database->startTransaction();
    try {
      $this->database->delete('rl_arm_data')
        ->condition('experiment_id', $experimentId)
        ->execute();

      $this->database->delete('rl_experiment_totals')
        ->condition('experiment_id', $experimentId)
        ->execute();

      $this->database->delete('rl_arm_snapshots')
        ->condition('experiment_id', $experimentId)
        ->execute();

      $this->database->delete('rl_experiment_registry')
        ->condition('experiment_id', $experimentId)
        ->execute();
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      return $this->error(
        sprintf('Failed to delete experiment "%s": %s', $experimentId, $e->getMessage())
      );
    }

    return $this->success(
      sprintf('Experiment "%s" deleted.', $experimentId),
      [
        'deleted' => [
          'arms' => $armCount,
          'snapshots' => $snapshotCount,
        ],
      ]
    );
  }

}
