<?php

namespace Drupal\rl\Service;

/**
 * Thompson Sampling.
 *
 * Idea in metaphor.
 * -----------------
 * – Each “arm” is a different coffee blend.  
 * – After every cup we store two counts per blend:
 *       • turns   = how many cups were served
 *       • rewards = how many of those cups were rated “good”
 * – Before the next customer is served we draw one *taste score* for
 *   every blend.  The draw is **randomized but not uniform**:
 *       blends with more good ratings are *more likely* to draw
 *       higher scores, blends with few ratings still get a chance.
 * – We serve the blend with the highest drawn score.  Repeating this
 *   process balances *exploration* (trying all blends) and *exploitation*
 *   (serving the best-known blend more often).
 *
 * Idea in math terms
 * ------------------
 * • For a blend with   successes = a   and   failures = b
 *   we treat the “unknown true like-rate” as following a Beta(a+1, b+1)
 *   curve.  (That “+1” is just a neutral starting point.)
 * • A fast trick to draw a Beta value is:
 *       X ← Gamma(a+1,1)   Y ← Gamma(b+1,1)
 *       return  X / (X + Y).
 * 
 *  @see https://arxiv.org/abs/1707.02038
 *  @see https://dl.acm.org/doi/pdf/10.1145/358407.358414
 */
class ThompsonCalculator {

  /*──────────────────────── PUBLIC API ────────────────────────*/

  /**
   * Draw one Thompson score for every blend.
   *
   * @param array $arms_data  objects with ->turns and ->rewards
   * @return array            scores keyed by blend id
   */
  public function calculateThompsonScores(array $arms_data): array {
    $scores = [];

    foreach ($arms_data as $id => $arm) {
      $alpha = $arm->rewards + 1;                 // good ratings + 1
      $beta  = ($arm->turns - $arm->rewards) + 1; // bad  ratings + 1
      $scores[$id] = $this->randBeta($alpha, $beta);
    }
    return $scores;
  }

  /** Pick the blend with the highest score. */
  public function selectBestArm(array $scores) {
    return $scores ? array_keys($scores, max($scores))[0] : NULL;
  }

  /*───────────────────── PRIVATE HELPERS ─────────────────────*/

  /** Draw Beta(α,β) by dividing two Gamma draws. */
  private function randBeta(int $alpha, int $beta): float {
    $x = $this->randGamma($alpha);
    $y = $this->randGamma($beta);
    return $x / ($x + $y);
  }

  /**
   * Marsaglia–Tsang Γ(k,1) sampler.
   *
   * Key ideas (k ≥ 1)
   * -----------------
   * 1.  Rewrite k as  k = d + 1/3  with  d = k − 1/3.
   *     This centres the target density so the proposal works well.
   * 2.  Draw Z ~ N(0,1) (a bell-curve number) and set
   *         V = (1 + c·Z)³      where  c = 1 / √(9d)
   *     → V is positive most of the time.  Multiplying by d later scales
   *       it into the right range for a Gamma random number.
   * 3.  Use *rejection sampling*: accept V with probability equal to the
   *    ratio “target Gamma density / proposal density”.
   *    a) FAST test  u < 1 − 0.0331 Z⁴
   *       • Cheap polynomial bound that sits fully inside the true
   *         acceptance region.  If it passes we can safely accept.
   *       • Accepts ~87 % of good candidates, saving calls to log().
   *    b) If the fast test fails, run the EXACT test
   *         ln u < ½ Z² + d (1 − V + ln V)
   *       • This is the exact log-likelihood ratio.  It guarantees that
   *         the remaining ~13 % of cases are accepted or rejected
   *         correctly, so the output distribution is truly Γ(k,1).
   *
   * k < 1
   * -----
   *  • Trick: sample Γ(k+1,1) (which has shape ≥ 1) and then divide by
   *    U^{1/k}.  The scaling U^{1/k} converts the +1 shape back down.
   */
  private function randGamma(float $k): float {
    /* ----- Case 0 < k < 1  ----------------------------------------- */
    if ($k < 1.0) {
      // Draw Γ(k+1) and shrink it.  The exponent 1/k acts like “take the
      // k-th root” of a uniform number, redistributing mass toward zero.
      return $this->randGamma($k + 1.0) * pow($this->u(), 1.0 / $k);
    }

    /* ----- Case k ≥ 1  --------------------------------------------- */
    $d = $k - 1.0 / 3.0;          // shifts the shape
    $c = 1.0 / sqrt(9.0 * $d);    // scales the normal draw

    while (true) {
      /* Step 1: Z ~ N(0,1) */
      $z = $this->z();

      /* Step 2: candidate V;  (1 + cZ) might be negative if Z < −1/c  */
      $v = pow(1.0 + $c * $z, 3);

      if ($v <= 0.0) {            // invalid candidate → try again
        continue;
      }

      $u = $this->u();            // U ~ Uniform(0,1) for accept/reject

      /* Step 3a: FAST acceptance (“squeeze” region)                   *
       * Inequality derived in the paper ensures we are safely inside  *
       * the true acceptance region, so accepting here is always ok.   */
      if ($u < 1.0 - 0.0331 * $z ** 4) {
        return $d * $v;
      }

      /* Step 3b: EXACT acceptance (rare)                              *
       *  log U  vs.  log(target/proposal)                             *
       * If the inequality holds we accept; otherwise loop again.      */
      if (log($u) < 0.5 * $z * $z + $d * (1.0 - $v + log($v))) {
        return $d * $v;
      }
      /* If we reach here both tests failed; draw a new Z and restart. */
    }
  }

  /*──────────── RNG helpers (uniform & normal) ──────────────*/

  /** Uniform(0,1) using PHP’s cryptographic RNG. */
  private function u(): float {
    return random_int(1, PHP_INT_MAX - 1) / PHP_INT_MAX;
  }

  /**
   * Standard normal N(0,1) via Box-Muller transform.
   * Generates two normals per two uniforms; caches one for speed.
   */
  private function z(): float {
    static $cache = NULL;

    if ($cache !== NULL) {
      $z = $cache;
      $cache = NULL;
      return $z;
    }

    do {
      $u1 = 2.0 * $this->u() - 1.0;
      $u2 = 2.0 * $this->u() - 1.0;
      $s  = $u1 * $u1 + $u2 * $u2;
    } while ($s >= 1.0 || $s == 0.0);

    $factor = sqrt(-2.0 * log($s) / $s);
    $cache  = $u1 * $factor;   // save one normal for next call
    return  $u2 * $factor;     // return the other
  }

}
