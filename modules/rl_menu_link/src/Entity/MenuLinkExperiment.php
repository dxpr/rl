<?php

namespace Drupal\rl_menu_link\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the Menu Link Experiment config entity.
 *
 * @ConfigEntityType(
 *   id = "rl_menu_link_experiment",
 *   label = @Translation("Menu link experiment"),
 *   label_collection = @Translation("Menu link experiments"),
 *   label_singular = @Translation("menu link experiment"),
 *   label_plural = @Translation("menu link experiments"),
 *   admin_permission = "administer rl menu link experiments",
 *   handlers = {
 *     "list_builder" = "Drupal\rl_menu_link\MenuLinkExperimentListBuilder",
 *     "form" = {
 *       "default" = "Drupal\rl_menu_link\Form\MenuLinkExperimentForm",
 *       "add" = "Drupal\rl_menu_link\Form\MenuLinkExperimentForm",
 *       "edit" = "Drupal\rl_menu_link\Form\MenuLinkExperimentForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   config_prefix = "rl_menu_link_experiment",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "status" = "enabled",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "menu_link_plugin_id",
 *     "variants",
 *     "enabled",
 *   },
 *   links = {
 *     "collection" = "/admin/config/services/rl-menu-link",
 *     "add-form" = "/admin/config/services/rl-menu-link/add",
 *     "edit-form" = "/admin/config/services/rl-menu-link/{rl_menu_link_experiment}/edit",
 *     "delete-form" = "/admin/config/services/rl-menu-link/{rl_menu_link_experiment}/delete",
 *   },
 * )
 */
class MenuLinkExperiment extends ConfigEntityBase {

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
   * The menu link plugin ID being tested.
   *
   * @var string
   */
  protected $menu_link_plugin_id = '';

  /**
   * Variant labels.
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
   * Get the menu link plugin ID.
   */
  public function getMenuLinkPluginId(): string {
    return $this->menu_link_plugin_id ?? '';
  }

  /**
   * Set the menu link plugin ID.
   */
  public function setMenuLinkPluginId(string $plugin_id): static {
    $this->menu_link_plugin_id = $plugin_id;
    return $this;
  }

  /**
   * Get variant labels.
   *
   * @return string[]
   */
  public function getVariants(): array {
    return array_values($this->variants ?? []);
  }

  /**
   * Set variant labels.
   *
   * @param string[] $variants
   */
  public function setVariants(array $variants): static {
    $this->variants = array_values($variants);
    return $this;
  }

  /**
   * Get the deterministic RL experiment ID.
   */
  public function getRlExperimentId(): string {
    return self::buildRlExperimentId($this->getMenuLinkPluginId());
  }

  /**
   * Build a deterministic RL experiment ID from a plugin ID.
   */
  public static function buildRlExperimentId(string $plugin_id): string {
    return 'rl_menu_link-' . substr(sha1($plugin_id), 0, 12);
  }

  /**
   * Build arm IDs (v0 = original, v1..vN = stored variants).
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
   * Get the text for an arm ID.
   *
   * @return string|null
   *   The variant text or NULL for v0 (original).
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
