<?php

namespace Drupal\rl\Decorator;

/**
 * Interface for experiment decoration service.
 */
interface ExperimentDecoratorInterface {

  /**
   * Decorate an experiment UUID with user-friendly information.
   *
   * @param string $uuid
   *   The experiment UUID.
   *
   * @return array|null
   *   A render array with decorated information, or NULL if no decoration.
   */
  public function decorateExperiment(string $uuid): ?array;

  /**
   * Decorate an arm ID with user-friendly information.
   *
   * @param string $experiment_uuid
   *   The experiment UUID.
   * @param string $arm_id
   *   The arm ID.
   *
   * @return array|null
   *   A render array with decorated information, or NULL if no decoration.
   */
  public function decorateArm(string $experiment_uuid, string $arm_id): ?array;

}
