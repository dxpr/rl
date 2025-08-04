<?php

namespace Drupal\rl\Service;

/**
 * Thompson Sampling algorithm implementation for multi-armed bandit experiments.
 *
 * Implements the Thompson Sampling algorithm using a Beta-Bernoulli model.
 * The Beta distribution is generated using the Marsaglia-Tsang algorithm
 * for Gamma distribution sampling, requiring no external dependencies.
 */
class ThompsonCalculator {

  /**
   * Calculates Thompson Sampling scores for all arms in an experiment.
   *
   * @param array $arms_data
   *   Array of arm data objects, each containing 'turns' and 'rewards' properties.
   *
   * @return array
   *   Array of sampled scores keyed by arm_id.
   */
  public function calculateThompsonScores(array $arms_data) {
    return $this->calculateScores($arms_data);
  }

  /**
   * Generates Thompson Sampling scores by sampling from Beta distributions.
   *
   * For each arm, samples from Beta(α, β) where:
   * - α = successes + 1 (prior successes)
   * - β = failures + 1 (prior failures)
   *
   * @param array $arms_data
   *   Array of arm data objects with 'turns' and 'rewards' properties.
   *
   * @return array
   *   Array of sampled scores keyed by arm_id.
   */
  public function calculateScores(array $arms_data): array {
    $scores = [];

    foreach ($arms_data as $arm_id => $arm) {
      // Beta distribution parameters: α = successes + 1, β = failures + 1
      $alpha = $arm->rewards + 1;
      $beta = ($arm->turns - $arm->rewards) + 1;

      $scores[$arm_id] = $this->randBeta($alpha, $beta);
    }

    return $scores;
  }

  /**
   * Calculates a single Thompson Sampling score for one arm.
   *
   * @param int $arm_turns
   *   Total number of trials for this arm.
   * @param int $arm_rewards
   *   Total number of successes for this arm.
   *
   * @return float
   *   Sampled score from Beta distribution.
   */
  public function calculateThompsonScore($arm_turns, $arm_rewards) {
    $alpha = $arm_rewards + 1;
    $beta = ($arm_turns - $arm_rewards) + 1;
    return $this->randBeta($alpha, $beta);
  }

  /**
   * Selects the arm with the highest Thompson Sampling score.
   *
   * @param array $scores
   *   Array of scores keyed by arm_id.
   *
   * @return string|null
   *   The arm_id with the highest score, or NULL if no scores provided.
   */
  public function selectBestArm(array $scores) {
    return $scores ? array_keys($scores, max($scores))[0] : NULL;
  }

  /**
   * Samples from a Beta distribution using two Gamma distributions.
   *
   * Uses the property that if X ~ Gamma(α) and Y ~ Gamma(β), 
   * then X/(X+Y) ~ Beta(α,β).
   *
   * @param int $alpha
   *   Alpha parameter of the Beta distribution.
   * @param int $beta
   *   Beta parameter of the Beta distribution.
   *
   * @return float
   *   Random sample from Beta(α,β) distribution.
   */
  private function randBeta(int $alpha, int $beta): float {
    $x = $this->randGamma($alpha);
    $y = $this->randGamma($beta);
    return $x / ($x + $y);
  }

  /**
   * Samples from a Gamma distribution using the Marsaglia-Tsang algorithm.
   *
   * Efficient method for generating Gamma-distributed random numbers
   * without external dependencies.
   *
   * @param float $shape
   *   Shape parameter (k) of the Gamma distribution.
   *
   * @return float
   *   Random sample from Gamma(k,1) distribution.
   */
  private function randGamma(float $shape): float {
    // Handle shape < 1 by boosting and scaling
    if ($shape < 1.0) {
      return $this->randGamma($shape + 1.0) * pow($this->u(), 1.0 / $shape);
    }

    $d = $shape - 1.0 / 3.0;
    $c = 1.0 / sqrt(9.0 * $d);

    while (true) {
      // Generate standard normal random variable
      $z = $this->z();
      $v = pow(1.0 + $c * $z, 3);

      // Reject negative values
      if ($v <= 0.0) {
        continue;
      }

      $u = $this->u();

      // Fast acceptance test
      if ($u < 1.0 - 0.0331 * $z ** 4) {
        return $d * $v;
      }

      // Slow acceptance test
      if (log($u) < 0.5 * $z * $z + $d * (1.0 - $v + log($v))) {
        return $d * $v;
      }
    }
  }

  /**
   * Generates a cryptographically secure uniform random number in (0,1).
   *
   * @return float
   *   Random number between 0 and 1 (exclusive).
   */
  private function u(): float {
    return random_int(1, PHP_INT_MAX - 1) / PHP_INT_MAX;
  }

  /**
   * Generates a standard normal random variable using Box-Muller transform.
   *
   * Uses static caching to generate two normal variables per call to the
   * uniform random number generator, improving efficiency.
   *
   * @return float
   *   Random sample from standard normal distribution N(0,1).
   */
  private function z(): float {
    static $cache = NULL;

    // Return cached value if available
    if ($cache !== NULL) {
      $z = $cache;
      $cache = NULL;
      return $z;
    }

    // Generate two uniform random numbers in unit circle
    do {
      $u1 = 2.0 * $this->u() - 1.0;
      $u2 = 2.0 * $this->u() - 1.0;
      $s = $u1 * $u1 + $u2 * $u2;
    } while ($s >= 1.0 || $s == 0.0);

    // Apply Box-Muller transformation
    $factor = sqrt(-2.0 * log($s) / $s);
    $cache = $u1 * $factor;  // Cache one value
    return $u2 * $factor;    // Return the other
  }

}