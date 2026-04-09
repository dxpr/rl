<?php

namespace Drupal\rl\Experiment;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Contract for variant-style RL experiment config entities.
 *
 * Modules that ship config entities representing variant experiments
 * (rl_page_title, rl_menu_link, future rl_cta etc.) implement this so the
 * shared base classes (VariantSelectorBase, VariantExperimentListBuilderBase,
 * VariantExperimentDecoratorBase, VariantExperimentDeleteFormBase) can narrow
 * the type without each base class taking a class-string template.
 *
 * The convention this interface enforces:
 *
 *   - Arm v0 = original (read live from whatever renders the target).
 *   - Arms v1..vN = stored variant texts, indexed sequentially.
 *
 * Implementations should use VariantArmsTrait, which provides default
 * implementations of getArmIds() and getArmText() for this convention.
 */
interface VariantExperimentInterface extends ConfigEntityInterface {

  /**
   * The deterministic RL experiment ID for this entity.
   *
   * Used as the key into the RL parent module's experiment_registry,
   * arm_data, totals, and snapshots tables.
   *
   * @return string
   *   The RL experiment ID, formatted as `{module_prefix}-{12-char-sha1}`.
   */
  public function getRlExperimentId(): string;

  /**
   * The list of stored variant texts.
   *
   * @return string[]
   *   The variant text strings, indexed sequentially from 0.
   */
  public function getVariants(): array;

  /**
   * The arm IDs in canonical order.
   *
   * @return string[]
   *   `[v0, v1, ..., vN]` where v0 is the original (un-stored) text.
   */
  public function getArmIds(): array;

  /**
   * The text for a specific arm ID.
   *
   * @param string $arm_id
   *   Arm ID like `v0`, `v1`, `v2`.
   *
   * @return string|null
   *   The variant text, or NULL when the arm is v0 (original) or unknown.
   */
  public function getArmText(string $arm_id): ?string;

}
