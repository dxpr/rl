<?php

namespace Drupal\rl\Decorator;

/**
 * Manager service for experiment decorators.
 *
 * This service collects and manages multiple decorator services,
 * allowing modules to provide their own decorators.
 */
class ExperimentDecoratorManager {
  /**
   * Array of decorator services.
   *
   * @var \Drupal\rl\Decorator\ExperimentDecoratorInterface[]
   */
  protected $decorators = [];

  /**
   * Add a decorator service.
   *
   * @param \Drupal\rl\Decorator\ExperimentDecoratorInterface $decorator
   *   The decorator service to add.
   */
  public function addDecorator(ExperimentDecoratorInterface $decorator): void {
    $this->decorators[] = $decorator;
  }

  /**
   * Decorate an experiment ID with user-friendly information.
   *
   * @param string $experiment_id
   *   The experiment ID.
   *
   * @return array|null
   *   A render array with decorated information, or NULL if no decoration.
   */
  public function decorateExperiment(string $experiment_id): ?array {
    foreach ($this->decorators as $decorator) {
      $result = $decorator->decorateExperiment($experiment_id);
      if ($result !== NULL) {
        return $result;
      }
    }
    return NULL;
  }

  /**
   * Decorate an arm ID with user-friendly information.
   *
   * @param string $experiment_id
   *   The experiment ID.
   * @param string $arm_id
   *   The arm ID.
   *
   * @return array|null
   *   A render array with decorated information, or NULL if no decoration.
   */
  public function decorateArm(string $experiment_id, string $arm_id): ?array {
    foreach ($this->decorators as $decorator) {
      $result = $decorator->decorateArm($experiment_id, $arm_id);
      if ($result !== NULL) {
        return $result;
      }
    }
    return NULL;
  }

}
