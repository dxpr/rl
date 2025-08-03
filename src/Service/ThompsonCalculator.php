<?php

namespace Drupal\rl\Service;

/**
 * Thompson-sampling calculator for multi-armed bandits.
 *
 * Replaces the old UCB-1 logic.  No Composer packages or PECL
 * extensions are required – the Beta draw is implemented in ±25 lines
 * of pure PHP using the Marsaglia–Tsang Gamma algorithm.
 */
class ThompsonCalculator {

  /* ------------------------------------------------------------------
   * Public helpers (same signatures as before, so the calling code
   * doesn't change).
   * -----------------------------------------------------------------*/

  /**
   * Draws one Thompson-sampling score for each arm.
   *
   * @param array $arms_data
   *   Objects with ->turns  (trials)  and ->rewards (successes).
   * @param int $total_turns
   *   Total number of turns across all arms (unused for Thompson Sampling).
   * @param float $alpha
   *   Exploration parameter (unused for Thompson Sampling).
   *
   * @return array
   *   Random scores keyed by arm_id.
   */
  public function calculateUCB1Scores(array $arms_data, $total_turns, $alpha = 2.0) {
    $logger = \Drupal::logger('rl');
    $logger->debug('ThompsonCalculator::calculateUCB1Scores called with @count arms, total_turns: @total', [
      '@count' => count($arms_data),
      '@total' => $total_turns,
    ]);
    
    try {
      $result = $this->calculateScores($arms_data);
      $logger->debug('ThompsonCalculator::calculateScores returned @count scores', ['@count' => count($result)]);
      return $result;
    } catch (\Exception $e) {
      $logger->error('Error in ThompsonCalculator::calculateUCB1Scores: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Draws one Thompson-sampling score for each arm.
   *
   * @param array $arms_data
   *   Objects with ->turns  (trials)  and ->rewards (successes).
   * @return array
   *   Random scores keyed by arm_id.
   */
  public function calculateScores(array $arms_data): array {
    $logger = \Drupal::logger('rl');
    $logger->debug('ThompsonCalculator::calculateScores called with @count arms', ['@count' => count($arms_data)]);
    
    $scores = [];

    foreach ($arms_data as $arm_id => $arm) {
      $logger->debug('Processing arm @arm_id: turns=@turns, rewards=@rewards', [
        '@arm_id' => $arm_id,
        '@turns' => $arm->turns ?? 'NULL',
        '@rewards' => $arm->rewards ?? 'NULL',
      ]);
      
      //  α = successes + 1,  β = failures + 1
      $alpha = $arm->rewards + 1;
      $beta  = ($arm->turns - $arm->rewards) + 1;

      $score = $this->randBeta($alpha, $beta);
      $scores[$arm_id] = $score;
      
      $logger->debug('Generated score @score for arm @arm_id (alpha=@alpha, beta=@beta)', [
        '@score' => $score,
        '@arm_id' => $arm_id,
        '@alpha' => $alpha,
        '@beta' => $beta,
      ]);
    }

    $logger->debug('ThompsonCalculator::calculateScores returning @count scores', ['@count' => count($scores)]);
    return $scores;
  }

  /**
   * Calculate Thompson Sampling score for a single arm.
   *
   * @param int $arm_turns
   *   Number of times this arm has been trialed.
   * @param int $arm_rewards
   *   Number of rewards received for this arm.
   * @param int $total_turns
   *   Total number of turns across all arms (unused for Thompson Sampling).
   * @param float $alpha
   *   Exploration parameter (unused for Thompson Sampling).
   *
   * @return float
   *   The Thompson Sampling score for this arm.
   */
  public function calculateUCB1Score($arm_turns, $arm_rewards, $total_turns, $alpha = 2.0) {
    $alpha_param = $arm_rewards + 1;
    $beta_param = ($arm_turns - $arm_rewards) + 1;
    return $this->randBeta($alpha_param, $beta_param);
  }

  /**
   * Select the arm with the highest Thompson draw.
   *
   * @param array $scores
   * @return string|null
   */
  public function selectBestArm(array $scores) {
    return $scores ? array_keys($scores, max($scores))[0] : NULL;
  }

  /* ------------------------------------------------------------------
   *                     Pure-PHP Beta (α,β) sampler
   * -----------------------------------------------------------------*/

  /**
   * Draw a Beta-distributed random number via two Gamma draws.
   */
  private function randBeta(int $alpha, int $beta): float {
    $x = $this->randGamma($alpha);
    $y = $this->randGamma($beta);
    return $x / ($x + $y);                 //  X/(X+Y)  ~  Beta(α,β)
  }

  /**
   * Marsaglia–Tsang: draw Γ(k,1) for any k > 0.
   * Runs in <1 µs on PHP 8; no extensions needed.
   */
  private function randGamma(float $k): float {
    if ($k < 1.0) {                        // boost to k≥1 for stability
      return $this->randGamma($k + 1.0) * pow($this->u(), 1.0 / $k);
    }

    $d = $k - 1.0 / 3.0;
    $c = 1.0 / sqrt(9.0 * $d);

    while (true) {
      // Step 1: standard normal Z  (Box–Muller shortcut)
      $z = $this->z();
      $v = pow(1.0 + $c * $z, 3);

      if ($v <= 0.0) {
        continue;                          // reject, keep looping
      }

      $u = $this->u();                     // uniform(0,1)

      // cheap accept-test
      if ($u < 1.0 - 0.0331 * $z ** 4) {
        return $d * $v;
      }

      // exact accept-test
      if (log($u) < 0.5 * $z * $z + $d * (1.0 - $v + log($v))) {
        return $d * $v;
      }
    }
  }

  /* ------------------------------------------------------------------
   *                 Tiny helper RNGs (no external libs)
   * -----------------------------------------------------------------*/

  /** cryptographically-safe uniform (0,1) */
  private function u(): float {
    return random_int(1, PHP_INT_MAX - 1) / PHP_INT_MAX;
  }

  /** standard normal via Box–Muller (two uniforms → one Gaussian) */
  private function z(): float {
    static $cache = NULL;

    if ($cache !== NULL) {
      $z = $cache;
      $cache = NULL;
      return $z;
    }

    // generate two uniforms in the unit circle
    do {
      $u1 = 2.0 * $this->u() - 1.0;
      $u2 = 2.0 * $this->u() - 1.0;
      $s  = $u1 * $u1 + $u2 * $u2;
    } while ($s >= 1.0 || $s == 0.0);

    $factor = sqrt(-2.0 * log($s) / $s);
    $cache  = $u1 * $factor;
    return  $u2 * $factor;                 // return one Z, cache the other
  }

}