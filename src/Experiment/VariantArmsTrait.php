<?php

namespace Drupal\rl\Experiment;

/**
 * Reusable arm-id helpers for variant-style RL experiments.
 *
 * Consumer modules whose experiments follow the convention "v0 = original
 * (read live, not stored), v1..vN = stored variants" can apply this trait to
 * their experiment classes to avoid duplicating the arm bookkeeping.
 *
 * Implementing classes must provide a getVariants(): array method.
 */
trait VariantArmsTrait {

  /**
   * Returns the list of stored variant texts.
   *
   * @return string[]
   */
  abstract public function getVariants(): array;

  /**
   * Build arm IDs: v0 (original) plus v1..vN for each stored variant.
   *
   * @return string[]
   */
  public function getArmIds(): array {
    $arm_ids = ['v0'];
    foreach ($this->getVariants() as $i => $_unused) {
      $arm_ids[] = 'v' . ($i + 1);
    }
    return $arm_ids;
  }

  /**
   * Get the text for a specific arm ID.
   *
   * @param string $arm_id
   *   Arm ID like "v0", "v1", "v2".
   *
   * @return string|null
   *   The variant text, or NULL if the arm is v0 (original) or unknown.
   */
  public function getArmText(string $arm_id): ?string {
    if ($arm_id === 'v0' || !preg_match('/^v(\d+)$/', $arm_id, $m)) {
      return NULL;
    }
    $index = (int) $m[1] - 1;
    if ($index < 0) {
      return NULL;
    }
    $variants = $this->getVariants();
    return $variants[$index] ?? NULL;
  }

  /**
   * Build a deterministic, collision-resistant RL experiment ID.
   *
   * @param string $prefix
   *   Module-specific prefix, e.g. "rl_page_title".
   * @param string $target
   *   The target identifier (path, plugin id, etc).
   *
   * @return string
   *   The RL experiment ID, in format: {prefix}-{12-char-sha1}.
   */
  public static function buildVariantExperimentId(string $prefix, string $target): string {
    return $prefix . '-' . substr(sha1($target), 0, 12);
  }

}
