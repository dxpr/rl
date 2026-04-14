<?php

namespace Drupal\rl\Experiment;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Contract for variant-style RL experiment content entities.
 *
 * Modules that ship content entities representing variant experiments
 * (rl_page_title, rl_menu_link, future rl_cta, etc.) implement this so the
 * shared base classes (VariantSelectorBase, VariantExperimentDecoratorBase,
 * VariantExperimentDeleteFormBase) can narrow the type without each base
 * class taking a class-string template.
 *
 * Multilingual model: each implementing entity has a langcode entity key.
 * Each (target, langcode) pair is a separate row, mirroring the Redirect
 * module's per-language redirects. Implementations should NOT use Drupal's
 * translation framework; they should store one row per language.
 * `LanguageInterface::LANGCODE_NOT_SPECIFIED` is the "all languages"
 * fallback target, looked up only when no language-specific row matches.
 *
 * The arm convention this interface enforces:
 *   - Arm v0 = original (read live from whatever renders the target).
 *   - Arms v1..vN = stored variant texts, indexed sequentially.
 *
 * Implementations should use VariantArmsTrait, which provides default
 * implementations of getArmIds() and getArmText() for this convention.
 */
interface VariantExperimentInterface extends ContentEntityInterface {

  /**
   * The deterministic RL experiment ID for this entity.
   *
   * Used as the key into the RL parent module's experiment_registry,
   * arm_data, totals, and snapshots tables. The hash includes the
   * langcode so analytics are scoped per-language: an English experiment
   * for /blog and a Spanish experiment for /blog get separate Thompson
   * Sampling state.
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
