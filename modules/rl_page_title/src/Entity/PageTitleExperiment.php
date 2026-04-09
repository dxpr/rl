<?php

namespace Drupal\rl_page_title\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\rl\Experiment\VariantArmsTrait;

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
 *       "delete" = "Drupal\rl_page_title\Form\PageTitleExperimentDeleteForm",
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

  use VariantArmsTrait;

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
   * Set the internal path. Normalizes to leading slash, no trailing slash.
   */
  public function setPath(string $path): static {
    $this->path = self::normalizePath($path);
    return $this;
  }

  /**
   * Normalize a path to the canonical form used for storage and matching.
   *
   * Ensures a leading slash and removes any trailing slash so that runtime
   * lookup matches save-time storage exactly.
   */
  public static function normalizePath(string $path): string {
    $path = trim($path);
    if ($path === '' || $path === '/') {
      return '/';
    }
    if ($path[0] !== '/') {
      $path = '/' . $path;
    }
    return rtrim($path, '/');
  }

  /**
   * {@inheritdoc}
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
   *   The internal path. Will be normalized first so callers can pass either
   *   canonical or non-canonical input and get the same result.
   *
   * @return string
   *   The RL experiment ID, in format: rl_page_title-{12-char-sha1}.
   */
  public static function buildRlExperimentId(string $path): string {
    return self::buildVariantExperimentId('rl_page_title', self::normalizePath($path));
  }

}
