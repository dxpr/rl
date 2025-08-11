<?php

namespace Drupal\rl\Registry;

use Drupal\Core\Database\Connection;

/**
 * Service for managing experiment registration.
 */
class ExperimentRegistry implements ExperimentRegistryInterface {
  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs an ExperimentRegistry object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public function register(string $uuid, string $module, ?string $experiment_name = NULL): void {
    try {
      // Use merge to handle duplicate registrations gracefully.
      $fields = [
        'module' => $module,
        'registered_at' => \Drupal::time()->getRequestTime(),
      ];

      if ($experiment_name !== NULL) {
        $fields['experiment_name'] = $experiment_name;
      }

      $this->database->merge('rl_experiment_registry')
        ->key(['uuid' => $uuid])
        ->fields($fields)
        ->execute();
    }
    catch (\Exception $e) {
      // Log error but don't break the page.
      \Drupal::logger('rl')->error('Failed to register experiment @uuid: @message', [
        '@uuid' => $uuid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isRegistered(string $uuid): bool {
    try {
      $result = $this->database->select('rl_experiment_registry', 'r')
        ->fields('r', ['uuid'])
        ->condition('uuid', $uuid)
        ->countQuery()
        ->execute()
        ->fetchField();

      return (bool) $result;
    }
    catch (\Exception $e) {
      // Return false if table doesn't exist yet.
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getOwner(string $uuid): ?string {
    try {
      $result = $this->database->select('rl_experiment_registry', 'r')
        ->fields('r', ['module'])
        ->condition('uuid', $uuid)
        ->execute()
        ->fetchField();

      return $result ?: NULL;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getAll(): array {
    try {
      $results = $this->database->select('rl_experiment_registry', 'r')
        ->fields('r', ['uuid', 'module'])
        ->execute()
        ->fetchAllKeyed();

      return $results;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getExperimentName(string $uuid): ?string {
    try {
      $result = $this->database->select('rl_experiment_registry', 'r')
        ->fields('r', ['experiment_name'])
        ->condition('uuid', $uuid)
        ->execute()
        ->fetchField();

      return $result ?: NULL;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

}
