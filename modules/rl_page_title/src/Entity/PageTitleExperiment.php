<?php

namespace Drupal\rl_page_title\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the Page Title Experiment config entity.
 *
 * @ConfigEntityType(
 *   id = "rl_page_title_experiment",
 *   label = @Translation("Page title experiment"),
 *   label_collection = @Translation("Page title experiments"),
 *   label_singular = @Translation("page title experiment"),
 *   label_plural = @Translation("page title experiments"),
 *   admin_permission = "administer rl page title experiments",
 *   handlers = {
 *     "list_builder" = "Drupal\rl_page_title\PageTitleExperimentListBuilder",
 *     "form" = {
 *       "default" = "Drupal\rl_page_title\Form\PageTitleExperimentForm",
 *       "add" = "Drupal\rl_page_title\Form\PageTitleExperimentForm",
 *       "edit" = "Drupal\rl_page_title\Form\PageTitleExperimentForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   config_prefix = "rl_page_title_experiment",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "status" = "enabled",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "path",
 *     "variants",
 *     "enabled",
 *   },
 *   links = {
 *     "collection" = "/admin/config/services/rl-page-title",
 *     "add-form" = "/admin/config/services/rl-page-title/add",
 *     "edit-form" = "/admin/config/services/rl-page-title/{rl_page_title_experiment}/edit",
 *     "delete-form" = "/admin/config/services/rl-page-title/{rl_page_title_experiment}/delete",
 *   },
 * )
 */
class PageTitleExperiment extends ConfigEntityBase {

  /**
   * The experiment ID (machine name).
   *
   * @var string
   */
  protected $id;

  /**
   * The experiment label.
   *
   * @var string
   */
  protected $label;

  /**
   * The internal path being tested.
   *
   * @var string
   */
  protected $path = '';

  /**
   * Variant titles (alternatives to the original).
   *
   * @var string[]
   */
  protected $variants = [];

  /**
   * Whether the experiment is enabled.
   *
   * @var bool
   */
  protected $enabled = TRUE;

  /**
   * Get the internal path.
   */
  public function getPath(): string {
    return $this->path ?? '';
  }

  /**
   * Set the internal path.
   */
  public function setPath(string $path): static {
    $this->path = $path;
    return $this;
  }

  /**
   * Get the variant titles.
   *
   * @return string[]
   */
  public function getVariants(): array {
    return array_values($this->variants ?? []);
  }

  /**
   * Set the variant titles.
   *
   * @param string[] $variants
   */
  public function setVariants(array $variants): static {
    $this->variants = array_values($variants);
    return $this;
  }

  /**
   * Get the deterministic RL experiment ID for this experiment.
   */
  public function getRlExperimentId(): string {
    return self::buildRlExperimentId($this->getPath());
  }

  /**
   * Build a deterministic RL experiment ID from a path.
   *
   * @param string $path
   *   The internal path.
   *
   * @return string
   *   The RL experiment ID, in format: rl_page_title-{12-char-sha1}.
   */
  public static function buildRlExperimentId(string $path): string {
    return 'rl_page_title-' . substr(sha1($path), 0, 12);
  }

  /**
   * Build arm IDs for this experiment.
   *
   * @return string[]
   *   Arm IDs: v0 (original) plus v1..vN for each stored variant.
   */
  public function getArmIds(): array {
    $arm_ids = ['v0'];
    foreach ($this->getVariants() as $i => $_unused) {
      $arm_ids[] = 'v' . ($i + 1);
    }
    return $arm_ids;
  }

  /**
   * Get the text for a given arm.
   *
   * @return string|null
   *   The variant text, or NULL for v0 (original, not stored).
   */
  public function getArmText(string $arm_id): ?string {
    if ($arm_id === 'v0') {
      return NULL;
    }
    $index = (int) substr($arm_id, 1) - 1;
    $variants = $this->getVariants();
    return $variants[$index] ?? NULL;
  }

}
