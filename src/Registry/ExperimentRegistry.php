<?php

namespace Drupal\rl\Registry;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

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
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs an ExperimentRegistry object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(Connection $database, TimeInterface $time, LoggerInterface $logger) {
    $this->database = $database;
    $this->time = $time;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public function register(string $experiment_id, string $module, ?string $experiment_name = NULL): void {
    try {
      // Use merge to handle duplicate registrations gracefully.
      $fields = [
        'module' => $module,
        'registered_at' => $this->time->getRequestTime(),
      ];

      if ($experiment_name !== NULL) {
        $fields['experiment_name'] = $experiment_name;
      }

      $this->database->merge('rl_experiment_registry')
        ->key('experiment_id', $experiment_id)
        ->fields($fields)
        ->execute();
    }
    catch (\Exception $e) {
      // Log error but don't break the page.
      $this->logger->error('Failed to register experiment @id: @message', [
        '@id' => $experiment_id,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isRegistered(string $experiment_id): bool {
    try {
      $result = $this->database->select('rl_experiment_registry', 'r')
        ->fields('r', ['experiment_id'])
        ->condition('experiment_id', $experiment_id)
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
  public function getOwner(string $experiment_id): ?string {
    try {
      $result = $this->database->select('rl_experiment_registry', 'r')
        ->fields('r', ['module'])
        ->condition('experiment_id', $experiment_id)
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
        ->fields('r', ['experiment_id', 'module'])
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
  public function getExperimentName(string $experiment_id): ?string {
    try {
      $result = $this->database->select('rl_experiment_registry', 'r')
        ->fields('r', ['experiment_name'])
        ->condition('experiment_id', $experiment_id)
        ->execute()
        ->fetchField();

      return $result ?: NULL;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

}
