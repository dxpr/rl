<?php

namespace Drupal\rl\Registry;

/**
 * Interface for experiment registration service.
 */
interface ExperimentRegistryInterface {

  /**
   * Register an experiment ID with a module.
   *
   * @param string $experiment_id
   *   The experiment ID.
   * @param string $module
   *   The module name that owns this experiment.
   * @param string $experiment_name
   *   Optional human-readable experiment name.
   */
  public function register(string $experiment_id, string $module, ?string $experiment_name = NULL): void;

  /**
   * Check if an experiment ID is registered.
   *
   * @param string $experiment_id
   *   The experiment ID to check.
   *
   * @return bool
   *   TRUE if the experiment is registered, FALSE otherwise.
   */
  public function isRegistered(string $experiment_id): bool;

  /**
   * Get the module that owns an experiment.
   *
   * @param string $experiment_id
   *   The experiment ID.
   *
   * @return string|null
   *   The module name if registered, NULL otherwise.
   */
  public function getOwner(string $experiment_id): ?string;

  /**
   * Get all registered experiments.
   *
   * @return array
   *   Array keyed by ID with module names as values.
   */
  public function getAll(): array;

  /**
   * Get the human-readable name for an experiment.
   *
   * @param string $experiment_id
   *   The experiment ID.
   *
   * @return string|null
   *   The experiment name or NULL if not found.
   */
  public function getExperimentName(string $experiment_id): ?string;

}
