<?php

namespace Drupal\rl\Registry;

/**
 * Interface for experiment registration service.
 */
interface ExperimentRegistryInterface {

  /**
   * Register an experiment UUID with a module.
   *
   * @param string $uuid
   *   The experiment UUID.
   * @param string $module
   *   The module name that owns this experiment.
   * @param string $experiment_name
   *   Optional human-readable experiment name.
   */
  public function register(string $uuid, string $module, string $experiment_name = NULL): void;

  /**
   * Check if an experiment UUID is registered.
   *
   * @param string $uuid
   *   The experiment UUID to check.
   *
   * @return bool
   *   TRUE if the experiment is registered, FALSE otherwise.
   */
  public function isRegistered(string $uuid): bool;

  /**
   * Get the module that owns an experiment.
   *
   * @param string $uuid
   *   The experiment UUID.
   *
   * @return string|null
   *   The module name if registered, NULL otherwise.
   */
  public function getOwner(string $uuid): ?string;

  /**
   * Get all registered experiments.
   *
   * @return array
   *   Array keyed by UUID with module names as values.
   */
  public function getAll(): array;

}
