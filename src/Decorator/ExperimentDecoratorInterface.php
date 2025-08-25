<?php

namespace Drupal\rl\Decorator;

/**
 * Interface for experiment decoration service.
 */
interface ExperimentDecoratorInterface {

  /**
   * Decorate an experiment ID with user-friendly information.
   *
   * @param string $experiment_id
   *   The experiment ID.
   *
   * @return array|null
   *   A render array with decorated information, or NULL if no decoration.
   */
  public function decorateExperiment(string $experiment_id): ?array;

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
  public function decorateArm(string $experiment_id, string $arm_id): ?array;

}
