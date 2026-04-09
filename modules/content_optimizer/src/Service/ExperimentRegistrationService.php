<?php

namespace Drupal\content_optimizer\Service;

use Drupal\rl\Registry\ExperimentRegistryInterface;

/**
 * Registers content optimizer experiments with the RL experiment registry.
 */
class ExperimentRegistrationService {

  /**
   * The RL experiment registry.
   *
   * @var \Drupal\rl\Registry\ExperimentRegistryInterface
   */
  protected ExperimentRegistryInterface $experimentRegistry;

  /**
   * Constructs an ExperimentRegistrationService.
   *
   * @param \Drupal\rl\Registry\ExperimentRegistryInterface $experiment_registry
   *   The RL experiment registry.
   */
  public function __construct(ExperimentRegistryInterface $experiment_registry) {
    $this->experimentRegistry = $experiment_registry;
  }

  /**
   * Register an experiment with the RL system.
   *
   * @param string $experiment_id
   *   The deterministic experiment ID.
   * @param string|null $experiment_name
   *   Human-readable name.
   */
  public function register(string $experiment_id, ?string $experiment_name = NULL): void {
    $this->experimentRegistry->register($experiment_id, 'content_optimizer', $experiment_name);
  }

}
